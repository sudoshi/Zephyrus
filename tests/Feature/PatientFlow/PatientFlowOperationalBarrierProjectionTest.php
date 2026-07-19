<?php

namespace Tests\Feature\PatientFlow;

use App\Models\User;
use App\Services\PatientFlow\FlowEventNormalizer;
use App\Services\PatientFlow\FlowEventRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatientFlowOperationalBarrierProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_occupancy_projects_only_verified_exact_or_unit_wide_operational_barriers(): void
    {
        [$user, $unitId, $prodEncounterId, $flowPatientRef, $flowEncounterRef] = $this->seedFlowPatient();
        $asOf = '2026-06-25T02:00:00Z';

        $patientBarrierId = DB::table('prod.barriers')->insertGetId([
            'encounter_id' => $prodEncounterId,
            'unit_id' => $unitId,
            'category' => 'logistical',
            'reason_code' => 'bed_assignment_pending',
            'description' => 'Receiving bed assignment is still pending.',
            'owner' => 'Capacity RN',
            'status' => 'open',
            'opened_at' => '2026-06-25 01:20:00+00',
            'created_at' => '2026-06-25 01:20:00+00',
            'updated_at' => '2026-06-25 01:30:00+00',
            'is_deleted' => false,
        ], 'barrier_id');
        $unitBarrierId = DB::table('prod.barriers')->insertGetId([
            'encounter_id' => null,
            'unit_id' => $unitId,
            'category' => 'placement',
            'reason_code' => 'unit_acceptance_pause',
            'description' => 'Unit-wide placement acceptance is paused.',
            'owner' => 'House Supervisor',
            'status' => 'open',
            'opened_at' => '2026-06-25 01:25:00+00',
            'created_at' => '2026-06-25 01:25:00+00',
            'updated_at' => '2026-06-25 01:35:00+00',
            'is_deleted' => false,
        ], 'barrier_id');

        $otherEncounterId = DB::table('prod.encounters')->insertGetId([
            'patient_ref' => 'OTHER-PATIENT',
            'unit_id' => $unitId,
            'status' => 'active',
            'admitted_at' => '2026-06-25 00:30:00+00',
            'created_at' => '2026-06-25 00:30:00+00',
            'updated_at' => '2026-06-25 00:30:00+00',
            'is_deleted' => false,
        ], 'encounter_id');
        DB::table('prod.barriers')->insert([
            'encounter_id' => $otherEncounterId,
            'unit_id' => $unitId,
            'category' => 'medical',
            'description' => 'This belongs to another patient in the same unit.',
            'status' => 'open',
            'opened_at' => '2026-06-25 01:10:00+00',
            'created_at' => '2026-06-25 01:10:00+00',
            'updated_at' => '2026-06-25 01:10:00+00',
            'is_deleted' => false,
        ]);
        DB::table('prod.barriers')->insert([
            'encounter_id' => $prodEncounterId,
            'unit_id' => $unitId,
            'category' => 'social',
            'description' => 'Resolved before the replay time.',
            'status' => 'resolved',
            'opened_at' => '2026-06-25 00:45:00+00',
            'resolved_at' => '2026-06-25 01:30:00+00',
            'created_at' => '2026-06-25 00:45:00+00',
            'updated_at' => '2026-06-25 01:30:00+00',
            'is_deleted' => false,
        ]);

        $transportId = $this->insertTransport([
            'status' => 'assigned',
            'patient_ref' => $flowPatientRef,
            'encounter_ref' => $flowEncounterRef,
            'needed_at' => '2026-06-25 01:59:59+00',
        ]);
        $this->insertTransport([
            'status' => 'assigned',
            'patient_ref' => $flowPatientRef,
            'encounter_ref' => $flowEncounterRef,
            'needed_at' => '2026-06-25 03:00:00+00',
        ]);
        $this->insertTransport([
            'status' => 'completed',
            'patient_ref' => $flowPatientRef,
            'encounter_ref' => $flowEncounterRef,
            'needed_at' => '2026-06-25 01:00:00+00',
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/patient-flow/occupancy?asOf={$asOf}")
            ->assertOk()
            ->json();

        $this->assertSame(1, $response['summary']['active']);
        $this->assertSame(0, $response['summary']['duration_risks']);
        $this->assertSame('prod.barriers', $response['operational_barrier_sources'][0]['source_table']);
        $this->assertTrue($response['operational_barrier_sources'][0]['available']);
        $this->assertSame('prod.transport_requests', $response['operational_barrier_sources'][1]['source_table']);
        $this->assertTrue($response['operational_barrier_sources'][1]['available']);
        $this->assertSame(
            1,
            $response['summary']['transport_delays'],
            json_encode([
                'sources' => $response['operational_barrier_sources'],
                'timers' => $response['occupancy'][0]['timers'],
            ], JSON_THROW_ON_ERROR),
        );
        $this->assertSame('ok', $response['occupancy'][0]['duration_risk']['status']);
        $this->assertFalse($response['occupancy'][0]['duration_risk']['verified']);
        $this->assertSame(
            3,
            $response['summary']['verified_barriers'],
            json_encode($response['operational_barrier_sources'], JSON_THROW_ON_ERROR),
        );
        $occupancy = $response['occupancy'][0];
        $this->assertCount(3, $occupancy['verified_barriers']);
        $this->assertContains('rtdc_logistical_barrier', $occupancy['barrier_codes']);
        $this->assertContains('rtdc_placement_barrier', $occupancy['barrier_codes']);
        $this->assertContains('transport_request_overdue', $occupancy['barrier_codes']);
        $this->assertNotContains('rtdc_medical_barrier', $occupancy['barrier_codes']);
        $this->assertNotContains('rtdc_social_barrier', $occupancy['barrier_codes']);
        $this->assertNotContains('long_stay_capacity_risk', $occupancy['barrier_codes']);

        $verifiedBySourceId = collect($occupancy['verified_barriers'])
            ->keyBy(fn (array $barrier): string => $barrier['provenance']['source_table'].':'.$barrier['provenance']['source_record_id']);
        $this->assertSame(
            'flow_encounter_link',
            $verifiedBySourceId["prod.barriers:{$patientBarrierId}"]['verification']['matched_by'],
        );
        $this->assertSame(
            'unit_id',
            $verifiedBySourceId["prod.barriers:{$unitBarrierId}"]['verification']['matched_by'],
        );
        $this->assertSame(
            'encounter_ref',
            $verifiedBySourceId["prod.transport_requests:{$transportId}"]['verification']['matched_by'],
        );
        $this->assertSame(
            'verified',
            $verifiedBySourceId["prod.transport_requests:{$transportId}"]['verification']['status'],
        );
        $transportBarrier = $verifiedBySourceId["prod.transport_requests:{$transportId}"];
        $transportTimer = collect($occupancy['timers'])->first(
            fn (array $timer): bool => ($timer['provenance']['source_table'] ?? null) === 'prod.transport_requests'
                && (int) ($timer['provenance']['source_record_id'] ?? 0) === $transportId,
        );
        $this->assertNotNull($transportTimer);
        $this->assertEqualsWithDelta(-1 / 60, $transportTimer['minutes_remaining'], 0.000001);
        $this->assertSame('delayed', $transportBarrier['status']);
        $this->assertStringContainsString('1 sec overdue', $transportBarrier['reason']);

        $redacted = $this->actingAs(User::factory()->create(['role' => 'executive']))
            ->getJson("/api/patient-flow/occupancy?asOf={$asOf}")
            ->assertOk()
            ->assertJsonPath('lens.patient_dots', 'none')
            ->json('occupancy.0');
        $encoded = json_encode($redacted, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Receiving bed assignment is still pending.', $encoded);
        $this->assertStringNotContainsString('Capacity RN', $encoded);
        $verifiedEncoded = json_encode($redacted['verified_barriers'], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($flowPatientRef, $verifiedEncoded);
        $this->assertStringNotContainsString($flowEncounterRef, $verifiedEncoded);
    }

    public function test_elapsed_long_stay_is_a_non_verified_duration_risk_not_a_barrier(): void
    {
        [$user] = $this->seedFlowPatient();

        $occupancy = $this->actingAs($user)
            ->getJson('/api/patient-flow/occupancy?asOf=2026-06-26T00:00:00Z')
            ->assertOk()
            ->assertJsonPath('summary.active', 1)
            // F-3 ruling: an inferred duration-only risk caps at watch — it
            // never counts as delayed (coral) without verification.
            ->assertJsonPath('summary.delayed', 0)
            ->assertJsonPath('summary.watch', 1)
            ->assertJsonPath('summary.duration_risks', 1)
            ->assertJsonPath('summary.verified_barriers', 0)
            ->assertJsonPath('summary.top_barriers', [])
            ->assertJsonPath('occupancy.0.primary_status', 'watch')
            ->assertJsonPath('occupancy.0.blockers', [])
            ->assertJsonPath('occupancy.0.barrier_codes', [])
            ->assertJsonPath('occupancy.0.barrier_reasons', [])
            ->assertJsonPath('occupancy.0.verified_barriers', [])
            ->assertJsonPath('occupancy.0.duration_risk.status', 'watch')
            ->assertJsonPath('occupancy.0.duration_risk.classification', 'duration_risk')
            ->assertJsonPath('occupancy.0.duration_risk.risk_code', 'long_stay_capacity_risk')
            ->assertJsonPath('occupancy.0.duration_risk.verified', false)
            ->assertJsonPath('occupancy.0.duration_risk.verification.status', 'inferred')
            ->json('occupancy.0');

        $stayTimer = collect($occupancy['timers'])->firstWhere('kind', 'stay');
        $this->assertNull($stayTimer['barrier_code']);
        $this->assertSame('long_stay_capacity_risk', $stayTimer['risk_code']);
        $this->assertSame('duration_risk', $stayTimer['classification']);
        $this->assertFalse($stayTimer['verified']);
        // The amber cap itself (F-3): unverified stay pressure is watch, never delayed.
        $this->assertSame('watch', $stayTimer['status']);
    }

    /** @return array{User, int, int, string, string} */
    private function seedFlowPatient(): array
    {
        $facilityCode = 'ZEPHYRUS-500';
        $this->assertSame(0, Artisan::call('facility:import-catalog', [
            'path' => base_path('tests/Fixtures/facility/model_catalog_fixture.json'),
            '--facility-code' => $facilityCode,
            '--facility-name' => 'Navigator Test Facility',
            '--source-name' => 'navigator-fixture-catalog',
            '--map-operational' => true,
        ]));

        $raw = implode("\r", [
            'MSH|^~\\&|EHR|AMC|FLOW|AMC|20260625010100||ADT^A01|BARRIER1|P|2.5.1',
            'EVN|A01|20260625010100',
            'PID|||SYN000001^^^AMC^MR||FLOW^SYN000001',
            'PV1||I|TICU^TICU-R001^TICU-B001^ZEPHYRUS||||99001^ATTENDING^SYNTHETIC|||critical_care|||||||||VIS000001^^^AMC^VN',
            '',
        ]);
        $event = app(FlowEventNormalizer::class)->normalize($raw);
        app(FlowEventRepository::class)->upsertNormalizedEvent($event, null, null, null, $facilityCode);
        $flowPatientRef = (string) $event['patient_id'];
        $flowEncounterRef = (string) $event['encounter_id'];

        $unitId = (int) DB::table('prod.units')->where('abbreviation', 'TICU')->value('unit_id');
        $this->assertGreaterThan(0, $unitId);
        $prodEncounterId = DB::table('prod.encounters')->insertGetId([
            'patient_ref' => $flowPatientRef,
            'unit_id' => $unitId,
            'status' => 'active',
            'admitted_at' => '2026-06-25 01:01:00+00',
            'created_at' => '2026-06-25 01:01:00+00',
            'updated_at' => '2026-06-25 01:01:00+00',
            'is_deleted' => false,
        ], 'encounter_id');
        DB::table('flow_core.encounters')
            ->where('encounter_ref', $flowEncounterRef)
            ->update(['prod_encounter_id' => $prodEncounterId]);

        return [
            User::factory()->create(['role' => 'bed_manager']),
            $unitId,
            $prodEncounterId,
            $flowPatientRef,
            $flowEncounterRef,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function insertTransport(array $overrides): int
    {
        return DB::table('prod.transport_requests')->insertGetId(array_merge([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'inpatient',
            'priority' => 'urgent',
            'status' => 'assigned',
            'patient_ref' => 'SYN000001',
            'encounter_ref' => 'VIS000001',
            'origin' => 'TICU',
            'destination' => 'RAD',
            'transport_mode' => 'stretcher',
            'requested_at' => '2026-06-25 01:10:00+00',
            'needed_at' => '2026-06-25 01:40:00+00',
            'assigned_team' => 'Summit Patient Transport',
            'created_at' => '2026-06-25 01:10:00+00',
            'updated_at' => '2026-06-25 01:30:00+00',
            'is_deleted' => false,
        ], $overrides), 'transport_request_id');
    }
}
