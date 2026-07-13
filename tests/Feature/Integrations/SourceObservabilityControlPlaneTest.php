<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Healthcare\Services\IntegrationControlPlaneService;
use App\Integrations\Healthcare\Services\SourceConfigurationVersionService;
use App\Integrations\Healthcare\Services\SourceLifecycleService;
use App\Integrations\Healthcare\Services\SourceMaintenanceWindowService;
use App\Integrations\Healthcare\Services\SourceObservabilityService;
use App\Integrations\Healthcare\Services\SourceOnboardingService;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SourceObservabilityControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    private int $sourceId;

    private int $organizationId;

    private int $facilityId;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse((string) DB::scalar('select now()')));
        $this->artisan('deployment:seed-registry')->assertSuccessful();
        $organization = Organization::create([
            'organization_key' => 'OBSERVABILITY_TEST_IDN',
            'name' => 'Observability Test IDN',
            'kind' => 'idn',
        ]);
        $facility = Facility::create([
            'organization_id' => $organization->organization_id,
            'facility_key' => 'OBSERVABILITY_TEST_FACILITY',
            'facility_name' => 'Observability Test Facility',
            'idn_role' => 'community_hospital',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);
        $this->organizationId = (int) $organization->organization_id;
        $this->facilityId = (int) $facility->facility_id;
        $this->sourceId = (int) DB::table('integration.sources')->insertGetId([
            'source_uuid' => (string) Str::uuid7(),
            'source_key' => 'observability.fhir.test',
            'organization_id' => $this->organizationId,
            'facility_id' => $this->facilityId,
            'tenant_key' => 'OBSERVABILITY_TEST_IDN',
            'facility_key' => 'OBSERVABILITY_TEST_FACILITY',
            'source_name' => 'Observability FHIR Test',
            'vendor' => 'Test Vendor',
            'system_class' => 'ehr',
            'environment' => 'production',
            'interface_type' => 'fhir_r4',
            'active_status' => 'testing',
            'protocol_health_status' => 'healthy',
            'protocol_health_checked_at' => now(),
            'contract_status' => 'executed',
            'baa_status' => 'executed',
            'phi_allowed' => true,
            'go_live_status' => 'testing',
            'lifecycle_state' => 'validating',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'source_id');
        app(SourceConfigurationVersionService::class)->initialize(
            $this->sourceId,
            null,
            'Initialize source observability test configuration authority.',
            (string) Str::uuid7(),
        );
        app(SourceLifecycleService::class)->initialize(
            $this->sourceId,
            null,
            'Initialize source observability test lifecycle authority.',
        );
        app(SourceOnboardingService::class)->initialize($this->sourceId);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_onboarding_slo_is_normalized_one_to_one_versioned_and_append_only(): void
    {
        $initialDefinition = DB::table('integration.source_slo_definitions')
            ->where('source_id', $this->sourceId)
            ->firstOrFail();
        $this->assertSame('incomplete', $initialDefinition->definition_status);
        $this->assertNull($initialDefinition->completeness_percent);

        $onboarding = $this->completeOnboarding();
        $definition = DB::table('integration.source_slo_definitions')
            ->where('source_onboarding_version_id', $onboarding->source_onboarding_version_id)
            ->firstOrFail();
        $this->assertSame('complete', $definition->definition_status);
        $this->assertSame(2, (int) $definition->version_number);
        $this->assertSame((int) $initialDefinition->source_slo_definition_id, (int) $definition->previous_definition_id);
        $this->assertSame('99.9000', (string) $definition->completeness_percent);
        $this->assertSame(60, (int) $definition->evaluation_window_minutes);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $definition->definition_sha256);
        $this->assertSame(
            DB::table('integration.source_onboarding_versions')->where('source_id', $this->sourceId)->count(),
            DB::table('integration.source_slo_definitions')->where('source_id', $this->sourceId)->count(),
        );

        try {
            DB::transaction(fn () => DB::table('integration.source_slo_definitions')
                ->where('source_slo_definition_id', $definition->source_slo_definition_id)
                ->update(['freshness_minutes' => 30]));
            $this->fail('The normalized SLO authority accepted an update.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
        try {
            DB::transaction(fn () => DB::table('integration.source_slo_definitions')
                ->where('source_slo_definition_id', $definition->source_slo_definition_id)
                ->delete());
            $this->fail('The normalized SLO authority accepted a delete.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
    }

    public function test_observation_persists_truthful_metrics_unknowns_and_monotonic_current_projection(): void
    {
        $this->completeOnboarding();
        $observedAt = CarbonImmutable::parse('2026-07-13T14:00:00Z');
        CarbonImmutable::setTestNow($observedAt);
        $runId = $this->ingestRun($observedAt->subMinutes(5), 'completed', 10, 10, 0, 0);
        $this->watermark($observedAt->subMinute());
        $this->canonicalEvent($runId, $observedAt->subMinutes(2), $observedAt->subMinutes(2)->addSecond());

        $observation = app(SourceObservabilityService::class)->observe($this->sourceId, $observedAt);

        $this->assertSame('unknown', $observation['status']);
        $this->assertSame(5, $observation['summary']['met']);
        $this->assertSame(0, $observation['summary']['breached']);
        $this->assertSame(2, $observation['summary']['unknown']);
        $this->assertSame(7, DB::table('integration.health_observation_metrics')
            ->where('health_observation_id', $observation['observationId'])->count());
        $this->assertDatabaseHas('integration.health_observation_metrics', [
            'health_observation_id' => $observation['observationId'],
            'metric_key' => 'latency',
            'metric_status' => 'met',
            'evidence_code' => 'receipt_to_projection_p95',
        ]);
        $this->assertDatabaseHas('integration.health_observation_metrics', [
            'health_observation_id' => $observation['observationId'],
            'metric_key' => 'acknowledgement',
            'metric_status' => 'unknown',
            'evidence_code' => 'acknowledgement_ledger_unavailable',
        ]);
        $this->assertDatabaseHas('integration.source_health_current', [
            'source_id' => $this->sourceId,
            'health_observation_id' => $observation['observationId'],
            'observation_status' => 'unknown',
            'projection_version' => 1,
        ]);
        $this->assertSame(0, DB::table('integration.slo_breaches')->count());

        $actor = User::factory()->create(['role' => 'integration_operator']);
        $second = app(SourceObservabilityService::class)->observe(
            $this->sourceId,
            $observedAt->addMinute(),
            'manual',
            (string) Str::uuid7(),
            (string) Str::uuid7(),
            $actor->id,
        );
        $this->assertGreaterThan($observation['observationId'], $second['observationId']);
        $this->assertDatabaseHas('integration.source_health_current', [
            'source_id' => $this->sourceId,
            'health_observation_id' => $second['observationId'],
            'projection_version' => 2,
        ]);
        $this->assertDatabaseHas('integration.health_observations', [
            'health_observation_id' => $second['observationId'],
            'collector_origin' => 'manual',
            'recorded_by_user_id' => $actor->id,
        ]);

        $source = collect(app(IntegrationControlPlaneService::class)->snapshot()['sources'])
            ->firstWhere('sourceId', $this->sourceId);
        $this->assertSame('unknown', $source['observabilityStatus']);
        $this->assertSame('unobserved', $source['healthStatus']);
        $this->assertSame($second['observationId'], $source['healthObservationId']);
        $this->assertSame(2, $source['sloSummary']['unknown']);
        $this->assertSame(0, $source['openSloBreaches']);
    }

    public function test_maintenance_suppresses_notification_without_erasing_breach_and_recovery_closes_it(): void
    {
        $this->completeOnboarding();
        $maintenanceAt = CarbonImmutable::parse('2026-07-12T06:30:00Z'); // Sunday 02:30 EDT.
        CarbonImmutable::setTestNow($maintenanceAt);
        $this->ingestRun($maintenanceAt->subMinutes(20), 'failed', 10, 5, 5, 0);
        $this->watermark($maintenanceAt->subMinutes(90));

        $during = app(SourceObservabilityService::class)->observe($this->sourceId, $maintenanceAt);
        $this->assertSame('maintenance', $during['status']);
        $this->assertTrue($during['maintenanceActive']);
        $this->assertSame(4, $during['summary']['breached']);
        $this->assertSame(4, DB::table('integration.slo_breaches')->count());
        $this->assertSame(4, DB::table('integration.slo_breach_events')
            ->where('event_type', 'opened')
            ->where('status_after', 'suppressed')
            ->where('notification_suppressed', true)
            ->count());

        $afterMaintenance = $maintenanceAt->addHours(2);
        CarbonImmutable::setTestNow($afterMaintenance);
        $after = app(SourceObservabilityService::class)->observe($this->sourceId, $afterMaintenance);
        $this->assertSame('failed', $after['status']);
        $this->assertFalse($after['maintenanceActive']);
        $this->assertDatabaseHas('integration.slo_breach_events', [
            'event_type' => 'resumed',
            'status_after' => 'open',
            'notification_suppressed' => false,
        ]);
        $this->assertNotEmpty(app(SourceObservabilityService::class)->snapshot($this->sourceId)['openBreaches']);

        $recoveredAt = CarbonImmutable::parse('2026-07-12T10:00:00Z');
        CarbonImmutable::setTestNow($recoveredAt);
        $runId = $this->ingestRun($recoveredAt->subMinutes(5), 'completed', 10, 10, 0, 0);
        $this->watermark($recoveredAt->subMinute());
        $this->canonicalEvent($runId, $recoveredAt->subMinutes(2), $recoveredAt->subMinutes(2)->addSecond());
        $recovered = app(SourceObservabilityService::class)->observe($this->sourceId, $recoveredAt);
        $this->assertSame('unknown', $recovered['status'], 'ACK and reconciliation remain unknown after measured metrics recover.');
        $this->assertSame([], app(SourceObservabilityService::class)->snapshot($this->sourceId)['openBreaches']);
        $this->assertSame(4, DB::table('integration.slo_breach_events')->where('event_type', 'recovered')->count());
    }

    public function test_maintenance_window_resolver_handles_overnight_and_dst_boundaries(): void
    {
        $service = app(SourceMaintenanceWindowService::class);
        $overnight = (object) [
            'maintenance_timezone' => 'America/New_York',
            'maintenance_windows' => json_encode([
                ['weekday' => 6, 'start_local' => '23:30', 'duration_minutes' => 120],
            ], JSON_THROW_ON_ERROR),
        ];
        $this->assertTrue($service->at($overnight, CarbonImmutable::parse('2026-07-12T04:15:00Z'))['active']);

        $springForward = (object) [
            'maintenance_timezone' => 'America/New_York',
            'maintenance_windows' => json_encode([
                ['weekday' => 0, 'start_local' => '01:30', 'duration_minutes' => 120],
            ], JSON_THROW_ON_ERROR),
        ];
        $resolved = $service->at($springForward, CarbonImmutable::parse('2026-03-08T07:15:00Z'));
        $this->assertTrue($resolved['active']);
        $this->assertSame('America/New_York', $resolved['timezone']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $resolved['fingerprint']);
    }

    public function test_observability_api_is_authenticated_source_scoped_and_operator_gated(): void
    {
        $this->completeOnboarding();
        $this->getJson("/api/admin/integrations/sources/{$this->sourceId}/observability")->assertUnauthorized();

        $auditor = User::factory()->create(['role' => 'auditor']);
        $this->grantFacilityScope($auditor);
        $this->selectScope($auditor);
        $this->getJson("/api/admin/integrations/sources/{$this->sourceId}/observability")
            ->assertOk()
            ->assertJsonPath('data.contract.appendOnly', true)
            ->assertJsonPath('data.contract.missingEvidenceStatus', 'unknown');
        $this->postJson("/api/admin/integrations/sources/{$this->sourceId}/observations")->assertForbidden();

        $operator = User::factory()->create(['role' => 'integration_operator']);
        $this->grantFacilityScope($operator);
        $this->selectScope($operator);
        $response = $this->postJson("/api/admin/integrations/sources/{$this->sourceId}/observations")
            ->assertCreated()
            ->assertJsonPath('data.sourceId', $this->sourceId)
            ->assertJsonPath('data.status', 'unknown');
        $observationId = (int) $response->json('data.observationId');
        $this->assertDatabaseHas('integration.health_observations', [
            'health_observation_id' => $observationId,
            'recorded_by_user_id' => $operator->id,
            'collector_origin' => 'manual',
        ]);
        $this->assertDatabaseHas('integration.configuration_audits', [
            'action' => 'observed',
            'entity_type' => 'source_health',
            'entity_id' => $observationId,
        ]);
    }

    public function test_clinical_content_tripwire_and_append_only_history_fail_closed(): void
    {
        $this->completeOnboarding();
        $observation = app(SourceObservabilityService::class)->observe(
            $this->sourceId,
            CarbonImmutable::parse('2026-07-13T14:00:00Z'),
        );
        $row = (array) DB::table('integration.health_observations')
            ->where('health_observation_id', $observation['observationId'])
            ->firstOrFail();
        unset($row['health_observation_id']);
        $row['observation_uuid'] = (string) Str::uuid7();
        $row['batch_uuid'] = (string) Str::uuid7();
        $row['correlation_uuid'] = (string) Str::uuid7();
        $row['queue_state'] = json_encode(['diagnostic' => 'ZPHI-OBSERVABILITY-CANARY'], JSON_THROW_ON_ERROR);
        try {
            DB::transaction(fn () => DB::table('integration.health_observations')->insert($row));
            $this->fail('The database accepted clinical content in a health observation.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('clinical content is prohibited', $exception->getMessage());
        }

        try {
            DB::transaction(fn () => DB::table('integration.health_observations')
                ->where('health_observation_id', $observation['observationId'])
                ->delete());
            $this->fail('The append-only observation ledger accepted a delete.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
        try {
            DB::transaction(fn () => DB::table('integration.source_health_current')
                ->where('source_id', $this->sourceId)
                ->update(['projection_version' => 99]));
            $this->fail('The current projection accepted a non-monotonic update.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('advance monotonically', $exception->getMessage());
        }
    }

    public function test_scheduled_command_records_a_bounded_batch(): void
    {
        $this->completeOnboarding();
        $this->artisan('integrations:observe-source-health', ['--source-id' => [$this->sourceId], '--limit' => 1])
            ->expectsOutputToContain('1 recorded, 0 failed')
            ->assertSuccessful();
        $this->assertDatabaseCount('integration.health_observations', 1);
        $this->assertDatabaseHas('integration.health_observations', [
            'source_id' => $this->sourceId,
            'collector_origin' => 'scheduled',
            'recorded_by_user_id' => null,
        ]);
    }

    private function completeOnboarding(array $sloOverrides = []): object
    {
        $onboarding = app(SourceOnboardingService::class);
        $current = $onboarding->latest($this->sourceId);

        return $onboarding->revise(
            $this->sourceId,
            [
                'system_version' => '2026.1',
                'protocol_profile' => 'FHIR R4',
                'owner_name' => 'Integration Owner',
                'steward_name' => 'Data Steward',
                'network_route_key' => 'private-route-east',
                'data_classification' => 'restricted_phi',
                'permitted_purpose' => 'Support production operational flow coordination.',
                'phi_permission_basis' => 'Executed BAA and minimum necessary policy.',
                'retention_policy_key' => 'clinical-seven-year',
                'retention_days' => 2555,
                'credential_strategy' => 'oauth2',
                'conformance_status' => 'passed',
                'support_entitlement' => 'critical',
                'vendor_support_identifier' => 'SUPPORT-OBS-001',
                'maintenance_timezone' => 'America/New_York',
                'contacts' => [
                    ['role' => 'owner', 'name' => 'Integration Owner'],
                    ['role' => 'steward', 'name' => 'Data Steward'],
                    ['role' => 'escalation', 'name' => 'Command Center'],
                ],
                'maintenance_windows' => [
                    ['weekday' => 0, 'start_local' => '02:00', 'duration_minutes' => 60, 'purpose' => 'Vendor maintenance'],
                ],
                'slo_definition' => [
                    'evaluation_window_minutes' => 60,
                    'availability_percent' => 99.9,
                    'freshness_minutes' => 15,
                    'completeness_percent' => 99.9,
                    'latency_ms' => 5000,
                    'error_rate_percent' => 1,
                    'acknowledgement_seconds' => 30,
                    'reconciliation_variance_percent' => 1,
                    ...$sloOverrides,
                ],
            ],
            (int) $current->source_onboarding_version_id,
            null,
            'Complete the source SLO authority for observability testing.',
        );
    }

    private function ingestRun(
        CarbonImmutable $startedAt,
        string $status,
        int $received,
        int $succeeded,
        int $failed,
        int $skipped,
    ): int {
        return (int) DB::table('raw.ingest_runs')->insertGetId([
            'run_uuid' => (string) Str::uuid7(),
            'source_id' => $this->sourceId,
            'connector_key' => 'observability.test',
            'run_type' => 'fhir_poll',
            'status' => $status,
            'started_at' => $startedAt,
            'completed_at' => in_array($status, ['completed', 'failed'], true) ? $startedAt->addMinute() : null,
            'messages_received' => $received,
            'messages_succeeded' => $succeeded,
            'messages_failed' => $failed,
            'messages_skipped' => $skipped,
            'metadata' => '{}',
            'created_at' => $startedAt,
            'updated_at' => $startedAt,
        ], 'ingest_run_id');
    }

    private function watermark(CarbonImmutable $lastSuccessAt): void
    {
        DB::table('integration.connector_watermarks')->updateOrInsert([
            'source_id' => $this->sourceId,
            'connector_key' => 'observability.test',
            'scope_type' => 'source',
            'scope_key' => 'all',
            'watermark_kind' => 'last_success',
        ], [
            'watermark_value' => null,
            'last_success_at' => $lastSuccessAt,
            'metadata' => '{}',
            'created_at' => $lastSuccessAt,
            'updated_at' => $lastSuccessAt,
        ]);
    }

    private function canonicalEvent(int $runId, CarbonImmutable $receivedAt, CarbonImmutable $projectedAt): void
    {
        $uuid = (string) Str::uuid7();
        DB::table('integration.canonical_events')->insert([
            'event_id' => $uuid,
            'source_id' => $this->sourceId,
            'ingest_run_id' => $runId,
            'event_type' => 'test.observation',
            'occurred_at' => $receivedAt,
            'received_at' => $receivedAt,
            'payload' => '{}',
            'payload_hash' => hash('sha256', '{}'),
            'idempotency_key' => 'observability:'.$uuid,
            'projection_status' => 'projected',
            'projected_at' => $projectedAt,
            'metadata' => '{}',
            'created_at' => $receivedAt,
            'updated_at' => $projectedAt,
        ]);
    }

    private function selectScope(User $user): self
    {
        $this->actingAs($user)->put('/admin/active-scope', [
            'organization_id' => $this->organizationId,
            'facility_id' => $this->facilityId,
            'source_id' => $this->sourceId,
            'return_path' => '/integrations?tab=observability',
        ])->assertRedirect();

        return $this;
    }

    private function grantFacilityScope(User $user): void
    {
        DB::table('prod.user_access_scopes')->insert([
            'user_id' => $user->id,
            'organization_id' => null,
            'facility_id' => $this->facilityId,
            'granted_by_user_id' => null,
            'grant_reason' => 'Grant exact observability test facility scope.',
            'valid_from' => now()->subMinute(),
            'valid_until' => null,
            'revoked_at' => null,
            'revoked_by_user_id' => null,
            'revocation_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
