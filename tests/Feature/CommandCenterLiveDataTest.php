<?php

// tests/Feature/CommandCenterLiveDataTest.php

namespace Tests\Feature;

use App\Services\CommandCenterDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Derived-value assertions for the live-DB CommandCenterDataService.
 *
 * Each test inserts ONLY the rows its assertion needs, calls build(), and
 * checks the derived value matches what was inserted.  No dependency on
 * CommandCenterDemoSeeder — fixtures are self-contained so assertions are
 * exact.
 *
 * RefreshDatabase runs migrations on zephyrus_test and wraps each test in a
 * transaction that rolls back on teardown, so tests are fully isolated.
 */
class CommandCenterLiveDataTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers — fast fixture insertion via raw DB (avoids Eloquent overhead
    // and lets us insert only the columns each test needs)
    // -----------------------------------------------------------------------

    /** Insert a minimal prod.units row and return its unit_id. */
    private function insertUnit(string $name, string $type = 'med_surg'): int
    {
        static $seq = 0;
        $seq++;

        return (int) DB::table('prod.units')->insertGetId([
            'name' => $name,
            'abbreviation' => strtoupper(substr(preg_replace('/\s+/', '', $name), 0, 4)).$seq,
            'type' => $type,
            'staffed_bed_count' => 30,
            'ratio_floor' => 4,
            'access_standard_minutes' => 120,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'unit_id');
    }

    /** Insert a prod.census_snapshot row for the given unit. */
    private function insertSnapshot(
        int $unitId,
        int $staffed,
        int $occupied,
        int $available,
        int $blocked,
        int $acuityAdjusted = 0,
        ?Carbon $capturedAt = null
    ): void {
        DB::table('prod.census_snapshots')->insert([
            'unit_id' => $unitId,
            'captured_at' => ($capturedAt ?? now())->toDateTimeString(),
            'staffed_beds' => $staffed,
            'occupied' => $occupied,
            'available' => $available,
            'blocked' => $blocked,
            'acuity_adjusted_capacity' => $acuityAdjusted > 0 ? $acuityAdjusted : $staffed,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Insert a minimal prod.rtdc_predictions row. */
    private function insertPrediction(
        int $unitId,
        int $definite,
        int $probable,
        int $possible,
        int $demandEd,
        int $demandExpected,
        float $dischargesWeighted = 0,
        string $horizon = 'by_2pm',
        ?string $serviceDate = null
    ): void {
        DB::table('prod.rtdc_predictions')->insert([
            'unit_id' => $unitId,
            'service_date' => $serviceDate ?? Carbon::today()->toDateString(),
            'horizon' => $horizon,
            'discharges_definite' => $definite,
            'discharges_probable' => $probable,
            'discharges_possible' => $possible,
            'discharges_weighted' => $dischargesWeighted > 0 ? $dischargesWeighted : round($definite + $probable * 0.7, 2),
            'demand_ed' => $demandEd,
            'demand_or' => 0,
            'demand_transfer' => 0,
            'demand_direct' => 0,
            'demand_expected' => $demandExpected,
            'capacity_now' => 5,
            'bed_need' => max(0, $demandExpected - 5),
            'status' => 'open',
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Set up the minimal OR domain reference data (location, specialty, provider,
     * service, room, case status, ASA rating, case type, case class, patient class)
     * and return an array of the IDs needed to insert or_cases rows.
     *
     * @return array{locId:int,roomId:int,surgeonId:int,serviceId:int,asaId:int,caseTypeId:int,caseClassId:int,patientClassId:int,cancelStatusId:int,schedStatusId:int}
     */
    private function setupOrDomain(): array
    {
        $locId = (int) DB::table('prod.locations')->insertGetId([
            'name' => 'Test OR Suite', 'abbreviation' => 'TOR', 'type' => 'surgical',
            'pos_type' => 'inpatient', 'active_status' => true,
            'is_deleted' => false, 'created_at' => now(), 'updated_at' => now(),
        ], 'location_id');

        $specId = (int) DB::table('prod.specialties')->insertGetId([
            'name' => 'General Surgery', 'code' => 'TGSURG', 'active_status' => true,
            'is_deleted' => false, 'created_at' => now(), 'updated_at' => now(),
        ], 'specialty_id');

        $surgeonId = (int) DB::table('prod.providers')->insertGetId([
            'name' => 'Dr. Test', 'npi' => '9999999999', 'specialty_id' => $specId,
            'type' => 'surgeon', 'active_status' => true,
            'is_deleted' => false, 'created_at' => now(), 'updated_at' => now(),
        ], 'provider_id');

        $serviceId = (int) DB::table('prod.services')->insertGetId([
            'name' => 'Test Surgery', 'code' => 'TSURG', 'active_status' => true,
            'is_deleted' => false, 'created_at' => now(), 'updated_at' => now(),
        ], 'service_id');

        $roomId = (int) DB::table('prod.rooms')->insertGetId([
            'location_id' => $locId, 'name' => 'OR-T1', 'type' => 'OR',
            'active_status' => true, 'is_deleted' => false,
            'created_at' => now(), 'updated_at' => now(),
        ], 'room_id');

        // Case statuses (5 canonical).
        foreach ([
            [5, 'Cancelled', 'CANC'],
            [1, 'Scheduled', 'SCHED'],
            [2, 'In Progress', 'INPROG'],
            [3, 'Delayed', 'DELAY'],
            [4, 'Completed', 'COMP'],
        ] as [$sid, $name, $code]) {
            DB::table('prod.case_statuses')->updateOrInsert(
                ['status_id' => $sid],
                ['name' => $name, 'code' => $code, 'active_status' => true,
                    'is_deleted' => false, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $asaId = (int) DB::table('prod.asa_ratings')->insertGetId([
            'name' => 'ASA II', 'code' => 'ASA2T', 'description' => 'Test',
            'is_deleted' => false, 'created_at' => now(), 'updated_at' => now(),
        ], 'asa_id');

        $caseTypeId = (int) DB::table('prod.case_types')->insertGetId([
            'name' => 'Elective', 'code' => 'ELECT', 'active_status' => true,
            'is_deleted' => false, 'created_at' => now(), 'updated_at' => now(),
        ], 'case_type_id');

        $caseClassId = (int) DB::table('prod.case_classes')->insertGetId([
            'name' => 'Inpatient', 'code' => 'INPT', 'active_status' => true,
            'is_deleted' => false, 'created_at' => now(), 'updated_at' => now(),
        ], 'case_class_id');

        $patientClassId = (int) DB::table('prod.patient_classes')->insertGetId([
            'name' => 'Inpatient', 'code' => 'INPAT', 'active_status' => true,
            'is_deleted' => false, 'created_at' => now(), 'updated_at' => now(),
        ], 'patient_class_id');

        $cancelStatusId = (int) DB::table('prod.case_statuses')->where('code', 'CANC')->value('status_id');
        $schedStatusId = (int) DB::table('prod.case_statuses')->where('code', 'SCHED')->value('status_id');

        return compact(
            'locId', 'roomId', 'surgeonId', 'serviceId',
            'asaId', 'caseTypeId', 'caseClassId', 'patientClassId',
            'cancelStatusId', 'schedStatusId'
        );
    }

    /** Insert a prod.or_cases row using the OR domain IDs returned by setupOrDomain(). */
    private function insertOrCase(array $or, string $surgeryDate, int $statusId, string $patientId = 'SIM-X'): int
    {
        return (int) DB::table('prod.or_cases')->insertGetId([
            'patient_id' => $patientId,
            'surgery_date' => $surgeryDate,
            'room_id' => $or['roomId'],
            'location_id' => $or['locId'],
            'primary_surgeon_id' => $or['surgeonId'],
            'case_service_id' => $or['serviceId'],
            'scheduled_start_time' => $surgeryDate.' 08:00:00',
            'scheduled_duration' => 120,
            'record_create_date' => now()->subDays(3),
            'status_id' => $statusId,
            'asa_rating_id' => $or['asaId'],
            'case_type_id' => $or['caseTypeId'],
            'case_class_id' => $or['caseClassId'],
            'patient_class_id' => $or['patientClassId'],
            'safety_status' => 'Normal',
            'journey_progress' => 0,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'case_id');
    }

    // -----------------------------------------------------------------------
    // 1. Shape test — build() must return the correct 10 top-level keys
    //    and every metric status must be a valid enum value, even on an
    //    empty DB (no seed data).
    // -----------------------------------------------------------------------

    public function test_build_returns_full_valid_shape_on_empty_db(): void
    {
        $data = app(CommandCenterDataService::class)->build();

        $topKeys = [
            'generatedAtIso', 'strain', 'heroMetrics', 'capacity', 'flow',
            'outcomes', 'forecast', 'forecastDetail', 'unitCensus', 'objectives',
        ];
        foreach ($topKeys as $key) {
            $this->assertArrayHasKey($key, $data, "Missing top-level key: {$key}");
        }

        $validStatuses = ['critical', 'warning', 'success', 'info', 'neutral'];
        foreach ($data['heroMetrics'] as $metric) {
            $this->assertContains(
                $metric['status'], $validStatuses,
                "heroMetric '{$metric['key']}' has invalid status '{$metric['status']}'"
            );
        }

        // Strain level must be 0–4 integer.
        $this->assertIsInt($data['strain']['level']);
        $this->assertGreaterThanOrEqual(0, $data['strain']['level']);
        $this->assertLessThanOrEqual(4, $data['strain']['level']);
    }

    // -----------------------------------------------------------------------
    // 2. House occupancy is derived from census_snapshots, not hardcoded.
    // -----------------------------------------------------------------------

    public function test_hero_occupancy_computed_from_census_snapshots(): void
    {
        // 20 staffed, 18 occupied => 90% occupancy.
        $unitId = $this->insertUnit('Test East', 'med_surg');
        $this->insertSnapshot($unitId, staffed: 20, occupied: 18, available: 2, blocked: 0);

        $data = app(CommandCenterDataService::class)->build();

        $occupancy = collect($data['heroMetrics'])->firstWhere('key', 'occupancy');
        $this->assertNotNull($occupancy);
        $this->assertSame(90, $occupancy['value'],
            'Occupancy should be 90% (18/20 = 90)');
    }

    // -----------------------------------------------------------------------
    // 3. PDSA active count matches inserted active pdsa_cycles.
    // -----------------------------------------------------------------------

    public function test_outcomes_pdsa_active_equals_inserted_active_cycles(): void
    {
        // 3 active, 1 planned, 2 completed → pdsa_active should be 4 (active+planned).
        foreach (['active', 'active', 'active'] as $status) {
            DB::table('prod.pdsa_cycles')->insert([
                'title' => "Cycle {$status}",
                'unit_id' => null,
                'status' => $status,
                'owner' => 'test',
                'objective' => null,
                'started_at' => now()->subDays(5),
                'completed_at' => null,
                'is_deleted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('prod.pdsa_cycles')->insert([
            'title' => 'Cycle planned',
            'unit_id' => null,
            'status' => 'planned',
            'owner' => 'test',
            'objective' => null,
            'started_at' => null,
            'completed_at' => null,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['completed', 'completed'] as $status) {
            DB::table('prod.pdsa_cycles')->insert([
                'title' => "Cycle {$status}",
                'unit_id' => null,
                'status' => $status,
                'owner' => 'test',
                'objective' => null,
                'started_at' => now()->subDays(10),
                'completed_at' => now()->subDays(1),
                'is_deleted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $data = app(CommandCenterDataService::class)->build();

        $pdsaMetric = collect($data['outcomes']['metrics'])->firstWhere('key', 'pdsa_active');
        $this->assertNotNull($pdsaMetric);
        $this->assertSame(4, $pdsaMetric['value'],
            'pdsa_active should count active+planned cycles (3+1=4)');
    }

    // -----------------------------------------------------------------------
    // 4. Forecast pred_discharges = sum(definite + probable) from predictions.
    // -----------------------------------------------------------------------

    public function test_forecast_pred_discharges_matches_inserted_predictions(): void
    {
        $u1 = $this->insertUnit('Unit A', 'med_surg');
        $u2 = $this->insertUnit('Unit B', 'icu');

        // Unit A: definite=3, probable=5 → contribution = 8
        $this->insertPrediction($u1, definite: 3, probable: 5, possible: 2, demandEd: 2, demandExpected: 4);
        // Unit B: definite=2, probable=4 → contribution = 6
        $this->insertPrediction($u2, definite: 2, probable: 4, possible: 1, demandEd: 1, demandExpected: 3);

        $data = app(CommandCenterDataService::class)->build();

        $predDc = collect($data['forecast']['metrics'])->firstWhere('key', 'pred_discharges');
        $this->assertNotNull($predDc);
        $this->assertSame(14, $predDc['value'],
            'pred_discharges should be 14 (3+5 + 2+4)');
    }

    // -----------------------------------------------------------------------
    // 5. OR cancellations = count of Cancelled cases today.
    // -----------------------------------------------------------------------

    public function test_flow_or_cancellations_matches_cancelled_cases_today(): void
    {
        $or = $this->setupOrDomain();
        $today = Carbon::today()->toDateString();
        $yesterday = Carbon::yesterday()->toDateString();

        // 3 Cancelled cases today.
        foreach (range(1, 3) as $i) {
            $this->insertOrCase($or, $today, $or['cancelStatusId'], "SIM-CXL-{$i}");
        }

        // 1 Cancelled case yesterday — must NOT appear in today's count.
        $this->insertOrCase($or, $yesterday, $or['cancelStatusId'], 'SIM-CXL-YEST');

        $data = app(CommandCenterDataService::class)->build();

        $orSubgroup = collect($data['flow']['subgroups'])->firstWhere('key', 'or');
        $this->assertNotNull($orSubgroup);
        $cancellations = collect($orSubgroup['metrics'])->firstWhere('key', 'cancellations');
        $this->assertNotNull($cancellations);
        $this->assertSame(3, $cancellations['value'],
            "cancellations should be 3 (today's only, not yesterday's)");
    }

    // -----------------------------------------------------------------------
    // 6. Every metric status in heroMetrics is a valid enum value with data.
    // -----------------------------------------------------------------------

    public function test_every_hero_metric_status_is_valid_enum_with_data(): void
    {
        $unitId = $this->insertUnit('Full Unit', 'med_surg');
        $this->insertSnapshot($unitId, staffed: 30, occupied: 28, available: 2, blocked: 0);

        $data = app(CommandCenterDataService::class)->build();

        $valid = ['critical', 'warning', 'success', 'info', 'neutral'];
        foreach ($data['heroMetrics'] as $metric) {
            $this->assertContains($metric['status'], $valid,
                "heroMetric '{$metric['key']}' status '{$metric['status']}' is not a valid enum value");
        }
        // Occupancy at 28/30 = 93% → should be critical.
        $occ = collect($data['heroMetrics'])->firstWhere('key', 'occupancy');
        $this->assertSame('critical', $occ['status'],
            '93% occupancy should yield critical status');
    }

    // -----------------------------------------------------------------------
    // 7. net_beds hero metric equals available − pendingAdmits.
    // -----------------------------------------------------------------------

    public function test_hero_net_beds_equals_available_minus_pending_admits(): void
    {
        $unitId = $this->insertUnit('Net Unit', 'med_surg');
        // 30 staffed, 24 occupied, 4 available, 2 blocked.
        $this->insertSnapshot($unitId, staffed: 30, occupied: 24, available: 4, blocked: 2);

        // Insert 2 pending bed requests.
        foreach (range(1, 2) as $i) {
            DB::table('prod.bed_requests')->insert([
                'patient_ref' => "sim-br-test-{$i}",
                'source' => 'ed',
                'acuity_tier' => 2,
                'isolation_required' => 'none',
                'required_unit_type' => 'any',
                'status' => 'pending',
                'is_deleted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $data = app(CommandCenterDataService::class)->build();

        $netBeds = collect($data['heroMetrics'])->firstWhere('key', 'net_beds');
        $this->assertNotNull($netBeds);
        // 4 available − 2 pending = 2.
        $this->assertSame(2, $netBeds['value'],
            'net_beds should be 4 available − 2 pending admits = 2');
    }

    // -----------------------------------------------------------------------
    // 8. ED boarding count comes from ed_visits with admitted+null bed_assigned.
    // -----------------------------------------------------------------------

    public function test_ed_boarding_count_from_ed_visits(): void
    {
        $unitId = $this->insertUnit('ED Unit', 'ed');

        // 3 boarding (admitted, no bed_assigned_at).
        foreach (range(1, 3) as $i) {
            DB::table('prod.ed_visits')->insert([
                'patient_ref' => "sim-board-{$i}",
                'arrived_at' => now()->subHours(6),
                'triaged_at' => now()->subHours(5),
                'esi_level' => 2,
                'provider_seen_at' => now()->subHours(4),
                'disposition' => 'admitted',
                'admit_decision_at' => now()->subHours(3),
                'bed_assigned_at' => null,  // still boarding
                'departed_at' => null,
                'unit_id' => $unitId,
                'is_deleted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 1 admitted with bed already assigned (not boarding).
        DB::table('prod.ed_visits')->insert([
            'patient_ref' => 'sim-placed-1',
            'arrived_at' => now()->subHours(5),
            'triaged_at' => now()->subHours(4),
            'esi_level' => 3,
            'provider_seen_at' => now()->subHours(3),
            'disposition' => 'admitted',
            'admit_decision_at' => now()->subHours(2),
            'bed_assigned_at' => now()->subHour(),
            'departed_at' => null,
            'unit_id' => $unitId,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $data = app(CommandCenterDataService::class)->build();

        $boarding = collect($data['heroMetrics'])->firstWhere('key', 'ed_boarding');
        $this->assertNotNull($boarding);
        $this->assertSame(3, $boarding['value'],
            'ed_boarding should be 3 (admitted with null bed_assigned_at)');
    }

    // -----------------------------------------------------------------------
    // 9. forecastDetail.predictedDischarges24h = sum(definite + probable).
    // -----------------------------------------------------------------------

    public function test_forecast_detail_predicted_discharges_24h(): void
    {
        $u1 = $this->insertUnit('FD Unit 1', 'med_surg');
        $u2 = $this->insertUnit('FD Unit 2', 'step_down');

        // u1: definite=4, probable=6 → 10; u2: definite=1, probable=3 → 4; total = 14.
        $this->insertPrediction($u1, definite: 4, probable: 6, possible: 2, demandEd: 3, demandExpected: 5);
        $this->insertPrediction($u2, definite: 1, probable: 3, possible: 1, demandEd: 1, demandExpected: 2);

        $data = app(CommandCenterDataService::class)->build();

        $this->assertSame(14, $data['forecastDetail']['predictedDischarges24h'],
            'forecastDetail.predictedDischarges24h should be sum of definite+probable (14)');
    }

    // -----------------------------------------------------------------------
    // 10. occupancyCurve has 13 entries (hour 0..24 step 2) with required keys.
    // -----------------------------------------------------------------------

    public function test_forecast_detail_occupancy_curve_shape(): void
    {
        $data = app(CommandCenterDataService::class)->build();

        $curve = $data['forecastDetail']['occupancyCurve'];
        $this->assertCount(13, $curve,
            'occupancyCurve should have 13 entries (0,2,4,...,24)');

        foreach ($curve as $point) {
            $this->assertArrayHasKey('hourOffset', $point);
            $this->assertArrayHasKey('occupancyPct', $point);
            $this->assertArrayHasKey('lowerPct', $point);
            $this->assertArrayHasKey('upperPct', $point);

            $occ = $point['occupancyPct'];
            $lower = $point['lowerPct'];
            $upper = $point['upperPct'];

            $this->assertGreaterThanOrEqual(0, $occ);
            $this->assertLessThanOrEqual(100, $occ);
            // lowerPct ≤ occupancyPct ≤ upperPct.
            $this->assertGreaterThanOrEqual(0, $lower);
            $this->assertGreaterThanOrEqual($lower, $occ,
                'occupancyPct must be ≥ lowerPct');
            $this->assertLessThanOrEqual(100, $upper);
        }
    }

    // -----------------------------------------------------------------------
    // 11. Objectives have correct structure and clamped progressPct 0–100.
    // -----------------------------------------------------------------------

    public function test_objectives_have_correct_structure_and_clamped_progress(): void
    {
        $data = app(CommandCenterDataService::class)->build();

        $this->assertCount(3, $data['objectives'], 'Should have 3 objective groups');

        $keys = array_column($data['objectives'], 'key');
        $this->assertContains('flow', $keys);
        $this->assertContains('or', $keys);
        $this->assertContains('beddays', $keys);

        foreach ($data['objectives'] as $obj) {
            $this->assertArrayHasKey('keyResults', $obj);
            foreach ($obj['keyResults'] as $kr) {
                $this->assertArrayHasKey('progressPct', $kr);
                $this->assertGreaterThanOrEqual(0, $kr['progressPct']);
                $this->assertLessThanOrEqual(100, $kr['progressPct']);
                $this->assertArrayHasKey('status', $kr);
                $this->assertContains($kr['status'], ['critical', 'warning', 'success', 'info', 'neutral']);
            }
        }
    }

    // -----------------------------------------------------------------------
    // 12. Readmission rate uses the forward-looking 30-day definition.
    //
    //     Fixtures:
    //       Patient A — discharged 15 days ago, readmitted 5 days ago (qualifies).
    //       Patient B — discharged 10 days ago, no subsequent admission (does not qualify).
    //
    //     Expected rate = 1 readmission / 2 cohort discharges = 50.0%.
    //
    //     This test FAILS against the old backward-looking query and PASSES
    //     after Fix 1 (forward-looking JOIN direction + 30-day cohort window).
    // -----------------------------------------------------------------------

    public function test_readmission_rate_uses_forward_looking_definition(): void
    {
        $unitId = $this->insertUnit('Readmit Unit', 'med_surg');

        // Patient A: discharged 15 days ago → readmitted 5 days ago (10 days later, within 30-day window).
        DB::table('prod.encounters')->insert([
            'patient_ref' => 'readmit-pt-a',
            'unit_id' => $unitId,
            'status' => 'discharged',
            'admitted_at' => now()->subDays(20)->toDateTimeString(),
            'discharged_at' => now()->subDays(15)->toDateTimeString(),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // The qualifying readmission for Patient A.
        DB::table('prod.encounters')->insert([
            'patient_ref' => 'readmit-pt-a',
            'unit_id' => $unitId,
            'status' => 'active',
            'admitted_at' => now()->subDays(5)->toDateTimeString(),
            'discharged_at' => null,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Patient B: discharged 10 days ago, no readmission.
        DB::table('prod.encounters')->insert([
            'patient_ref' => 'readmit-pt-b',
            'unit_id' => $unitId,
            'status' => 'discharged',
            'admitted_at' => now()->subDays(15)->toDateTimeString(),
            'discharged_at' => now()->subDays(10)->toDateTimeString(),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $data = app(CommandCenterDataService::class)->build();

        $readmMetric = collect($data['outcomes']['metrics'])->firstWhere('key', 'readmission');
        $this->assertNotNull($readmMetric);

        // 1 readmission out of 2 cohort discharges = 50.0%.
        $this->assertSame(50.0, $readmMetric['value'],
            'Readmission rate should be 50.0%: 1 qualifying forward readmission out of 2 cohort discharges.');
    }

    // -----------------------------------------------------------------------
    // 13. unitCensus does NOT expose the internal _netBedUnit key.
    // -----------------------------------------------------------------------

    public function test_unit_census_does_not_expose_internal_net_bed_unit_key(): void
    {
        $unitId = $this->insertUnit('Clean Unit', 'med_surg');
        $this->insertSnapshot($unitId, staffed: 20, occupied: 10, available: 10, blocked: 0);

        $data = app(CommandCenterDataService::class)->build();

        foreach ($data['unitCensus'] as $unit) {
            $this->assertArrayNotHasKey('_netBedUnit', $unit,
                'unitCensus must not expose internal _netBedUnit key');
        }
    }

    // -----------------------------------------------------------------------
    // 14. LWBS, Discharge by Noon, and Surge Probability expose detail payloads
    //     and 90-day visualization series for the dashboard.
    // -----------------------------------------------------------------------

    public function test_requested_metrics_expose_detail_payloads_and_ninety_day_trends(): void
    {
        $unitId = $this->insertUnit('Detail Unit', 'med_surg');
        $this->insertSnapshot($unitId, staffed: 20, occupied: 19, available: 1, blocked: 0);

        foreach (range(1, 8) as $i) {
            DB::table('prod.ed_visits')->insert([
                'patient_ref' => "detail-ed-seen-{$i}",
                'arrived_at' => now()->subHours(2),
                'triaged_at' => now()->subMinutes(110),
                'esi_level' => 3,
                'provider_seen_at' => now()->subMinutes(90),
                'disposition' => 'discharged',
                'admit_decision_at' => null,
                'bed_assigned_at' => null,
                'departed_at' => now()->subMinutes(20),
                'unit_id' => null,
                'is_deleted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ([1, 4] as $idx => $esi) {
            DB::table('prod.ed_visits')->insert([
                'patient_ref' => "detail-ed-lwbs-{$idx}",
                'arrived_at' => now()->subHours(2),
                'triaged_at' => now()->subMinutes(115),
                'esi_level' => $esi,
                'provider_seen_at' => null,
                'disposition' => 'lwbs',
                'admit_decision_at' => null,
                'bed_assigned_at' => null,
                'departed_at' => now()->subMinutes(50),
                'unit_id' => null,
                'is_deleted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ([10, 13, 15, 18] as $idx => $hour) {
            DB::table('prod.encounters')->insert([
                'patient_ref' => "detail-dbn-{$idx}",
                'unit_id' => $unitId,
                'status' => 'discharged',
                'admitted_at' => Carbon::today()->subDays(3)->setTime(8, 0)->toDateTimeString(),
                'discharged_at' => Carbon::today()->setTime($hour, 0)->toDateTimeString(),
                'expected_discharge_date' => Carbon::today()->toDateString(),
                'acuity_tier' => 2,
                'is_deleted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->insertPrediction(
            $unitId,
            definite: 1,
            probable: 1,
            possible: 0,
            demandEd: 4,
            demandExpected: 6,
            dischargesWeighted: 1.7
        );

        $data = app(CommandCenterDataService::class)->build();

        $edGroup = collect($data['flow']['subgroups'])->firstWhere('key', 'ed');
        $this->assertNotNull($edGroup);
        $lwbs = collect($edGroup['metrics'])->firstWhere('key', 'ed_lwbs');
        $this->assertNotNull($lwbs);
        $this->assertSame(20.0, $lwbs['value']);
        $this->assertArrayHasKey('detail', $lwbs);
        $this->assertSame('Last 24h ED arrival cohort', $lwbs['detail']['caption']);
        $this->assertCount(90, $lwbs['trajectory']['points']);

        $ipGroup = collect($data['flow']['subgroups'])->firstWhere('key', 'ip');
        $this->assertNotNull($ipGroup);
        $dbn = collect($ipGroup['metrics'])->firstWhere('key', 'dbn');
        $this->assertNotNull($dbn);
        $this->assertSame(25, $dbn['value']);
        $this->assertArrayHasKey('detail', $dbn);
        $this->assertSame("Today's inpatient discharge cohort", $dbn['detail']['caption']);
        $this->assertCount(90, $dbn['trajectory']['points']);

        $surge = collect($data['forecast']['metrics'])->firstWhere('key', 'surge_prob');
        $this->assertNotNull($surge);
        $this->assertArrayHasKey('detail', $surge);
        $this->assertSame('24h surge model drivers', $surge['detail']['caption']);
        $this->assertGreaterThan(0, $surge['value']);
        $this->assertCount(90, $surge['trajectory']['points']);
    }
}
