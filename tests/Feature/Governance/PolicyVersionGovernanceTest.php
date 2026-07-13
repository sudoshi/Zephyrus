<?php

namespace Tests\Feature\Governance;

use App\Models\Eddy\EddyProviderProfile;
use App\Models\Eddy\EddySurfacePolicy;
use App\Models\Governance\AiProviderPolicyVersion;
use App\Models\Governance\CockpitThresholdPolicyVersion;
use App\Models\Ops\MetricDefinition;
use App\Models\User;
use App\Services\Auth\StepUpAuthenticationService;
use App\Services\Governance\AiProviderPolicyService;
use App\Services\Governance\CockpitThresholdPolicyService;
use App\Services\Governance\GovernanceViolation;
use App\Services\Governance\GovernedChangeService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PolicyVersionGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private CockpitThresholdPolicyService $thresholds;

    private AiProviderPolicyService $aiPolicy;

    private GovernedChangeService $governance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->thresholds = app(CockpitThresholdPolicyService::class);
        $this->aiPolicy = app(AiProviderPolicyService::class);
        $this->governance = app(GovernedChangeService::class);
    }

    public function test_policy_version_ledgers_reject_update_and_delete_at_the_database(): void
    {
        $definition = $this->metricDefinition('gov.test_metric');
        $thresholdVersion = CockpitThresholdPolicyVersion::query()->create([
            'metric_definition_id' => $definition->metric_definition_id,
            'metric_key' => $definition->metric_key,
            'version_number' => 1,
            'policy' => ['metric_key' => $definition->metric_key, 'owner' => 'CMO'],
            'policy_sha256' => str_repeat('a', 64),
            'change_kind' => 'initial',
            'change_reason' => 'Initial governed threshold policy capture.',
            'effective_at' => now(),
            'created_at' => now(),
        ]);
        $aiVersion = AiProviderPolicyVersion::query()->create([
            'policy_key' => 'eddy',
            'version_number' => 1,
            'policy' => ['profiles' => [], 'surfaces' => []],
            'policy_sha256' => str_repeat('b', 64),
            'change_kind' => 'initial',
            'change_reason' => 'Initial governed AI provider policy capture.',
            'effective_at' => now(),
            'created_at' => now(),
        ]);

        foreach ([
            fn () => CockpitThresholdPolicyVersion::query()->whereKey($thresholdVersion->getKey())->update(['change_reason' => 'tampered reason field']),
            fn () => CockpitThresholdPolicyVersion::query()->whereKey($thresholdVersion->getKey())->delete(),
            fn () => AiProviderPolicyVersion::query()->whereKey($aiVersion->getKey())->update(['change_reason' => 'tampered reason field']),
            fn () => AiProviderPolicyVersion::query()->whereKey($aiVersion->getKey())->delete(),
        ] as $mutation) {
            DB::beginTransaction();
            try {
                $mutation();
                $this->fail('Policy version ledgers must be append-only.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_threshold_policy_application_requires_an_independent_approver(): void
    {
        $author = User::factory()->create(['role' => 'admin']);
        $this->metricDefinition('gov.author_approver');

        $result = $this->thresholds->requestChange(
            $this->steppedUpRequest($author),
            'gov.author_approver',
            ['owner' => 'CNO', 'warn_edge' => 25, 'crit_edge' => 40],
            'Retune the boarding band after the winter surge review.',
        );

        try {
            $this->governance->decide(
                $this->steppedUpRequest($author),
                $result['change']->getKey(),
                true,
                'Attempting to approve my own threshold change.',
            );
            $this->fail('The author must not approve their own threshold policy change.');
        } catch (GovernanceViolation $exception) {
            $this->assertSame('author_approver_conflict', $exception->reason);
        }

        try {
            $this->thresholds->applyApproved($this->steppedUpRequest($author), $result['change']->getKey());
            $this->fail('An undecided threshold policy change must not apply.');
        } catch (GovernanceViolation $exception) {
            $this->assertSame('approval_missing', $exception->reason);
        }

        // The effective definition is untouched while the proposal awaits review.
        $this->assertNull(MetricDefinition::query()->firstWhere('metric_key', 'gov.author_approver')->warn_edge);
    }

    public function test_threshold_rollback_creates_a_new_version_referencing_the_prior_one(): void
    {
        $author = User::factory()->create(['role' => 'admin']);
        $approver = User::factory()->create(['role' => 'admin']);
        $this->metricDefinition('gov.rollback_metric');

        $first = $this->applyGovernedThresholdChange(
            $author,
            $approver,
            'gov.rollback_metric',
            ['owner' => 'CMO', 'warn_edge' => 20, 'crit_edge' => 30],
            'Adopt the literature-aligned door-to-provider band.',
        );
        $second = $this->applyGovernedThresholdChange(
            $author,
            $approver,
            'gov.rollback_metric',
            ['owner' => 'CMO', 'warn_edge' => 25, 'crit_edge' => 35],
            'Loosen the band during the construction period.',
        );
        $this->assertSame(25.0, (float) MetricDefinition::query()->firstWhere('metric_key', 'gov.rollback_metric')->warn_edge);

        $rollbackRequest = $this->thresholds->requestChange(
            $this->steppedUpRequest($author),
            'gov.rollback_metric',
            [],
            'Construction ended; restore the prior approved band.',
            rollbackToVersionNumber: (int) $first->version_number,
        );
        $this->governance->decide(
            $this->steppedUpRequest($approver),
            $rollbackRequest['change']->getKey(),
            true,
            'Independent review confirms restoring the prior version.',
        );
        $applied = $this->thresholds->applyApproved($this->steppedUpRequest($author), $rollbackRequest['change']->getKey());

        $this->assertSame('rollback', $applied->change_kind);
        $this->assertSame((int) $first->getKey(), (int) $applied->rolled_back_to_version_id);
        $this->assertGreaterThan((int) $second->version_number, (int) $applied->version_number);
        $this->assertSame(20.0, (float) MetricDefinition::query()->firstWhere('metric_key', 'gov.rollback_metric')->warn_edge);
        // Rollback never rewrote history: the superseded version still exists.
        $this->assertDatabaseHas('governance.cockpit_threshold_policy_versions', [
            'cockpit_threshold_policy_version_id' => $second->getKey(),
            'change_kind' => 'governed_application',
        ]);
    }

    public function test_ai_provider_policy_application_is_dual_controlled_and_projects_exactly(): void
    {
        $author = User::factory()->create(['role' => 'admin']);
        $approver = User::factory()->create(['role' => 'admin']);
        $this->seedEddyPolicy();

        $document = $this->aiPolicy->currentDocument();
        $document['surfaces'][0]['provider_mode'] = 'disabled';
        $document['profiles'][0]['region'] = 'us-east-1';
        $document['profiles'][0]['limits']['monthly_budget_usd'] = 250.0;

        $result = $this->aiPolicy->requestChange(
            $this->steppedUpRequest($author),
            $document,
            'Disable chat routing and pin the local profile region pending review.',
        );

        try {
            $this->governance->decide(
                $this->steppedUpRequest($author),
                $result['change']->getKey(),
                true,
                'Attempting to approve my own AI policy change.',
            );
            $this->fail('The author must not approve their own AI policy change.');
        } catch (GovernanceViolation $exception) {
            $this->assertSame('author_approver_conflict', $exception->reason);
        }
        $this->assertSame('local_only', EddySurfacePolicy::query()->firstWhere('surface', 'chat')->provider_mode);

        $this->governance->decide(
            $this->steppedUpRequest($approver),
            $result['change']->getKey(),
            true,
            'Independent review of the routing and region change completed.',
        );
        $applied = $this->aiPolicy->applyApproved($this->steppedUpRequest($author), $result['change']->getKey());

        $this->assertSame('governed_application', $applied->change_kind);
        $this->assertSame('disabled', EddySurfacePolicy::query()->firstWhere('surface', 'chat')->provider_mode);
        $profile = EddyProviderProfile::query()->firstWhere('profile_id', 'local-medgemma');
        $this->assertSame('us-east-1', $profile->region);
        $this->assertSame(250.0, (float) ($profile->limits['monthly_budget_usd'] ?? null));
        $this->assertFalse($this->aiPolicy->drift());
    }

    private function applyGovernedThresholdChange(
        User $author,
        User $approver,
        string $metricKey,
        array $updates,
        string $reason,
    ): CockpitThresholdPolicyVersion {
        $result = $this->thresholds->requestChange($this->steppedUpRequest($author), $metricKey, $updates, $reason);
        $this->governance->decide(
            $this->steppedUpRequest($approver),
            $result['change']->getKey(),
            true,
            'Independent threshold review completed with evidence.',
        );

        return $this->thresholds->applyApproved($this->steppedUpRequest($author), $result['change']->getKey());
    }

    private function metricDefinition(string $metricKey): MetricDefinition
    {
        return MetricDefinition::query()->create([
            'metric_definition_uuid' => (string) Str::uuid(),
            'metric_key' => $metricKey,
            'label' => 'Governance test metric',
            'domain' => Str::before($metricKey, '.'),
            'definition' => 'A metric used to test governed threshold policy.',
            'direction' => 'down',
            'unit' => 'min',
            'owner' => null,
            'facility_key' => 'HOSP1',
            'refresh_secs' => 300,
            'is_active' => true,
        ]);
    }

    private function seedEddyPolicy(): void
    {
        EddyProviderProfile::query()->create([
            'profile_id' => 'local-medgemma',
            'display_name' => 'Local MedGemma',
            'provider_type' => 'ollama',
            'transport' => 'ollama_chat',
            'entitlement_type' => 'local',
            'model' => 'medgemma-27b',
            'base_url' => 'http://127.0.0.1:11434',
            'is_enabled' => true,
            'capabilities' => ['chat', 'streaming', 'tool_calling'],
            'safety' => ['patient_level_context_allowed' => true],
            'limits' => ['timeout' => 120],
            'fallback_profile_ids' => [],
        ]);
        EddySurfacePolicy::query()->create([
            'surface' => 'chat',
            'provider_mode' => 'local_only',
            'default_profile_id' => 'local-medgemma',
            'fallback_profile_ids' => [],
            'never_send_phi_to_cloud' => true,
            'allow_cloud' => false,
            'required_capabilities' => [],
        ]);
    }

    private function steppedUpRequest(User $user): Request
    {
        $request = Request::create('/policy-governance-test', 'POST');
        $request->setUserResolver(fn (): User => $user);
        $session = new Store('policy-governance-test', new ArraySessionHandler(120));
        $session->start();
        $session->put([
            StepUpAuthenticationService::VERIFIED_AT => time(),
            StepUpAuthenticationService::METHOD => 'password',
        ]);
        $request->setLaravelSession($session);

        return $request;
    }
}
