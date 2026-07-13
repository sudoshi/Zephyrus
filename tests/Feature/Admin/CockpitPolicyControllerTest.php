<?php

namespace Tests\Feature\Admin;

use App\Models\Ops\MetricDefinition;
use App\Models\User;
use App\Services\Auth\StepUpAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CockpitPolicyControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_threshold_policy_page_is_capability_gated_for_read_and_manage(): void
    {
        $this->metricDefinition('ed.door_to_provider', 'ED');
        $plain = User::factory()->create(['role' => 'user', 'is_active' => true, 'must_change_password' => false]);
        $integrationAdmin = User::factory()->create(['role' => 'integration_admin', 'is_active' => true, 'must_change_password' => false]);
        $auditor = User::factory()->create(['role' => 'auditor', 'is_active' => true, 'must_change_password' => false]);
        $facilityAdmin = User::factory()->create(['role' => 'facility_admin', 'is_active' => true, 'must_change_password' => false]);

        $this->actingAs($plain)->get('/admin/cockpit/thresholds')->assertForbidden();
        $this->actingAs($integrationAdmin)->get('/admin/cockpit/thresholds')->assertForbidden();

        $this->actingAs($auditor)->get('/admin/cockpit/thresholds')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/CockpitThresholds')
                ->where('canManage', false)
                ->has('definitions', 1)
                ->where('definitions.0.metricKey', 'ed.door_to_provider')
                ->where('definitions.0.policy.owner', 'ED Medical Director')
                ->where('definitions.0.policy.scope', 'HOSP1')
                ->has('filters.domains')
                ->has('pendingChanges'));

        $this->actingAs($facilityAdmin)->get('/admin/cockpit/thresholds')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canManage', true));

        // Read capability never grants proposal authority.
        $this->actingAs($auditor)
            ->postJson('/admin/cockpit/thresholds/ed.door_to_provider/preview', ['updates' => ['warn_edge' => 25]])
            ->assertForbidden();
    }

    public function test_duplicate_and_ambiguous_metric_keys_are_detected_server_side(): void
    {
        $this->metricDefinition('ed.lwbs_rate', 'ED');
        $this->metricDefinition('ED.LWBS-RATE', 'ED');
        $this->metricDefinition('ed.boarding_count', 'ED');
        $this->metricDefinition('rtdc.boarding_count', 'RTDC', domain: 'rtdc');
        $auditor = User::factory()->create(['role' => 'auditor', 'is_active' => true, 'must_change_password' => false]);

        $response = $this->actingAs($auditor)->get('/admin/cockpit/thresholds');
        $response->assertOk();
        $duplicates = collect($response->viewData('page')['props']['duplicates']);

        $duplicate = $duplicates->firstWhere('kind', 'duplicate');
        $this->assertNotNull($duplicate);
        $this->assertSame('ed_lwbs_rate', $duplicate['normalizedKey']);
        $this->assertEqualsCanonicalizing(
            ['ED.LWBS-RATE', 'ed.lwbs_rate'],
            array_column($duplicate['members'], 'metricKey'),
        );

        $ambiguous = $duplicates->firstWhere('kind', 'ambiguous');
        $this->assertNotNull($ambiguous);
        $this->assertSame('boarding_count', $ambiguous['normalizedKey']);
        $this->assertEqualsCanonicalizing(
            ['ed.boarding_count', 'rtdc.boarding_count'],
            array_column($ambiguous['members'], 'metricKey'),
        );

        // Duplicate members are flagged on the definition rows for filtering.
        $definitions = collect($response->viewData('page')['props']['definitions']);
        $this->assertTrue($definitions->firstWhere('metricKey', 'ed.lwbs_rate')['flagged']);
    }

    public function test_governed_http_flow_requires_step_up_independent_approval_and_appends_versions(): void
    {
        $this->metricDefinition('okr.dc_before_noon', 'CNO', direction: 'up');
        $author = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $approver = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);

        // Without recent authentication the request is challenged, not accepted.
        $this->actingAs($author)
            ->postJson('/admin/cockpit/thresholds/okr.dc_before_noon/changes', [
                'updates' => ['warn_edge' => 38, 'crit_edge' => 28],
                'change_reason' => 'Seasonal review of the discharge-before-noon band.',
            ])
            ->assertStatus(428);

        $create = $this->actingAs($author)->withSession($this->stepUp())
            ->postJson('/admin/cockpit/thresholds/okr.dc_before_noon/changes', [
                'updates' => ['warn_edge' => 38, 'crit_edge' => 28],
                'change_reason' => 'Seasonal review of the discharge-before-noon band.',
            ])
            ->assertCreated();
        $changeUuid = $create->json('changeRequestUuid');
        $this->assertDatabaseHas('governance.cockpit_threshold_policy_versions', [
            'metric_key' => 'okr.dc_before_noon',
            'change_kind' => 'proposal',
        ]);
        // The effective policy is untouched until approval + apply.
        $this->assertNull(MetricDefinition::query()->firstWhere('metric_key', 'okr.dc_before_noon')->warn_edge);

        // The author cannot decide their own change (service + database trigger).
        $this->actingAs($author)->withSession($this->stepUp())
            ->postJson("/admin/cockpit/threshold-changes/{$changeUuid}/decision", [
                'approve' => true,
                'reason' => 'Trying to approve my own governed change.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'author_approver_conflict');

        $this->actingAs($approver)->withSession($this->stepUp())
            ->postJson("/admin/cockpit/threshold-changes/{$changeUuid}/decision", [
                'approve' => true,
                'reason' => 'Independent review of the proposed band completed.',
            ])
            ->assertOk()
            ->assertJsonPath('decision', 'approved');

        $this->actingAs($author)->withSession($this->stepUp())
            ->postJson("/admin/cockpit/threshold-changes/{$changeUuid}/apply", [])
            ->assertOk()
            ->assertJsonPath('metricKey', 'okr.dc_before_noon')
            ->assertJsonPath('changeKind', 'governed_application');

        $definition = MetricDefinition::query()->firstWhere('metric_key', 'okr.dc_before_noon');
        $this->assertSame(38.0, (float) $definition->warn_edge);
        $this->assertSame(28.0, (float) $definition->crit_edge);
        $this->assertDatabaseHas('governance.cockpit_threshold_policy_versions', [
            'metric_key' => 'okr.dc_before_noon',
            'change_kind' => 'governed_application',
            'governed_change_request_uuid' => $changeUuid,
        ]);

        // A second apply of the same approval is refused.
        $this->actingAs($author)->withSession($this->stepUp())
            ->postJson("/admin/cockpit/threshold-changes/{$changeUuid}/apply", [])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'change_already_executed');
    }

    public function test_direction_aware_validation_constraints_reject_non_monotonic_edges(): void
    {
        $this->metricDefinition('ed.door_to_provider', 'ED');
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);

        $preview = $this->actingAs($admin)->withSession($this->stepUp())
            ->postJson('/admin/cockpit/thresholds/ed.door_to_provider/preview', [
                'updates' => ['warn_edge' => 45, 'crit_edge' => 30],
            ])
            ->assertOk();
        $this->assertContains('edges_not_monotonic_for_direction', $preview->json('errors'));

        $this->actingAs($admin)->withSession($this->stepUp())
            ->postJson('/admin/cockpit/thresholds/ed.door_to_provider/changes', [
                'updates' => ['warn_edge' => 45, 'crit_edge' => 30],
                'change_reason' => 'This proposal must be rejected by validation.',
            ])
            ->assertStatus(422);
        $this->assertDatabaseMissing('governance.cockpit_threshold_policy_versions', [
            'metric_key' => 'ed.door_to_provider',
            'change_kind' => 'proposal',
        ]);
    }

    public function test_legacy_band_edge_editor_still_works_and_never_bypasses_the_version_ledger(): void
    {
        $definition = $this->metricDefinition('ed.lwbs_rate', 'ED');
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);

        $this->actingAs($admin)
            ->putJson("/api/cockpit/kpi-definitions/{$definition->metric_key}", ['warn_edge' => 42.5])
            ->assertOk()
            ->assertJsonPath('key', $definition->metric_key);

        $this->assertSame(42.5, (float) $definition->fresh()->warn_edge);
        $this->assertDatabaseHas('governance.cockpit_threshold_policy_versions', [
            'metric_key' => $definition->metric_key,
            'change_kind' => 'direct_update',
        ]);
    }

    /** @return array<string, mixed> */
    private function stepUp(): array
    {
        return [
            StepUpAuthenticationService::VERIFIED_AT => time(),
            StepUpAuthenticationService::METHOD => 'password',
        ];
    }

    private function metricDefinition(string $metricKey, string $owner, string $domain = 'ed', string $direction = 'down'): MetricDefinition
    {
        return MetricDefinition::query()->create([
            'metric_definition_uuid' => (string) Str::uuid(),
            'metric_key' => $metricKey,
            'label' => 'Test metric '.$metricKey,
            'domain' => $domain === 'ed' ? Str::of($metricKey)->before('.')->lower()->toString() : $domain,
            'definition' => 'A cockpit metric used by the governed threshold policy tests.',
            'direction' => $direction,
            'unit' => 'min',
            'owner' => $owner === 'ED' ? 'ED Medical Director' : $owner,
            'facility_key' => 'HOSP1',
            'refresh_secs' => 300,
            'is_active' => true,
        ]);
    }
}
