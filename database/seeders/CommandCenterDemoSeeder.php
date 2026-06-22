<?php

namespace Database\Seeders;

use App\Models\Barrier;
use App\Models\Bed;
use App\Models\BedPlacementDecision;
use App\Models\BedRequest;
use App\Models\DiversionEvent;
use App\Models\EdVisit;
use App\Models\Encounter;
use App\Models\GmlosReference;
use App\Models\PdsaCycle;
use App\Models\RtdcPrediction;
use App\Models\RtdcReconciliation;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CommandCenterDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder is idempotent (safe to re-run) and deterministic.
     * It never touches the `users` table or the RTDC core data
     * (units, beds, encounters, census_snapshots, operational_events).
     */
    public function run(): void
    {
        // Fixed seed for deterministic output.
        mt_srand(20260622);

        // ----------------------------------------------------------------
        // 0. Resolve canonical units (use lowest unit_id set, units 1–6).
        // ----------------------------------------------------------------
        $units = Unit::orderBy('unit_id')->get()->unique('abbreviation')->values();

        // Map abbreviation → unit model for convenience.
        /** @var array<string, Unit> $unitMap */
        $unitMap = [];
        foreach ($units as $unit) {
            $unitMap[$unit->abbreviation] = $unit;
        }

        $nonEdUnits = $units->filter(fn ($u) => $u->type !== 'ed')->values();
        $edUnit = $units->firstWhere('type', 'ed');

        // ----------------------------------------------------------------
        // 1. RTDC Predictions — today, both horizons, per non-ED unit.
        // ----------------------------------------------------------------
        $this->seedRtdcPredictions($nonEdUnits);

        // ----------------------------------------------------------------
        // 2. RTDC Reconciliations — ~14 days history per non-ED unit.
        // ----------------------------------------------------------------
        $this->seedRtdcReconciliations($nonEdUnits);

        // ----------------------------------------------------------------
        // 3. Bed Requests + Bed Placement Decisions.
        // ----------------------------------------------------------------
        $this->seedBedRequestsAndDecisions($units);

        // ----------------------------------------------------------------
        // 4. Barriers (~6 open).
        // ----------------------------------------------------------------
        $this->seedBarriers($nonEdUnits);

        // ----------------------------------------------------------------
        // 5. ED Visits (~70 over last 24h).
        // ----------------------------------------------------------------
        $this->seedEdVisits($edUnit, $nonEdUnits);

        // ----------------------------------------------------------------
        // 6. OR Domain (reference data + cases + logs + metrics + blocks).
        // ----------------------------------------------------------------
        $this->seedOrDomain();

        // ----------------------------------------------------------------
        // 7. GMLOS References.
        // ----------------------------------------------------------------
        $this->seedGmlosReferences();

        // ----------------------------------------------------------------
        // 8. Diversion Events (1–2 historical, 0 active).
        // ----------------------------------------------------------------
        $this->seedDiversionEvents($edUnit);

        // ----------------------------------------------------------------
        // 9. PDSA Cycles (5 active, 2 completed).
        // ----------------------------------------------------------------
        $this->seedPdsaCycles($nonEdUnits);
    }

    // ====================================================================
    // Private helpers
    // ====================================================================

    private function seedRtdcPredictions($nonEdUnits): void
    {
        $today = now()->toDateString();

        foreach ($nonEdUnits as $unit) {
            // Pull latest available count from census_snapshots.
            $latestSnap = DB::table('prod.census_snapshots')
                ->where('unit_id', $unit->unit_id)
                ->orderByDesc('captured_at')
                ->first();

            $capacityNow = $latestSnap ? $latestSnap->available : (int) ($unit->staffed_bed_count * 0.15);

            foreach (['by_2pm', 'by_midnight'] as $horizon) {
                // Deterministic random values per unit+horizon.
                $seed = $unit->unit_id * 100 + ($horizon === 'by_2pm' ? 1 : 2);
                $def = $this->seededRand($seed, 1, 4);
                $prob = $this->seededRand($seed + 10, 2, 6);
                $poss = $this->seededRand($seed + 20, 1, 4);
                $wt = round($def + $prob * 0.7 + $poss * 0.3, 2);

                $demEd = $this->seededRand($seed + 30, 1, 5);
                $demOr = $this->seededRand($seed + 40, 0, 3);
                $demTransfer = $this->seededRand($seed + 50, 0, 2);
                $demDirect = $this->seededRand($seed + 60, 0, 2);
                $demExpected = $demEd + $demOr + $demTransfer + $demDirect;

                $bedNeed = max(0, $demExpected - ($capacityNow + (int) $wt));

                RtdcPrediction::updateOrCreate(
                    [
                        'unit_id' => $unit->unit_id,
                        'service_date' => $today,
                        'horizon' => $horizon,
                    ],
                    [
                        'discharges_definite' => $def,
                        'discharges_probable' => $prob,
                        'discharges_possible' => $poss,
                        'discharges_weighted' => $wt,
                        'demand_ed' => $demEd,
                        'demand_or' => $demOr,
                        'demand_transfer' => $demTransfer,
                        'demand_direct' => $demDirect,
                        'demand_expected' => $demExpected,
                        'capacity_now' => $capacityNow,
                        'bed_need' => $bedNeed,
                        'status' => 'open',
                        'created_by' => 'seeder',
                        'modified_by' => 'seeder',
                        'is_deleted' => false,
                    ]
                );
            }
        }
    }

    private function seedRtdcReconciliations($nonEdUnits): void
    {
        for ($daysAgo = 1; $daysAgo <= 14; $daysAgo++) {
            $date = now()->subDays($daysAgo)->toDateString();

            foreach ($nonEdUnits as $unit) {
                $seed = $unit->unit_id * 1000 + $daysAgo;

                $predDischarges = $this->seededRandFloat($seed, 2.0, 8.0);
                $actualDischarges = $this->seededRand($seed + 1, 1, 8);
                $predAdmissions = $this->seededRand($seed + 2, 2, 9);
                $actualAdmissions = $this->seededRand($seed + 3, 1, 9);

                // reliability_score: 0.70–0.95 range
                $reliability = round($this->seededRandFloat($seed + 4, 0.70, 0.95), 4);

                RtdcReconciliation::updateOrCreate(
                    [
                        'unit_id' => $unit->unit_id,
                        'service_date' => $date,
                    ],
                    [
                        'predicted_discharges' => round($predDischarges, 2),
                        'actual_discharges' => $actualDischarges,
                        'predicted_admissions' => $predAdmissions,
                        'actual_admissions' => $actualAdmissions,
                        'reliability_score' => $reliability,
                    ]
                );
            }
        }
    }

    private function seedBedRequestsAndDecisions($units): void
    {
        // Delete only the seeder's own rows (tagged with created_by='seeder').
        BedPlacementDecision::whereHas('bedRequest', fn ($q) => $q->where('created_by', 'seeder'))->delete();
        BedRequest::where('created_by', 'seeder')->delete();

        $sources = ['ed', 'transfer', 'direct', 'or'];
        $services = ['Medicine', 'Surgery', 'Cardiology', 'Neurology', 'Orthopedics', 'Oncology'];
        $unitTypes = ['med_surg', 'icu', 'step_down', 'any'];

        // 18 requests: 12 placed, 6 pending.
        $requests = [];
        for ($i = 1; $i <= 18; $i++) {
            $seed = 20260622 + $i * 7;
            $source = $sources[$this->seededRand($seed, 0, 3)];
            $status = $i <= 12 ? 'placed' : 'pending';

            $requests[] = BedRequest::create([
                'patient_ref' => sprintf('sim-br-%04d', $i),
                'source' => $source,
                'sex' => ['M', 'F'][$this->seededRand($seed + 1, 0, 1)],
                'service' => $services[$this->seededRand($seed + 2, 0, 5)],
                'acuity_tier' => $this->seededRand($seed + 3, 1, 4),
                'isolation_required' => 'none',
                'required_unit_type' => $unitTypes[$this->seededRand($seed + 4, 0, 3)],
                'status' => $status,
                'created_by' => 'seeder',
                'modified_by' => 'seeder',
                'is_deleted' => false,
            ]);
        }

        // Create BedPlacementDecisions for the 12 placed requests.
        // Fetch a sample of beds from non-ED units to use as recommended/chosen.
        $availableBeds = Bed::whereHas('unit', fn ($q) => $q->where('type', '!=', 'ed'))
            ->where('is_deleted', false)
            ->limit(50)
            ->pluck('bed_id')
            ->toArray();

        if (empty($availableBeds)) {
            // Fallback: any beds.
            $availableBeds = Bed::where('is_deleted', false)->limit(50)->pluck('bed_id')->toArray();
        }

        $encounters = Encounter::where('status', 'active')->limit(12)->get();
        $actions = ['accepted', 'edited', 'rejected'];

        foreach (array_slice($requests, 0, 12) as $idx => $req) {
            $seed = 20260622 + ($idx + 1) * 13;
            $bedIdx = $this->seededRand($seed, 0, count($availableBeds) - 1);
            $bedId = $availableBeds[$bedIdx];
            $action = $actions[$this->seededRand($seed + 1, 0, 2)];
            $latencyMin = $this->seededRand($seed + 2, 30, 70);

            // The latency drives admit→bed metric. We set created_at to
            // latencyMin after the corresponding encounter admitted_at if
            // one exists, otherwise relative to now().
            $encounter = $encounters->get($idx);
            $admittedBase = $encounter ? $encounter->admitted_at : now()->subHours(4);
            $decisionAt = (clone $admittedBase)->addMinutes($latencyMin);

            $decision = new BedPlacementDecision([
                'bed_request_id' => $req->bed_request_id,
                'recommended_bed_id' => $bedId,
                'chosen_bed_id' => $action !== 'rejected' ? $bedId : null,
                'action' => $action,
                'reason' => null,
                'score_snapshot' => null,
                'decided_by' => null,
            ]);
            $decision->created_at = $decisionAt;
            $decision->updated_at = $decisionAt;
            $decision->save();
        }
    }

    private function seedBarriers($nonEdUnits): void
    {
        // Delete seeder's own barriers.
        Barrier::where('owner', 'seeder')->delete();

        $categories = ['medical', 'logistical', 'placement', 'social'];
        $descriptions = [
            'Awaiting cardiology consult',
            'No SNF beds available',
            'Family meeting pending disposition decision',
            'Pending IV antibiotic course completion',
            'Insurance authorization required for transfer',
            'Home oxygen equipment not yet arranged',
        ];

        $encounters = Encounter::where('status', 'active')->limit(6)->get();

        foreach (range(1, 6) as $i) {
            $seed = 20260622 + $i * 17;
            $unit = $nonEdUnits->get($this->seededRand($seed, 0, $nonEdUnits->count() - 1));
            $enc = $encounters->get($i - 1);
            $openedAt = now()->subHours($this->seededRand($seed + 1, 1, 48));

            Barrier::create([
                'encounter_id' => $enc ? $enc->encounter_id : null,
                'unit_id' => $unit->unit_id,
                'category' => $categories[$this->seededRand($seed + 2, 0, 3)],
                'reason_code' => null,
                'description' => $descriptions[$i - 1],
                'owner' => 'seeder',
                'status' => 'open',
                'opened_at' => $openedAt,
                'resolved_at' => null,
                'is_deleted' => false,
            ]);
        }
    }

    private function seedEdVisits(?Unit $edUnit, $nonEdUnits): void
    {
        // Delete seeder's own ED visits.
        EdVisit::where('patient_ref', 'like', 'sim-ed-%')->delete();

        if (! $edUnit) {
            return;
        }

        // Admission target units (non-ED).
        $admitUnits = $nonEdUnits->pluck('unit_id')->toArray();

        // 70 visits spread across the last 24 hours.
        // Disposition mix: 70% discharged / 24% admitted / 2% lwbs / 4% transfer.
        // ~4 admitted with bed_assigned_at=NULL (boarding).
        $dispositions = array_merge(
            array_fill(0, 49, 'discharged'), // 70%
            array_fill(0, 17, 'admitted'),   // ~24%
            array_fill(0, 1, 'lwbs'),        // ~2% (1 of 70)
            array_fill(0, 3, 'transfer'),    // ~4%
        );
        // Shuffle deterministically.
        $dispositions = $this->deterministicShuffle($dispositions, 20260622);

        // ESI weight: mostly 2-4, rarely 1 or 5.
        $esiWeights = [1 => 5, 2 => 25, 3 => 45, 4 => 20, 5 => 5];
        $esiPool = [];
        foreach ($esiWeights as $level => $weight) {
            for ($w = 0; $w < $weight; $w++) {
                $esiPool[] = $level;
            }
        }
        $esiPool = $this->deterministicShuffle($esiPool, 20260622 + 1);

        $boardingCount = 0;
        $maxBoarding = 4;
        $admittedCount = 0;

        for ($i = 1; $i <= 70; $i++) {
            $seed = 20260622 + $i * 31;
            $hoursAgo = $this->seededRandFloat($seed, 0.5, 23.5);
            $arrivedAt = now()->subMinutes((int) ($hoursAgo * 60));

            $triagedAt = $arrivedAt->copy()->addMinutes($this->seededRand($seed + 1, 3, 12));
            $providerAt = $triagedAt->copy()->addMinutes($this->seededRand($seed + 2, 8, 35));
            $esiLevel = $esiPool[($i - 1) % count($esiPool)];
            $disposition = $dispositions[($i - 1) % count($dispositions)];

            $admitDecisionAt = null;
            $bedAssignedAt = null;
            $departedAt = null;
            $unitId = null;

            switch ($disposition) {
                case 'discharged':
                    $losDuration = $this->seededRand($seed + 3, 90, 360);
                    $departedAt = $arrivedAt->copy()->addMinutes($losDuration);
                    if ($departedAt->greaterThan(now())) {
                        $departedAt = null;
                        $disposition = null; // Still in ED.
                    }
                    break;

                case 'admitted':
                    $admitDecisionAt = $providerAt->copy()->addMinutes($this->seededRand($seed + 4, 20, 90));
                    $admittedCount++;

                    // ~4 of admitted patients are still boarding (bed_assigned_at NULL).
                    $isBoarding = ($boardingCount < $maxBoarding && $admittedCount <= 8);
                    if ($isBoarding) {
                        $boardingCount++;
                        $bedAssignedAt = null;
                    } else {
                        $bedAssignedAt = $admitDecisionAt->copy()->addMinutes($this->seededRand($seed + 5, 30, 120));
                        if ($bedAssignedAt->greaterThan(now())) {
                            $bedAssignedAt = null;
                        }
                    }
                    // Departed once assigned.
                    if ($bedAssignedAt) {
                        $departedAt = $bedAssignedAt->copy()->addMinutes($this->seededRand($seed + 6, 10, 45));
                        if ($departedAt->greaterThan(now())) {
                            $departedAt = null;
                        }
                    }
                    $unitId = $admitUnits[$this->seededRand($seed + 7, 0, count($admitUnits) - 1)];
                    break;

                case 'lwbs':
                    // Left without being seen — departed shortly after arrival.
                    $departedAt = $arrivedAt->copy()->addMinutes($this->seededRand($seed + 3, 15, 60));
                    if ($departedAt->greaterThan(now())) {
                        $departedAt = null;
                    }
                    break;

                case 'transfer':
                    $admitDecisionAt = $providerAt->copy()->addMinutes($this->seededRand($seed + 4, 30, 120));
                    $departedAt = $admitDecisionAt->copy()->addMinutes($this->seededRand($seed + 5, 30, 90));
                    if ($departedAt->greaterThan(now())) {
                        $departedAt = null;
                    }
                    break;
            }

            EdVisit::create([
                'patient_ref' => sprintf('sim-ed-%04d', $i),
                'arrived_at' => $arrivedAt,
                'triaged_at' => $triagedAt,
                'esi_level' => $esiLevel,
                'provider_seen_at' => $providerAt,
                'disposition' => $disposition,
                'admit_decision_at' => $admitDecisionAt,
                'bed_assigned_at' => $bedAssignedAt,
                'departed_at' => $departedAt,
                'unit_id' => $unitId,
                'is_deleted' => false,
            ]);
        }
    }

    private function seedOrDomain(): void
    {
        // ----------------------------------------------------------------
        // Reference data (idempotent via updateOrInsert on natural keys).
        // ----------------------------------------------------------------

        // Location.
        $locationId = DB::table('prod.locations')->where('abbreviation', 'MOR')->value('location_id');
        if (! $locationId) {
            $locationId = DB::table('prod.locations')->insertGetId([
                'name' => 'Main OR Suite',
                'abbreviation' => 'MOR',
                'type' => 'surgical',
                'pos_type' => 'inpatient',
                'active_status' => true,
                'created_by' => 'seeder',
                'modified_by' => 'seeder',
                'created_at' => now(),
                'updated_at' => now(),
                'is_deleted' => false,
            ], 'location_id');
        }

        // Specialties.
        $specialtyNames = [
            'General Surgery', 'Orthopedics', 'Cardiology', 'Neurosurgery', 'OB/GYN',
        ];
        $specialtyIds = [];
        foreach ($specialtyNames as $idx => $name) {
            $code = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 6));
            $existing = DB::table('prod.specialties')->where('code', $code)->first();
            if ($existing) {
                $specialtyIds[] = $existing->specialty_id;
            } else {
                $specialtyIds[] = DB::table('prod.specialties')->insertGetId([
                    'name' => $name,
                    'code' => $code,
                    'active_status' => true,
                    'created_by' => 'seeder',
                    'modified_by' => 'seeder',
                    'created_at' => now(),
                    'updated_at' => now(),
                    'is_deleted' => false,
                ], 'specialty_id');
            }
        }

        // Services (4).
        $serviceData = [
            ['name' => 'General Surgery', 'code' => 'GS'],
            ['name' => 'Orthopedics', 'code' => 'ORTHO'],
            ['name' => 'Cardiology', 'code' => 'CARD'],
            ['name' => 'Neurosurgery', 'code' => 'NEURO'],
        ];
        $serviceIds = [];
        foreach ($serviceData as $s) {
            $existing = DB::table('prod.services')->where('code', $s['code'])->first();
            if ($existing) {
                $serviceIds[] = $existing->service_id;
            } else {
                $serviceIds[] = DB::table('prod.services')->insertGetId([
                    'name' => $s['name'],
                    'code' => $s['code'],
                    'active_status' => true,
                    'created_by' => 'seeder',
                    'modified_by' => 'seeder',
                    'created_at' => now(),
                    'updated_at' => now(),
                    'is_deleted' => false,
                ], 'service_id');
            }
        }

        // Providers (4 surgeons).
        $providerData = [
            ['name' => 'Dr. Smith',    'npi' => '1000000001', 'spec' => $specialtyIds[0]],
            ['name' => 'Dr. Johnson',  'npi' => '1000000002', 'spec' => $specialtyIds[1]],
            ['name' => 'Dr. Williams', 'npi' => '1000000003', 'spec' => $specialtyIds[2]],
            ['name' => 'Dr. Brown',    'npi' => '1000000004', 'spec' => $specialtyIds[3]],
        ];
        $providerIds = [];
        foreach ($providerData as $p) {
            $existing = DB::table('prod.providers')->where('npi', $p['npi'])->first();
            if ($existing) {
                $providerIds[] = $existing->provider_id;
            } else {
                $providerIds[] = DB::table('prod.providers')->insertGetId([
                    'name' => $p['name'],
                    'npi' => $p['npi'],
                    'specialty_id' => $p['spec'],
                    'type' => 'surgeon',
                    'active_status' => true,
                    'created_by' => 'seeder',
                    'modified_by' => 'seeder',
                    'created_at' => now(),
                    'updated_at' => now(),
                    'is_deleted' => false,
                ], 'provider_id');
            }
        }

        // Rooms (4 ORs).
        $roomNames = ['OR-1', 'OR-2', 'OR-3', 'OR-4'];
        $roomIds = [];
        foreach ($roomNames as $rn) {
            $existing = DB::table('prod.rooms')
                ->where('name', $rn)
                ->where('location_id', $locationId)
                ->first();
            if ($existing) {
                $roomIds[] = $existing->room_id;
            } else {
                $roomIds[] = DB::table('prod.rooms')->insertGetId([
                    'location_id' => $locationId,
                    'name' => $rn,
                    'type' => 'OR',
                    'active_status' => true,
                    'created_by' => 'seeder',
                    'modified_by' => 'seeder',
                    'created_at' => now(),
                    'updated_at' => now(),
                    'is_deleted' => false,
                ], 'room_id');
            }
        }

        // Case statuses (idempotent via CaseManagementSeeder pattern).
        $this->ensureCaseStatuses();

        // Case types.
        $caseTypeId = $this->ensureReferenceRow(
            'prod.case_types', 'case_type_id', 'code', 'ELEC',
            ['name' => 'Elective', 'code' => 'ELEC', 'active_status' => true,
                'created_by' => 'seeder', 'modified_by' => 'seeder',
                'created_at' => now(), 'updated_at' => now(), 'is_deleted' => false]
        );

        // Case classes.
        $caseClassId = $this->ensureReferenceRow(
            'prod.case_classes', 'case_class_id', 'code', 'INP',
            ['name' => 'Inpatient', 'code' => 'INP', 'active_status' => true,
                'created_by' => 'seeder', 'modified_by' => 'seeder',
                'created_at' => now(), 'updated_at' => now(), 'is_deleted' => false]
        );

        // Patient classes.
        $patientClassId = $this->ensureReferenceRow(
            'prod.patient_classes', 'patient_class_id', 'code', 'INP',
            ['name' => 'Inpatient', 'code' => 'INP', 'active_status' => true,
                'created_by' => 'seeder', 'modified_by' => 'seeder',
                'created_at' => now(), 'updated_at' => now(), 'is_deleted' => false]
        );

        // ASA ratings.
        $asaRatingId = $this->ensureReferenceRow(
            'prod.asa_ratings', 'asa_id', 'code', 'ASA2',
            ['name' => 'ASA II', 'code' => 'ASA2', 'description' => 'Mild systemic disease',
                'created_by' => 'seeder', 'modified_by' => 'seeder',
                'created_at' => now(), 'updated_at' => now(), 'is_deleted' => false]
        );

        // Cancellation reasons.
        $cancelReasonId = $this->ensureReferenceRow(
            'prod.cancellation_reasons', 'cancellation_id', 'code', 'NORS',
            ['name' => 'No OR Suite Available', 'code' => 'NORS', 'active_status' => true,
                'created_by' => 'seeder', 'modified_by' => 'seeder',
                'created_at' => now(), 'updated_at' => now(), 'is_deleted' => false]
        );

        // Resolve case status IDs.
        $statusScheduled = DB::table('prod.case_statuses')->where('code', 'SCHED')->value('status_id');
        $statusInProgress = DB::table('prod.case_statuses')->where('code', 'INPROG')->value('status_id');
        $statusCompleted = DB::table('prod.case_statuses')->where('code', 'COMP')->value('status_id');
        $statusCancelled = DB::table('prod.case_statuses')->where('code', 'CANC')->value('status_id');

        // ----------------------------------------------------------------
        // OR Cases: last 5 weekdays × 4 rooms × ~5 cases.
        // Delete seeder's own OR data first (idempotent).
        // ----------------------------------------------------------------
        $seederCaseIds = DB::table('prod.or_cases')
            ->where('created_by', 'seeder')
            ->pluck('case_id');

        if ($seederCaseIds->isNotEmpty()) {
            DB::table('prod.case_metrics')->whereIn('case_id', $seederCaseIds)->delete();
            DB::table('prod.or_logs')->whereIn('case_id', $seederCaseIds)->delete();
            DB::table('prod.or_cases')->whereIn('case_id', $seederCaseIds)->delete();
        }

        $procedures = [
            'Laparoscopic Cholecystectomy',
            'Total Knee Replacement',
            'Coronary Artery Bypass Graft',
            'Lumbar Discectomy',
            'Hip Arthroplasty',
            'Appendectomy',
            'Inguinal Hernia Repair',
            'Carpal Tunnel Release',
            'Thyroidectomy',
            'Colectomy',
            'Shoulder Arthroplasty',
            'Craniotomy',
            'Mastectomy',
            'Prostatectomy',
            'Knee Arthroscopy',
        ];

        // Generate the last 5 weekdays.
        $weekdays = [];
        $candidate = now()->startOfDay();
        while (count($weekdays) < 5) {
            if ($candidate->isWeekday()) {
                $weekdays[] = $candidate->copy();
            }
            $candidate->subDay();
        }
        $weekdays = array_reverse($weekdays); // Oldest first.

        $today = now()->toDateString();
        $isToday = fn (Carbon $d) => $d->toDateString() === $today;

        $allCaseIds = [];

        foreach ($weekdays as $dayIdx => $day) {
            foreach ($roomIds as $roomIdx => $roomId) {
                // ~5 cases per room per day.
                $numCases = 5;
                $slotStart = $day->copy()->setTime(7, 30);

                for ($cIdx = 0; $cIdx < $numCases; $cIdx++) {
                    $seed = 20260622 + $dayIdx * 1000 + $roomIdx * 100 + $cIdx;
                    $duration = $this->seededRand($seed, 60, 300);
                    $surgeonId = $providerIds[$this->seededRand($seed + 1, 0, count($providerIds) - 1)];
                    $serviceId = $serviceIds[$this->seededRand($seed + 2, 0, count($serviceIds) - 1)];
                    $procedureId = $this->seededRand($seed + 3, 0, count($procedures) - 1);
                    $procedure = $procedures[$procedureId];

                    // Determine status:
                    // Today: first case In Progress, last case Cancelled, rest Scheduled.
                    // Past days: all Completed.
                    if ($isToday($day)) {
                        if ($cIdx === 0) {
                            $statusId = $statusInProgress;
                        } elseif ($cIdx === $numCases - 1) {
                            $statusId = $statusCancelled;
                        } else {
                            $statusId = $statusScheduled;
                        }
                    } else {
                        $statusId = $statusCompleted;
                    }

                    $scheduledStart = $slotStart->copy();

                    $caseId = DB::table('prod.or_cases')->insertGetId([
                        'patient_id' => sprintf('SIM%04d', $seed % 10000),
                        'surgery_date' => $day->toDateString(),
                        'room_id' => $roomId,
                        'location_id' => $locationId,
                        'primary_surgeon_id' => $surgeonId,
                        'case_service_id' => $serviceId,
                        'scheduled_start_time' => $scheduledStart,
                        'scheduled_duration' => $duration,
                        'record_create_date' => $day->copy()->subDays(3),
                        'status_id' => $statusId,
                        'cancellation_reason_id' => ($statusId === $statusCancelled ? $cancelReasonId : null),
                        'asa_rating_id' => $asaRatingId,
                        'case_type_id' => $caseTypeId,
                        'case_class_id' => $caseClassId,
                        'patient_class_id' => $patientClassId,
                        'safety_status' => 'Normal',
                        'journey_progress' => 0,
                        'created_by' => 'seeder',
                        'modified_by' => 'seeder',
                        'created_at' => now(),
                        'updated_at' => now(),
                        'is_deleted' => false,
                    ], 'case_id');

                    $allCaseIds[] = $caseId;

                    // OR Log for completed and in-progress cases.
                    if (in_array($statusId, [$statusCompleted, $statusInProgress])) {
                        $lateStartMin = $this->seededRand($seed + 4, -5, 20);
                        $orInTime = $scheduledStart->copy()->addMinutes($lateStartMin);
                        $procStart = $orInTime->copy()->addMinutes($this->seededRand($seed + 5, 20, 45));
                        $procEnd = null;
                        $orOutTime = null;

                        if ($statusId === $statusCompleted) {
                            $procEnd = $procStart->copy()->addMinutes($duration);
                            $orOutTime = $procEnd->copy()->addMinutes($this->seededRand($seed + 6, 15, 35));
                        }

                        $logId = DB::table('prod.or_logs')->insertGetId([
                            'case_id' => $caseId,
                            'tracking_date' => $day->toDateString(),
                            'or_in_time' => $orInTime,
                            'procedure_start_time' => $procStart,
                            'procedure_end_time' => $procEnd,
                            'or_out_time' => $orOutTime,
                            'primary_procedure' => $procedure,
                            'created_by' => 'seeder',
                            'modified_by' => 'seeder',
                            'created_at' => now(),
                            'updated_at' => now(),
                            'is_deleted' => false,
                        ], 'log_id');

                        // Case Metrics.
                        if ($statusId === $statusCompleted) {
                            $turnover = $this->seededRand($seed + 7, 15, 45);
                            $lateStart = max(0, $lateStartMin);
                            $utilPct = round(min(100, ($duration / 480.0) * 100 + $this->seededRandFloat($seed + 8, -10, 10)), 2);
                            $primeMin = (int) ($duration * 0.85);
                            $nonPrimeMin = $duration - $primeMin;

                            DB::table('prod.case_metrics')->insert([
                                'case_id' => $caseId,
                                'turnover_time' => $turnover,
                                'utilization_percentage' => $utilPct,
                                'in_block_time' => $duration,
                                'out_of_block_time' => 0,
                                'prime_time_minutes' => $primeMin,
                                'non_prime_time_minutes' => $nonPrimeMin,
                                'late_start_minutes' => $lateStart,
                                'early_finish_minutes' => 0,
                                'created_by' => 'seeder',
                                'modified_by' => 'seeder',
                                'created_at' => now(),
                                'updated_at' => now(),
                                'is_deleted' => false,
                            ]);
                        }
                    }

                    // Advance slot start for next case.
                    $turnoverGap = $this->seededRand($seed + 9, 15, 30);
                    $slotStart = $slotStart->addMinutes($duration + $turnoverGap);
                }
            }
        }

        // ----------------------------------------------------------------
        // Block Templates + Block Utilization (per room × per weekday).
        // ----------------------------------------------------------------
        // Delete seeder's own block data.
        $seederBlockIds = DB::table('prod.block_templates')
            ->where('created_by', 'seeder')
            ->pluck('block_id');

        if ($seederBlockIds->isNotEmpty()) {
            DB::table('prod.block_utilization')->whereIn('block_id', $seederBlockIds)->delete();
            DB::table('prod.block_templates')->whereIn('block_id', $seederBlockIds)->delete();
        }

        foreach ($weekdays as $dayIdx => $day) {
            foreach ($roomIds as $roomIdx => $roomId) {
                $serviceId = $serviceIds[$roomIdx % count($serviceIds)];
                $surgeonId = $providerIds[$roomIdx % count($providerIds)];

                $blockId = DB::table('prod.block_templates')->insertGetId([
                    'room_id' => $roomId,
                    'service_id' => $serviceId,
                    'surgeon_id' => $surgeonId,
                    'group_id' => null,
                    'block_date' => $day->toDateString(),
                    'start_time' => '07:30:00',
                    'end_time' => '15:30:00',
                    'is_public' => true,
                    'title' => "Block {$day->format('M d')} OR-".($roomIdx + 1),
                    'abbreviation' => 'BLK',
                    'created_by' => 'seeder',
                    'modified_by' => 'seeder',
                    'created_at' => now(),
                    'updated_at' => now(),
                    'is_deleted' => false,
                ], 'block_id');

                $seed = 20260622 + $dayIdx * 500 + $roomIdx;
                $schedMin = 480; // 8h block
                $actualMin = $this->seededRand($seed, 300, 480);
                $utilPct = round(($actualMin / $schedMin) * 100, 2);
                $casesPerf = 5;
                $primePct = round($this->seededRandFloat($seed + 1, 70.0, 95.0), 2);
                $nonPrimePct = round(100.0 - $primePct, 2);

                DB::table('prod.block_utilization')->insert([
                    'block_id' => $blockId,
                    'date' => $day->toDateString(),
                    'service_id' => $serviceId,
                    'location_id' => $locationId,
                    'scheduled_minutes' => $schedMin,
                    'actual_minutes' => $actualMin,
                    'utilization_percentage' => $utilPct,
                    'cases_scheduled' => $casesPerf,
                    'cases_performed' => $isToday($day) ? $this->seededRand($seed + 2, 2, 4) : $casesPerf,
                    'prime_time_percentage' => $primePct,
                    'non_prime_time_percentage' => $nonPrimePct,
                    'created_by' => 'seeder',
                    'modified_by' => 'seeder',
                    'created_at' => now(),
                    'updated_at' => now(),
                    'is_deleted' => false,
                ]);
            }
        }
    }

    private function seedGmlosReferences(): void
    {
        $refs = [
            ['unit_type' => 'med_surg',  'gmlos_days' => 4.20],
            ['unit_type' => 'icu',       'gmlos_days' => 5.80],
            ['unit_type' => 'step_down', 'gmlos_days' => 3.50],
            ['unit_type' => 'ed',        'gmlos_days' => 0.40],
        ];

        foreach ($refs as $ref) {
            GmlosReference::updateOrCreate(
                ['unit_type' => $ref['unit_type']],
                [
                    'gmlos_days' => $ref['gmlos_days'],
                    'effective_from' => now()->startOfYear()->toDateString(),
                ]
            );
        }
    }

    private function seedDiversionEvents(?Unit $edUnit): void
    {
        // Delete seeder's own diversion events.
        DiversionEvent::where('reason', 'like', 'sim:%')->delete();

        if (! $edUnit) {
            return;
        }

        // 2 historical events last week, both ended (no active diversions).
        $events = [
            [
                'started_at' => now()->subDays(6)->setTime(14, 30),
                'ended_at' => now()->subDays(6)->setTime(17, 45),
                'reason' => 'sim: ED surge — high-acuity influx',
            ],
            [
                'started_at' => now()->subDays(3)->setTime(20, 0),
                'ended_at' => now()->subDays(3)->setTime(23, 30),
                'reason' => 'sim: Trauma activation — capacity exceeded',
            ],
        ];

        foreach ($events as $e) {
            DiversionEvent::create([
                'scope' => 'ed',
                'unit_id' => $edUnit->unit_id,
                'started_at' => $e['started_at'],
                'ended_at' => $e['ended_at'],
                'reason' => $e['reason'],
                'is_deleted' => false,
            ]);
        }
    }

    private function seedPdsaCycles($nonEdUnits): void
    {
        // Delete seeder's own PDSA cycles.
        PdsaCycle::where('owner', 'seeder')->delete();

        $cycles = [
            // 5 active
            [
                'title' => 'Reduce ED boarding time through rapid bed assignment protocol',
                'status' => 'active',
                'objective' => 'Decrease median admit-to-bed latency from 85 to <60 minutes.',
                'unit_idx' => 0,
            ],
            [
                'title' => 'Improve discharge-before-noon rate on 5 East',
                'status' => 'active',
                'objective' => 'Increase DBN rate from 28% to 45% within 60 days.',
                'unit_idx' => 1,
            ],
            [
                'title' => 'Standardize FCOTS checklist across OR suites',
                'status' => 'active',
                'objective' => 'Raise first-case on-time starts from 72% to 90%.',
                'unit_idx' => null,
            ],
            [
                'title' => 'ICU care-pathway bundle compliance for sepsis',
                'status' => 'active',
                'objective' => 'Achieve >95% bundle compliance; reduce ICU LOS by 0.5 days.',
                'unit_idx' => 2,
            ],
            [
                'title' => 'Barrier resolution daily huddle effectiveness',
                'status' => 'active',
                'objective' => 'Resolve >80% of open barriers within 24h of identification.',
                'unit_idx' => 3,
            ],
            // 2 completed
            [
                'title' => 'Post-surgical VTE prophylaxis protocol roll-out',
                'status' => 'completed',
                'objective' => 'Achieve 100% VTE risk screening within 4h of admission.',
                'unit_idx' => null,
            ],
            [
                'title' => 'Medication reconciliation at discharge pilot',
                'status' => 'completed',
                'objective' => 'Reduce medication discrepancy rate from 18% to <5%.',
                'unit_idx' => 1,
            ],
        ];

        $unitArr = $nonEdUnits->values()->all(); // keep as Eloquent model objects

        foreach ($cycles as $idx => $cycle) {
            $unit = ($cycle['unit_idx'] !== null && isset($unitArr[$cycle['unit_idx']]))
                ? $unitArr[$cycle['unit_idx']]
                : null;

            $startedAt = now()->subDays(30 + $idx * 7);
            $completedAt = $cycle['status'] === 'completed'
                ? now()->subDays($idx * 5)
                : null;

            PdsaCycle::create([
                'title' => $cycle['title'],
                'unit_id' => $unit ? $unit->unit_id : null,
                'status' => $cycle['status'],
                'owner' => 'seeder',
                'objective' => $cycle['objective'],
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'is_deleted' => false,
            ]);
        }
    }

    // ====================================================================
    // Utility helpers
    // ====================================================================

    /**
     * Ensure the five canonical case statuses exist (idempotent).
     */
    private function ensureCaseStatuses(): void
    {
        $statuses = [
            ['status_id' => 1, 'name' => 'Scheduled',   'code' => 'SCHED'],
            ['status_id' => 2, 'name' => 'In Progress',  'code' => 'INPROG'],
            ['status_id' => 3, 'name' => 'Delayed',      'code' => 'DELAY'],
            ['status_id' => 4, 'name' => 'Completed',    'code' => 'COMP'],
            ['status_id' => 5, 'name' => 'Cancelled',    'code' => 'CANC'],
        ];

        foreach ($statuses as $s) {
            DB::table('prod.case_statuses')->updateOrInsert(
                ['status_id' => $s['status_id']],
                array_merge($s, [
                    'active_status' => true,
                    'created_by' => 'system',
                    'modified_by' => 'system',
                    'created_at' => now(),
                    'updated_at' => now(),
                    'is_deleted' => false,
                ])
            );
        }
    }

    /**
     * Ensure a single reference row exists, returning its PK.
     */
    private function ensureReferenceRow(
        string $table,
        string $pk,
        string $keyCol,
        string $keyVal,
        array $data
    ): int {
        $existing = DB::table($table)->where($keyCol, $keyVal)->first();
        if ($existing) {
            return (int) $existing->$pk;
        }

        return (int) DB::table($table)->insertGetId($data, $pk);
    }

    /**
     * Deterministic integer in [min, max] using a given seed offset.
     * We mix the offset into mt_rand by calling it a fixed number of times,
     * which is not perfect but is simple and reproducible with fixed mt_srand.
     * A better approach: use a simple LCG seeded per call.
     */
    private function seededRand(int $seed, int $min, int $max): int
    {
        // LCG: x = (a*x + c) mod m
        $x = ($seed * 1664525 + 1013904223) & 0x7FFFFFFF;

        return $min + ($x % ($max - $min + 1));
    }

    private function seededRandFloat(int $seed, float $min, float $max): float
    {
        $x = ($seed * 1664525 + 1013904223) & 0x7FFFFFFF;
        $frac = $x / 0x7FFFFFFF;

        return $min + $frac * ($max - $min);
    }

    private function deterministicShuffle(array $arr, int $seed): array
    {
        $n = count($arr);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = $this->seededRand($seed + $i, 0, $i);
            [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
        }

        return $arr;
    }
}
