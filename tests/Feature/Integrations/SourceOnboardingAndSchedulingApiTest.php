<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Healthcare\Services\NetworkRouteService;
use App\Integrations\Healthcare\Services\SourceActivationWindowService;
use App\Integrations\Healthcare\Services\SourceConfigurationVersionService;
use App\Integrations\Healthcare\Services\SourceLifecycleService;
use App\Integrations\Healthcare\Services\SourceOnboardingService;
use App\Integrations\Healthcare\Services\SourceReadinessService;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use App\Security\Network\DnsResolver;
use App\Services\Auth\StepUpAuthenticationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SourceOnboardingAndSchedulingApiTest extends TestCase
{
    use RefreshDatabase;

    private int $sourceId;

    private int $organizationId;

    private int $facilityId;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse((string) DB::scalar('select now()')));
        $this->artisan('deployment:seed-registry')->assertExitCode(0);
        $organization = Organization::create([
            'organization_key' => 'ONBOARDING_TEST_IDN',
            'name' => 'Onboarding Test IDN',
            'kind' => 'idn',
        ]);
        $facility = Facility::create([
            'organization_id' => $organization->organization_id,
            'facility_key' => 'ONBOARDING_FACILITY',
            'facility_name' => 'Onboarding Facility',
            'idn_role' => 'community_hospital',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);
        $this->organizationId = (int) $organization->organization_id;
        $this->facilityId = (int) $facility->facility_id;
        $this->sourceId = (int) DB::table('integration.sources')->insertGetId([
            'source_uuid' => (string) Str::uuid(),
            'source_key' => 'onboarding.schedule.test',
            'organization_id' => $this->organizationId,
            'facility_id' => $this->facilityId,
            'tenant_key' => 'ONBOARDING_TEST_IDN',
            'facility_key' => 'ONBOARDING_FACILITY',
            'source_name' => 'Onboarding Schedule Test',
            'vendor' => 'Test Vendor',
            'system_class' => 'ehr',
            'environment' => 'production',
            'interface_type' => 'fhir_r4',
            'active_status' => 'testing',
            'contract_status' => 'executed',
            'baa_status' => 'executed',
            'phi_allowed' => true,
            'go_live_status' => 'ready',
            'lifecycle_state' => 'validating',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'source_id');
        app(SourceConfigurationVersionService::class)->initialize(
            $this->sourceId,
            null,
            'Initial onboarding schedule test configuration.',
            (string) Str::uuid(),
        );
        app(SourceLifecycleService::class)->initialize(
            $this->sourceId,
            null,
            'Initial onboarding schedule test lifecycle.',
        );
        app(SourceOnboardingService::class)->initialize($this->sourceId);
        $this->fakeDns(['fhir.test' => ['8.8.8.8']]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_onboarding_is_versioned_readiness_is_explicit_and_evidence_references_are_sanitized(): void
    {
        $actor = User::factory()->create(['role' => 'superuser']);
        $this->selectScope($actor);

        $initialResponse = $this->getJson("/api/admin/integrations/sources/{$this->sourceId}/onboarding")
            ->assertOk()
            ->assertJsonPath('data.currentProfile.versionNumber', 1)
            ->assertJsonPath('data.readiness.status', 'not_ready')
            ->assertJsonPath('data.readiness.supportBadges.0', 'template');
        $decodedInitial = json_decode($initialResponse->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $this->assertIsObject($decodedInitial->data->currentProfile->sloDefinition);
        $initial = $initialResponse->json('data');
        $failedCodes = collect($initial['readiness']['requirements'])
            ->where('status', 'failed')->pluck('code')->all();
        $this->assertContains('profile.protocol_profile', $failedCodes);
        $this->assertContains('evidence.rollback_plan', $failedCodes);

        $profile = $this->postJson("/api/admin/integrations/sources/{$this->sourceId}/onboarding-versions", [
            ...$this->completeProfilePayload(),
            'expected_onboarding_version_id' => $initial['currentProfile']['onboardingVersionId'],
            'change_reason' => 'Complete the source governance profile for production validation.',
        ])->assertCreated()
            ->assertJsonPath('data.versionNumber', 2)
            ->assertJsonPath('data.conformanceStatus', 'passed')
            ->json('data');

        $this->postJson("/api/admin/integrations/sources/{$this->sourceId}/onboarding-versions", [
            ...$this->completeProfilePayload(),
            'expected_onboarding_version_id' => $initial['currentProfile']['onboardingVersionId'],
            'change_reason' => 'Attempt to overwrite a stale onboarding authority version.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['expected_onboarding_version_id']);

        $this->addRuntimeReferences();
        foreach ($this->requiredEvidence() as $type => $status) {
            $reference = 'https://evidence.test/repository/'.$type;
            $response = $this->postJson("/api/admin/integrations/sources/{$this->sourceId}/evidence", [
                'evidence_type' => $type,
                'evidence_status' => $status,
                'display_label' => str_replace('_', ' ', ucfirst($type)).' evidence',
                'reference_uri' => $reference,
                'artifact_sha256' => str_repeat('b', 64),
                'issued_at' => now()->subDay()->toIso8601String(),
                'expires_at' => now()->addYear()->toIso8601String(),
                'reason' => 'Record independently reviewed source onboarding evidence.',
            ])->assertCreated()
                ->assertJsonPath('data.referenceConfigured', true);
            $this->assertStringNotContainsString($reference, $response->getContent());
        }

        $assessment = $this->postJson("/api/admin/integrations/sources/{$this->sourceId}/readiness-assessments")
            ->assertCreated()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.score', 100)
            ->json('data');
        $this->assertContains('production-certified', $assessment['supportBadges']);
        $this->assertSame($profile['onboardingVersionId'], $assessment['onboardingVersionId']);
        $this->assertDatabaseHas('integration.source_readiness_assessments', [
            'source_readiness_assessment_id' => $assessment['readinessAssessmentId'],
            'readiness_status' => 'ready',
        ]);

        $auditJson = DB::table('integration.configuration_audits')
            ->where('entity_type', 'source_evidence')
            ->pluck('after_payload')
            ->implode(' ');
        $this->assertStringNotContainsString('https://evidence.test', $auditJson);
        $this->assertStringNotContainsString('repository', $auditJson);
    }

    public function test_future_activation_requires_step_up_independent_approval_and_scheduler_execution(): void
    {
        $this->completeReadiness();
        $author = User::factory()->create(['role' => 'superuser']);
        $approver = User::factory()->create(['role' => 'superuser']);
        $payload = $this->activationWindowPayload();

        $this->selectScope($author)
            ->postJson("/api/admin/integrations/sources/{$this->sourceId}/activation-window-requests", $payload)
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'step_up_required');

        $request = $this->selectScope($author)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/sources/{$this->sourceId}/activation-window-requests", $payload)
            ->assertCreated()
            ->assertJsonPath('data.action', 'schedule_production_source_activation')
            ->assertJsonPath('data.activationWindow.status', 'pending_approval')
            ->json('data');
        $changeUuid = $request['changeRequestUuid'];

        $this->selectScope($author)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/governed-changes/{$changeUuid}/decision", [
                'decision' => 'approved',
                'reason' => 'Attempt to approve the activation window I authored.',
            ])->assertConflict()->assertJsonPath('error.code', 'author_approver_conflict');
        $this->selectScope($approver)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/governed-changes/{$changeUuid}/decision", [
                'decision' => 'approved',
                'reason' => 'Independent review confirmed the exact readiness and activation window.',
            ])->assertOk();
        $this->selectScope($author)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/governed-changes/{$changeUuid}/execute-source-activation-schedule")
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled');
        $this->assertDatabaseHas('integration.sources', [
            'source_id' => $this->sourceId,
            'lifecycle_state' => 'scheduled',
            'active_status' => 'testing',
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(6));
        $result = app(SourceActivationWindowService::class)->runDue('scheduler:test-a', 10, 120);
        $this->assertSame(['expired' => 0, 'claimed' => 1, 'activated' => 1, 'failed' => 0], $result);
        $this->assertSame(
            ['expired' => 0, 'claimed' => 0, 'activated' => 0, 'failed' => 0],
            app(SourceActivationWindowService::class)->runDue('scheduler:test-b', 10, 120),
        );
        $this->assertDatabaseHas('integration.source_activation_windows', [
            'activation_window_uuid' => $request['activationWindow']['activationWindowUuid'],
            'status' => 'activated',
            'attempt_count' => 1,
        ]);
        $this->assertDatabaseHas('integration.sources', [
            'source_id' => $this->sourceId,
            'lifecycle_state' => 'live',
            'active_status' => 'active',
            'go_live_status' => 'live',
        ]);
        $this->assertDatabaseHas('integration.source_lifecycle_events', [
            'source_id' => $this->sourceId,
            'from_state' => 'scheduled',
            'to_state' => 'live',
            'governed_change_request_uuid' => $changeUuid,
        ]);
    }

    public function test_evidence_drift_invalidates_an_approved_activation_schedule(): void
    {
        $this->completeReadiness();
        $author = User::factory()->create(['role' => 'superuser']);
        $approver = User::factory()->create(['role' => 'superuser']);
        $changeUuid = $this->selectScope($author)->withSession($this->stepUp())
            ->postJson(
                "/api/admin/integrations/sources/{$this->sourceId}/activation-window-requests",
                $this->activationWindowPayload(),
            )->assertCreated()->json('data.changeRequestUuid');
        $this->selectScope($approver)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/governed-changes/{$changeUuid}/decision", [
                'decision' => 'approved',
                'reason' => 'Approve the exact readiness evidence submitted for scheduling.',
            ])->assertOk();

        $current = DB::table('integration.source_evidence_records')
            ->where('source_id', $this->sourceId)
            ->where('evidence_type', 'change_ticket')
            ->orderByDesc('source_evidence_record_id')
            ->firstOrFail();
        app(SourceOnboardingService::class)->addEvidence($this->sourceId, [
            'evidence_type' => 'change_ticket',
            'evidence_status' => 'verified',
            'display_label' => 'Replacement production change ticket',
            'reference_uri' => 'https://evidence.test/repository/change-ticket-replacement',
            'artifact_sha256' => str_repeat('c', 64),
            'issued_at' => now(),
            'expires_at' => now()->addYear(),
            'supersedes_evidence_id' => $current->source_evidence_record_id,
            'reason' => 'Replace the change ticket after the activation approval was issued.',
        ], $author->id);

        $this->selectScope($author)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/governed-changes/{$changeUuid}/execute-source-activation-schedule")
            ->assertConflict()
            ->assertJsonPath('error.code', 'approved_payload_mismatch');
        $this->assertDatabaseHas('integration.source_activation_windows', [
            'governed_change_request_uuid' => $changeUuid,
            'status' => 'pending_approval',
        ]);
        $this->assertDatabaseMissing('governance.change_executions', [
            'change_request_uuid' => $changeUuid,
            'outcome' => 'success',
        ]);
    }

    public function test_activation_lease_is_exclusive_and_recoverable_after_expiry(): void
    {
        $this->completeReadiness();
        [$changeUuid] = $this->approveAndScheduleWindow();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(6));
        $windows = app(SourceActivationWindowService::class);
        $first = $windows->claimDue('scheduler:lease-a', 10, 30);
        $this->assertCount(1, $first);
        $this->assertSame([], $windows->claimDue('scheduler:lease-b', 10, 30));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(31));
        $reclaimed = $windows->claimDue('scheduler:lease-b', 10, 30);
        $this->assertCount(1, $reclaimed);
        $windows->executeClaimed((string) $reclaimed[0]->activation_window_uuid, 'scheduler:lease-b');
        $this->assertDatabaseHas('integration.source_activation_windows', [
            'governed_change_request_uuid' => $changeUuid,
            'status' => 'activated',
            'attempt_count' => 2,
        ]);
    }

    public function test_expired_lease_fails_closed_when_scheduler_attempts_are_exhausted(): void
    {
        $this->completeReadiness();
        [, $windowUuid] = $this->approveAndScheduleWindow();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(6));
        $windows = app(SourceActivationWindowService::class);

        $this->assertCount(1, $windows->claimDue('scheduler:attempt-a', 1, 30));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(31));
        $this->assertCount(1, $windows->claimDue('scheduler:attempt-b', 1, 30));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(31));
        $this->assertCount(1, $windows->claimDue('scheduler:attempt-c', 1, 30));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(31));

        $this->assertSame(
            ['expired' => 0, 'claimed' => 0, 'activated' => 0, 'failed' => 1],
            $windows->runDue('scheduler:attempt-d', 1, 30),
        );
        $this->assertDatabaseHas('integration.source_activation_windows', [
            'activation_window_uuid' => $windowUuid,
            'status' => 'failed',
            'attempt_count' => 3,
            'last_error_code' => 'activation_attempts_exhausted',
        ]);
        $this->assertDatabaseHas('integration.sources', [
            'source_id' => $this->sourceId,
            'lifecycle_state' => 'approved',
        ]);
    }

    public function test_scheduler_command_executes_a_due_window(): void
    {
        $this->completeReadiness();
        [, $windowUuid] = $this->approveAndScheduleWindow();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(6));

        $this->artisan('integrations:execute-scheduled-activations', [
            '--owner' => 'scheduler:command-test',
            '--limit' => 5,
            '--lease-seconds' => 60,
        ])->expectsOutputToContain('1 claimed, 1 activated, 0 failed')
            ->assertSuccessful();

        $this->assertDatabaseHas('integration.source_activation_windows', [
            'activation_window_uuid' => $windowUuid,
            'status' => 'activated',
        ]);
        $this->assertDatabaseHas('integration.sources', [
            'source_id' => $this->sourceId,
            'lifecycle_state' => 'live',
        ]);
    }

    public function test_scheduler_fails_closed_and_releases_scheduled_state_when_readiness_drifts(): void
    {
        $this->completeReadiness();
        [$changeUuid, $windowUuid] = $this->approveAndScheduleWindow();
        $current = DB::table('integration.source_evidence_records')
            ->where('source_id', $this->sourceId)
            ->where('evidence_type', 'security_review')
            ->orderByDesc('source_evidence_record_id')
            ->firstOrFail();
        app(SourceOnboardingService::class)->addEvidence($this->sourceId, [
            'evidence_type' => 'security_review',
            'evidence_status' => 'revoked',
            'display_label' => 'Revoked security review',
            'reference_uri' => 'https://evidence.test/repository/security-review-revoked',
            'artifact_sha256' => str_repeat('f', 64),
            'issued_at' => now(),
            'expires_at' => now()->addYear(),
            'supersedes_evidence_id' => $current->source_evidence_record_id,
            'reason' => 'Revoke security approval before the scheduled cutover.',
        ], null);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(6));

        $this->assertSame(
            ['expired' => 0, 'claimed' => 1, 'activated' => 0, 'failed' => 1],
            app(SourceActivationWindowService::class)->runDue('scheduler:fail-closed', 5, 60),
        );
        $this->assertDatabaseHas('integration.source_activation_windows', [
            'activation_window_uuid' => $windowUuid,
            'status' => 'failed',
            'last_error_code' => 'approved_payload_mismatch',
        ]);
        $this->assertDatabaseHas('integration.sources', [
            'source_id' => $this->sourceId,
            'lifecycle_state' => 'approved',
            'active_status' => 'testing',
        ]);
        $this->assertDatabaseHas('integration.source_lifecycle_events', [
            'source_id' => $this->sourceId,
            'from_state' => 'scheduled',
            'to_state' => 'approved',
            'governed_change_request_uuid' => $changeUuid,
        ]);
    }

    public function test_activation_window_cancellation_requires_step_up_and_prevents_execution(): void
    {
        $this->completeReadiness();
        [, $windowUuid] = $this->approveAndScheduleWindow();
        $actor = User::factory()->create(['role' => 'superuser']);
        $url = "/api/admin/integrations/sources/{$this->sourceId}/activation-windows/{$windowUuid}/cancel";

        $this->selectScope($actor)->withSession([
            StepUpAuthenticationService::VERIFIED_AT => 0,
            StepUpAuthenticationService::METHOD => null,
        ])->postJson($url, [
            'reason' => 'Cancel cutover after the vendor declared an incident.',
        ])->assertStatus(428)->assertJsonPath('error.code', 'step_up_required');
        $this->selectScope($actor)->withSession($this->stepUp())->postJson($url, [
            'reason' => 'Cancel cutover after the vendor declared an incident.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelledByUserId', $actor->id);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(6));
        $this->assertSame(
            ['expired' => 0, 'claimed' => 0, 'activated' => 0, 'failed' => 0],
            app(SourceActivationWindowService::class)->runDue('scheduler:cancel-test', 5, 60),
        );
        $this->assertDatabaseHas('integration.sources', [
            'source_id' => $this->sourceId,
            'lifecycle_state' => 'approved',
        ]);
        $this->assertDatabaseHas('integration.configuration_audits', [
            'entity_type' => 'source_activation_window',
            'entity_key' => $windowUuid,
            'action' => 'cancelled',
        ]);
    }

    public function test_protected_source_rejects_ordinary_child_configuration_mutations_but_allows_governed_rotation(): void
    {
        $this->completeReadiness();
        [, $windowUuid] = $this->approveAndScheduleWindow();
        $actor = User::factory()->create(['role' => 'superuser']);
        $approver = User::factory()->create(['role' => 'superuser']);
        $endpointId = (int) DB::table('integration.source_endpoints')
            ->where('source_id', $this->sourceId)->value('source_endpoint_id');
        $credentialId = (int) DB::table('integration.source_credentials')
            ->where('source_id', $this->sourceId)->value('source_credential_id');
        config()->set('integrations.network.allowed_hosts', ['fhir.test']);
        config()->set('integrations.network.require_dns_resolution', false);

        $this->selectScope($actor)->postJson("/api/admin/integrations/sources/{$this->sourceId}/endpoints", [
            'endpoint_type' => 'other',
            'url' => 'https://fhir.test/secondary',
            'is_active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['configuration']);
        $this->selectScope($actor)->patchJson(
            "/api/admin/integrations/sources/{$this->sourceId}/endpoints/{$endpointId}",
            ['is_active' => false],
        )->assertUnprocessable()->assertJsonValidationErrors(['configuration']);
        $this->selectScope($actor)->deleteJson(
            "/api/admin/integrations/sources/{$this->sourceId}/endpoints/{$endpointId}",
        )->assertUnprocessable()->assertJsonValidationErrors(['configuration']);

        $this->selectScope($actor)->postJson("/api/admin/integrations/sources/{$this->sourceId}/credentials", [
            'credential_key' => 'secondary-key',
            'credential_type' => 'oauth2_client',
            'secret_ref' => 'vault://zephyrus/integration/secondary',
        ])->assertUnprocessable()->assertJsonValidationErrors(['configuration']);
        $this->selectScope($actor)->patchJson(
            "/api/admin/integrations/sources/{$this->sourceId}/credentials/{$credentialId}",
            ['credential_key' => 'renamed-outside-governance'],
        )->assertUnprocessable()->assertJsonValidationErrors(['configuration']);
        $this->selectScope($actor)->deleteJson(
            "/api/admin/integrations/sources/{$this->sourceId}/credentials/{$credentialId}",
            ['reason' => 'Attempt to revoke a protected credential outside governance.'],
        )->assertUnprocessable()->assertJsonValidationErrors(['configuration']);

        $rotation = [
            'secret_ref' => 'vault://zephyrus/integration/governed-replacement',
            'rotates_at' => CarbonImmutable::now()->addYear()->toDateString(),
        ];
        $changeUuid = $this->selectScope($actor)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/sources/{$this->sourceId}/credentials/{$credentialId}/rotation-requests", [
                ...$rotation,
                'reason' => 'Rotate the credential through independent approval while protecting the source.',
            ])->assertCreated()->json('data.changeRequestUuid');
        $this->selectScope($approver)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/governed-changes/{$changeUuid}/decision", [
                'decision' => 'approved',
                'reason' => 'Independent review confirmed the bounded credential replacement.',
            ])->assertOk();
        $this->selectScope($actor)->withSession($this->stepUp())
            ->postJson(
                "/api/admin/integrations/governed-changes/{$changeUuid}/sources/{$this->sourceId}/credentials/{$credentialId}/execute-rotation",
                $rotation,
            )->assertOk();
        $this->assertSame(
            $rotation['secret_ref'],
            DB::table('integration.source_credentials')->where('source_credential_id', $credentialId)->value('secret_ref'),
        );

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(6));
        $this->assertSame(
            ['expired' => 0, 'claimed' => 1, 'activated' => 0, 'failed' => 1],
            app(SourceActivationWindowService::class)->runDue('scheduler:governed-rotation-drift', 5, 60),
        );
        $this->assertDatabaseHas('integration.source_activation_windows', [
            'activation_window_uuid' => $windowUuid,
            'status' => 'failed',
            'last_error_code' => 'approved_payload_mismatch',
        ]);
    }

    public function test_scheduler_detects_privileged_endpoint_authority_drift(): void
    {
        $this->completeReadiness();
        [, $windowUuid] = $this->approveAndScheduleWindow();
        DB::table('integration.source_endpoints')->where('source_id', $this->sourceId)->update([
            'url' => 'https://fhir.test/r4/repointed-outside-application',
            'updated_at' => now()->addSecond(),
        ]);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(6));

        $this->assertSame(
            ['expired' => 0, 'claimed' => 1, 'activated' => 0, 'failed' => 1],
            app(SourceActivationWindowService::class)->runDue('scheduler:endpoint-drift', 5, 60),
        );
        $this->assertDatabaseHas('integration.source_activation_windows', [
            'activation_window_uuid' => $windowUuid,
            'status' => 'failed',
            'last_error_code' => 'approved_payload_mismatch',
        ]);
        $this->assertDatabaseHas('integration.sources', [
            'source_id' => $this->sourceId,
            'lifecycle_state' => 'approved',
        ]);
    }

    public function test_onboarding_evidence_and_readiness_ledgers_reject_update_and_delete(): void
    {
        $this->completeReadiness();
        app(SourceReadinessService::class)->evaluate(
            $this->sourceId,
            CarbonImmutable::now(),
            persist: true,
        );
        foreach ([
            ['integration.source_onboarding_versions', 'source_onboarding_version_id', 'created_at'],
            ['integration.source_evidence_records', 'source_evidence_record_id', 'created_at'],
            ['integration.source_readiness_assessments', 'source_readiness_assessment_id', 'evaluated_at'],
        ] as [$table, $key, $timestamp]) {
            $id = DB::table($table)->where('source_id', $this->sourceId)->max($key);
            try {
                DB::transaction(fn () => DB::table($table)->where($key, $id)->update([
                    $timestamp => now()->addMinute(),
                ]));
                $this->fail("{$table} accepted an update.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            }
            try {
                DB::transaction(fn () => DB::table($table)->where($key, $id)->delete());
                $this->fail("{$table} accepted a delete.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            }
        }
    }

    private function completeReadiness(): void
    {
        $onboarding = app(SourceOnboardingService::class);
        $current = $onboarding->latest($this->sourceId);
        $onboarding->revise(
            $this->sourceId,
            $this->completeProfilePayload(),
            (int) $current->source_onboarding_version_id,
            null,
            'Complete production onboarding before activation scheduling.',
        );
        $this->addRuntimeReferences();
        foreach ($this->requiredEvidence() as $type => $status) {
            $onboarding->addEvidence($this->sourceId, [
                'evidence_type' => $type,
                'evidence_status' => $status,
                'display_label' => str_replace('_', ' ', ucfirst($type)).' readiness evidence',
                'reference_uri' => 'https://evidence.test/repository/'.$type,
                'artifact_sha256' => str_repeat('d', 64),
                'issued_at' => now()->subDay(),
                'expires_at' => now()->addYear(),
                'reason' => 'Record reviewed evidence for scheduled activation testing.',
            ], null);
        }
        $assessment = app(SourceReadinessService::class)->evaluate(
            $this->sourceId,
            CarbonImmutable::now(),
            persist: false,
        );
        $this->assertSame('ready', $assessment['status']);
    }

    /** @return array<string, mixed> */
    private function completeProfilePayload(): array
    {
        return [
            'system_version' => '2026.1',
            'protocol_profile' => 'FHIR R4 + US Core 6.1',
            'owner_name' => 'Integration Owner',
            'steward_name' => 'Data Steward',
            'network_route_key' => 'private-link-east',
            'data_classification' => 'restricted_phi',
            'permitted_purpose' => 'Coordinate production clinical operations and patient flow.',
            'phi_permission_basis' => 'Executed BAA and minimum-necessary operations policy.',
            'retention_policy_key' => 'clinical-interface-7y',
            'retention_days' => 2555,
            'credential_strategy' => 'oauth2',
            'conformance_status' => 'passed',
            'support_entitlement' => 'critical',
            'vendor_support_identifier' => 'SUPPORT-TEST-001',
            'maintenance_timezone' => 'America/New_York',
            'contacts' => [
                ['role' => 'owner', 'name' => 'Integration Owner', 'email' => 'owner@example.test'],
                ['role' => 'steward', 'name' => 'Data Steward', 'email' => 'steward@example.test'],
                ['role' => 'escalation', 'name' => 'Command Center', 'email' => 'escalation@example.test'],
            ],
            'maintenance_windows' => [
                ['weekday' => 0, 'start_local' => '02:00', 'duration_minutes' => 60, 'purpose' => 'Vendor maintenance'],
            ],
            'slo_definition' => [
                'availability_percent' => 99.9,
                'freshness_minutes' => 15,
                'completeness_percent' => 99.9,
                'latency_ms' => 5000,
                'error_rate_percent' => 1,
                'acknowledgement_seconds' => 30,
                'reconciliation_variance_percent' => 1,
            ],
        ];
    }

    /** @return array<string, string> */
    private function requiredEvidence(): array
    {
        return [
            'contract' => 'verified',
            'baa' => 'verified',
            'dua' => 'not_required',
            'conformance_report' => 'verified',
            'vendor_approval' => 'verified',
            'customer_uat' => 'verified',
            'test_results' => 'verified',
            'security_review' => 'verified',
            'change_ticket' => 'verified',
            'cutover_plan' => 'verified',
            'rollback_plan' => 'verified',
        ];
    }

    private function addRuntimeReferences(): void
    {
        $endpointId = (int) DB::table('integration.source_endpoints')->insertGetId([
            'source_id' => $this->sourceId,
            'endpoint_type' => 'api_base',
            'url' => 'https://fhir.test/r4',
            'auth_type' => 'oauth2',
            'tls_mode' => 'system_ca',
            'is_active' => true,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'source_endpoint_id');
        DB::table('integration.source_credentials')->insert([
            'source_id' => $this->sourceId,
            'credential_key' => 'backend-services',
            'credential_type' => 'smart_backend_services',
            'secret_ref' => 'vault://zephyrus/integration/test',
            'is_active' => true,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(NetworkRouteService::class)->create($this->sourceId, [
            'route_key' => 'fhir-public-egress',
            'source_endpoint_id' => $endpointId,
            'transport' => 'public_internet',
            'hostname' => 'fhir.test',
            'port' => 443,
            'dns_policy' => 'public_only',
            'allowed_ip_cidrs' => [],
            'egress_policy_key' => 'integration-https-egress',
            'mtls_required' => false,
            'change_reason' => 'Authorize the exact FHIR test endpoint for readiness evaluation.',
        ], null);
    }

    /** @return array<string, string> */
    private function activationWindowPayload(): array
    {
        return [
            'activate_at' => CarbonImmutable::now()->addMinutes(5)->toIso8601String(),
            'window_ends_at' => CarbonImmutable::now()->addMinutes(30)->toIso8601String(),
            'requested_timezone' => 'America/New_York',
            'reason' => 'Schedule the independently reviewed production cutover window.',
        ];
    }

    /** @return array{string, string} */
    private function approveAndScheduleWindow(): array
    {
        $author = User::factory()->create(['role' => 'superuser']);
        $approver = User::factory()->create(['role' => 'superuser']);
        $response = $this->selectScope($author)->withSession($this->stepUp())
            ->postJson(
                "/api/admin/integrations/sources/{$this->sourceId}/activation-window-requests",
                $this->activationWindowPayload(),
            )->assertCreated()->json('data');
        $changeUuid = $response['changeRequestUuid'];
        $this->selectScope($approver)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/governed-changes/{$changeUuid}/decision", [
                'decision' => 'approved',
                'reason' => 'Independently approve the exact scheduled activation authority.',
            ])->assertOk();
        $this->selectScope($author)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/governed-changes/{$changeUuid}/execute-source-activation-schedule")
            ->assertOk();

        return [$changeUuid, $response['activationWindow']['activationWindowUuid']];
    }

    /** @return array<string, int|string> */
    private function stepUp(): array
    {
        return [
            StepUpAuthenticationService::VERIFIED_AT => time(),
            StepUpAuthenticationService::METHOD => 'password',
        ];
    }

    private function selectScope(User $user): self
    {
        $this->actingAs($user)->put('/admin/active-scope', [
            'organization_id' => $this->organizationId,
            'facility_id' => $this->facilityId,
            'source_id' => $this->sourceId,
            'return_path' => '/integrations?tab=sources',
        ])->assertRedirect();

        return $this;
    }

    /** @param array<string, list<string>> $answers */
    private function fakeDns(array $answers): void
    {
        $this->app->instance(DnsResolver::class, new class($answers) extends DnsResolver
        {
            /** @param array<string, list<string>> $answers */
            public function __construct(private readonly array $answers) {}

            public function resolve(string $host): array
            {
                return $this->answers[$host] ?? [];
            }
        });
    }
}
