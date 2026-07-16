<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Healthcare\Services\SourceConfigurationVersionService;
use App\Integrations\Healthcare\Services\SourceLifecycleService;
use App\Integrations\Healthcare\Services\SourceOnboardingService;
use App\Integrations\Healthcare\Services\SourceStatusFacetService;
use App\Models\Auth\UserAccessScope;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * INT-LIFECYCLE — the six status facets are independently addressable and
 * separately surfaced without collapsing into one status.
 */
final class SourceStatusFacetTest extends TestCase
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
            'organization_key' => 'FACET_TEST_IDN',
            'name' => 'Facet Test IDN',
            'kind' => 'idn',
        ]);
        $facility = Facility::create([
            'organization_id' => $organization->organization_id,
            'facility_key' => 'FACET_TEST_FACILITY',
            'facility_name' => 'Facet Test Facility',
            'idn_role' => 'community_hospital',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);
        $this->organizationId = (int) $organization->organization_id;
        $this->facilityId = (int) $facility->facility_id;
        $this->sourceId = (int) DB::table('integration.sources')->insertGetId([
            'source_uuid' => (string) Str::uuid7(),
            'source_key' => 'facet.fhir.test',
            'organization_id' => $this->organizationId,
            'facility_id' => $this->facilityId,
            'tenant_key' => 'FACET_TEST_IDN',
            'facility_key' => 'FACET_TEST_FACILITY',
            'source_name' => 'Facet FHIR Test',
            'vendor' => 'Test Vendor',
            'system_class' => 'ehr',
            'environment' => 'production',
            'interface_type' => 'fhir_r4',
            'active_status' => 'testing',
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
            'Initialize source facet test configuration authority.',
            (string) Str::uuid7(),
        );
        app(SourceLifecycleService::class)->initialize(
            $this->sourceId,
            null,
            'Initialize source facet test lifecycle authority.',
        );
        app(SourceOnboardingService::class)->initialize($this->sourceId);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_new_source_starts_with_six_independent_facets_at_base_states(): void
    {
        $snapshot = app(SourceStatusFacetService::class)->snapshot($this->sourceId);

        // Six distinct blocks; none collapsed into another.
        $this->assertSame('validating', $snapshot['lifecycle']['state']);
        $this->assertTrue($snapshot['lifecycle']['governed']);
        $this->assertTrue($snapshot['protocolHealth']['readOnly']);
        $this->assertTrue($snapshot['dataFreshness']['readOnly']);
        $this->assertSame('not_started', $snapshot['conformance']['status']);
        $this->assertSame('none', $snapshot['contract']['status']);
        $this->assertSame('none', $snapshot['incident']['status']);
        $this->assertTrue($snapshot['incident']['operatorUpdatable']);

        // Every facet has a governing initial event.
        $this->assertSame(1, DB::table('integration.source_conformance_events')->where('source_id', $this->sourceId)->count());
        $this->assertSame(1, DB::table('integration.source_contract_events')->where('source_id', $this->sourceId)->count());
        $this->assertSame(1, DB::table('integration.source_incident_events')->where('source_id', $this->sourceId)->count());
    }

    public function test_facet_reads_require_view_and_mutations_require_operate_capability(): void
    {
        $reader = User::factory()->create(['role' => 'integration_approver', 'must_change_password' => false]);
        UserAccessScope::create([
            'user_id' => $reader->id,
            'facility_id' => $this->facilityId,
            'granted_by_user_id' => $reader->id,
            'grant_reason' => 'Assigned to the facet test facility integration review team.',
            'valid_from' => now()->subMinute(),
        ]);
        $operator = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);

        // A read-only role can view but not mutate the governed facets.
        $this->selectScope($reader)
            ->getJson("/api/admin/integrations/sources/{$this->sourceId}/status-facets")
            ->assertOk()
            ->assertJsonPath('data.conformance.status', 'not_started');
        $this->selectScope($reader)
            ->postJson("/api/admin/integrations/sources/{$this->sourceId}/status-facets/conformance", [
                'status' => 'passed',
                'profile_key' => 'fhir-r4-us-core',
                'reason' => 'Attempt an unauthorized conformance transition.',
            ])->assertForbidden();

        // The operator can record each facet independently, append-only.
        $this->selectScope($operator)
            ->postJson("/api/admin/integrations/sources/{$this->sourceId}/status-facets/conformance", [
                'status' => 'passed',
                'profile_key' => 'fhir-r4-us-core',
                'profile_version' => '6.1.0',
                'reason' => 'Vendor conformance verified against the declared profile.',
            ])->assertCreated()->assertJsonPath('data.status', 'passed');
        $this->selectScope($operator)
            ->postJson("/api/admin/integrations/sources/{$this->sourceId}/status-facets/incident", [
                'status' => 'open',
                'reason' => 'Operator opened an incident for the degraded feed.',
            ])->assertCreated()->assertJsonPath('data.status', 'open');

        $snapshot = app(SourceStatusFacetService::class)->snapshot($this->sourceId);
        $this->assertSame('passed', $snapshot['conformance']['status']);
        $this->assertSame('fhir-r4-us-core', $snapshot['conformance']['profileKey']);
        $this->assertSame('open', $snapshot['incident']['status']);
        // Conformance change did not touch the contract facet.
        $this->assertSame('none', $snapshot['contract']['status']);
    }

    public function test_contract_facet_is_evidence_pointer_only_and_active_requires_a_pointer(): void
    {
        $facets = app(SourceStatusFacetService::class);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $facets->recordContract(
            $this->sourceId,
            'active',
            null,
            'Attempt to activate a contract without an evidence pointer.',
            null,
        );
    }

    public function test_incident_can_link_an_existing_slo_breach_reference(): void
    {
        $definitionId = (int) DB::table('integration.source_slo_definitions')
            ->where('source_id', $this->sourceId)->value('source_slo_definition_id');
        $breachId = (int) DB::table('integration.slo_breaches')->insertGetId([
            'breach_uuid' => (string) Str::uuid7(),
            'source_id' => $this->sourceId,
            'source_slo_definition_id' => $definitionId,
            'metric_key' => 'availability',
            'opened_health_observation_id' => $this->seedBreachObservation($definitionId),
            'opened_health_observation_metric_id' => $this->lastBreachedMetricId(),
            'opened_at' => now(),
            'created_at' => now(),
        ], 'slo_breach_id');
        $breachUuid = (string) DB::table('integration.slo_breaches')->where('slo_breach_id', $breachId)->value('breach_uuid');

        $result = app(SourceStatusFacetService::class)->recordIncident(
            $this->sourceId,
            'open',
            $breachUuid,
            'Operator linked the incident to the availability breach.',
            null,
        );
        $this->assertSame('open', $result['status']);
        $this->assertDatabaseHas('integration.source_incident_events', [
            'source_id' => $this->sourceId,
            'incident_status' => 'open',
            'slo_breach_id' => $breachId,
        ]);
    }

    public function test_facet_projection_and_histories_reject_update_and_delete(): void
    {
        app(SourceStatusFacetService::class)->recordConformance(
            $this->sourceId,
            'in_progress',
            null,
            null,
            'Conformance testing is under way with the vendor.',
            null,
        );

        foreach ([
            ['integration.source_conformance_events', 'source_conformance_event_id'],
            ['integration.source_contract_events', 'source_contract_event_id'],
            ['integration.source_incident_events', 'source_incident_event_id'],
        ] as [$table, $key]) {
            $id = (int) DB::table($table)->where('source_id', $this->sourceId)->orderByDesc($key)->value($key);
            try {
                DB::transaction(fn () => DB::table($table)->where($key, $id)
                    ->update(['reason' => 'Tampered reason for append-only test.']));
                $this->fail("{$table} accepted an update.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            }
        }
    }

    public function test_status_facet_ledgers_reject_clinical_content(): void
    {
        try {
            DB::transaction(fn () => DB::table('integration.source_conformance_events')->insert([
                'event_uuid' => (string) Str::uuid7(),
                'source_id' => $this->sourceId,
                'previous_event_id' => null,
                'conformance_status' => 'in_progress',
                // An HL7 v2 canary in the profile key must be rejected at the DB.
                'profile_key' => 'mshhl7canary',
                'profile_version' => null,
                'evidence_reference_sha256' => null,
                'reason' => "MSH|^~\\&|SENDER|FAC|RCV|FAC|202601010000||ADT^A01|1|P|2.5\r",
                'actor_user_id' => null,
                'occurred_at' => now(),
                'created_at' => now(),
            ]));
            $this->fail('The conformance ledger accepted clinical content.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('clinical', strtolower($exception->getMessage()));
        }
    }

    private function seedBreachObservation(int $definitionId): int
    {
        $observationId = (int) DB::table('integration.health_observations')->insertGetId([
            'observation_uuid' => (string) Str::uuid7(),
            'batch_uuid' => (string) Str::uuid7(),
            'correlation_uuid' => (string) Str::uuid7(),
            'source_id' => $this->sourceId,
            'source_configuration_version_id' => (int) DB::table('integration.sources')->where('source_id', $this->sourceId)->value('current_configuration_version_id'),
            'source_onboarding_version_id' => (int) DB::table('integration.source_onboarding_versions')->where('source_id', $this->sourceId)->value('source_onboarding_version_id'),
            'source_slo_definition_id' => $definitionId,
            'observation_status' => 'degraded',
            'protocol_status' => 'degraded',
            'window_started_at' => now()->subMinutes(5),
            'window_ended_at' => now(),
            'observed_at' => now(),
            'freshness_expires_at' => now()->addMinutes(5),
            'collector_origin' => 'manual',
            'evidence_sha256' => hash('sha256', 'facet-breach-fixture'),
            'created_at' => now(),
        ], 'health_observation_id');
        DB::table('integration.health_observation_metrics')->insert([
            'health_observation_id' => $observationId,
            'metric_key' => 'availability',
            'metric_status' => 'breached',
            'measured_value' => 80,
            'target_value' => 99.9,
            'comparator' => 'gte',
            'unit' => 'percent',
            'sample_count' => 10,
            'evidence_code' => 'availability_below_target',
            'details' => '{}',
            'created_at' => now(),
        ]);

        return $observationId;
    }

    private function lastBreachedMetricId(): int
    {
        return (int) DB::table('integration.health_observation_metrics')
            ->where('metric_status', 'breached')
            ->orderByDesc('health_observation_metric_id')
            ->value('health_observation_metric_id');
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
}
