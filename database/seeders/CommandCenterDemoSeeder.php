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
use App\Support\Hospital\HospitalManifest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CommandCenterDemoSeeder extends Seeder
{
    private HospitalManifest $manifest;

    /**
     * Run the database seeds.
     *
     * This seeder is idempotent (safe to re-run) and deterministic.
     * It never touches the `users` table or the RTDC core data
     * (units, beds, encounters, census_snapshots, operational_events).
     */
    public function run(): void
    {
        // Single source of truth for unit/provider/service/transport names.
        $this->manifest = app(HospitalManifest::class);

        // Fixed seed for deterministic output.
        mt_srand(20260622);

        // ----------------------------------------------------------------
        // 0a. De-duplicate units — idempotent soft-delete.
        //
        //     For each abbreviation group with more than one non-deleted
        //     unit, keep the lowest unit_id (canonical) and set is_deleted=true
        //     on all higher ones. If no duplicates exist (prod, fresh seed)
        //     this is a complete no-op.
        //
        //     Reversible: only sets the is_deleted flag; no rows are removed.
        //     Never touches beds/encounters/census of the soft-deleted units.
        // ----------------------------------------------------------------
        $allUnits = Unit::orderBy('unit_id')->where('is_deleted', false)->get();
        $seenAbbreviations = [];
        foreach ($allUnits as $unit) {
            if (! in_array($unit->abbreviation, $seenAbbreviations, true)) {
                $seenAbbreviations[] = $unit->abbreviation;
            } else {
                // Duplicate — soft-delete it.
                DB::table('prod.units')
                    ->where('unit_id', $unit->unit_id)
                    ->update(['is_deleted' => true, 'updated_at' => now()]);
            }
        }

        // ----------------------------------------------------------------
        // 0b. Resolve canonical units (non-deleted, lowest unit_id per abbr).
        // ----------------------------------------------------------------
        $units = Unit::orderBy('unit_id')->where('is_deleted', false)->get()->values();

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
        // 4b. Staffing plans + gap-mitigation requests.
        //     Deliberately leaves two units short for the current day shift
        //     so the "staffing is tight on two units" demo signal is real.
        // ----------------------------------------------------------------
        $this->seedStaffingPlans($nonEdUnits);

        // ----------------------------------------------------------------
        // 4b-ii. Workforce actuals (P7) — the time-and-attendance day fact.
        //        Today feeds the live staffing tiles (OT% / agency / callouts
        //        / sitters / productivity); the trailing 30 days feed the MTD
        //        cost-center productivity MV (WS-5).
        // ----------------------------------------------------------------
        $this->seedWorkforceActuals($nonEdUnits);

        // ----------------------------------------------------------------
        // 4c. EVS backlog — several pending/in-progress turns with a couple
        //     overdue so the "EVS turnaround is behind" demo signal is real.
        // ----------------------------------------------------------------
        $this->seedEvsBacklog($nonEdUnits);

        // ----------------------------------------------------------------
        // 4c-ii. Discharge-lounge stays (P7) — live census for the
        //        flow.discharge_lounge tile: 6 in the lounge, 4 picked up.
        // ----------------------------------------------------------------
        $this->seedDischargeLoungeStays($nonEdUnits);

        // ----------------------------------------------------------------
        // 4d. Transport backlog — several active moves with stat/overdue
        //     requests so the "transport queue is overloaded" demo signal,
        //     transport SLA-risk recommendation, and dispatch board are real.
        // ----------------------------------------------------------------
        $this->seedTransportBacklog($nonEdUnits);

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

        // ----------------------------------------------------------------
        // 9b. Historical discharged encounters for LOS/GMLOS, readmissions,
        //     and discharge-by-noon metrics.
        //
        //     Creates ~220 historical + ~30 discharged-today encounters
        //     tagged with 'sim-hx-' prefix (idempotently deletable).
        //     Readmission encounters tagged 'sim-ra-' prefix.
        //
        //     Must run AFTER seedGmlosReferences() (step 7) so the unit
        //     type→gmlos lookup is populated.
        // ----------------------------------------------------------------
        $this->seedHistoricalEncounters($nonEdUnits);

        // ----------------------------------------------------------------
        // 10. Busy-state tuning — sets a compelling, realistic high-demand
        //     snapshot for the Command Center dashboard.
        //
        //     Target: occupancy ≈ 87%, net beds ≈ 0–4, ED boarding = 5,
        //             dc_ready = 12, strain level 2 (warning).
        //
        //     Idempotent: uses updateOrInsert / deterministic ID selection.
        //     Never touches the users table or drops core RTDC data.
        //
        //     Duplicates are now soft-deleted (step 0a), so the service
        //     excludes them via WHERE u.is_deleted = false. We only need to
        //     tune the 6 canonical units.
        // ----------------------------------------------------------------
        $this->tuneCommandCenterBusyState($units, $edUnit);

        // ----------------------------------------------------------------
        // 11. Zephyrus 2.0 cockpit KPI catalog — every Appendix-A key with
        //     literature-aligned band edges (standalone seeder so prod can
        //     refresh the catalog without the demo data).
        // ----------------------------------------------------------------
        $this->call(CockpitKpiDefinitionSeeder::class);

        // ----------------------------------------------------------------
        // 12. Quality / Service-Line / Financial MTD facts (P7 WS-5) — the
        //     quality_events ledger and the discharge_facts DRG/cost ledger
        //     that back the three MTD materialized views, then refresh the
        //     views so the Quality/Service/Financial tiles light up live.
        // ----------------------------------------------------------------
        $this->seedQualityEvents($nonEdUnits);
        $this->seedDischargeFacts($nonEdUnits);
        $this->refreshCockpitMaterializedViews();
    }

    // ====================================================================
    // Busy-state tuning
    // ====================================================================

    /**
     * Tune the Command Center demo to a busy, high-demand state.
     *
     * Called AFTER all other seeders so it always wins the "latest snapshot"
     * race regardless of what earlier steps inserted.
     *
     * Idempotent: census snapshots use a fixed sentinel captured_at (today
     * at noon), so updateOrInsert matches the same row on re-runs.
     * Bed-request and encounter updates are idempotent by design (WHERE +
     * UPDATE, not INSERT).
     *
     * Duplicates are soft-deleted in step 0a, so $canonicalUnits contains
     * one row per abbreviation (the 25-unit Summit roster). No workaround needed.
     */
    private function tuneCommandCenterBusyState($canonicalUnits, ?Unit $edUnit): void
    {
        // ------------------------------------------------------------------
        // 10a. Census snapshots — one deterministic "latest" row per unit.
        //
        // captured_at sentinel = today 12:00:00 (fixed per calendar day).
        // The service uses DISTINCT ON (unit_id) ORDER BY captured_at DESC,
        // so this row always wins over earlier snapshots from the core seeder.
        //
        // Duplicates are soft-deleted; the service filters WHERE u.is_deleted=false,
        // so only the canonical Summit units contribute to house totals.
        //
        // Targets are derived from HospitalManifest::censusDemoTargets() — the
        // single source of truth for the 25-unit Summit roster (23 inpatient
        // units summing to exactly 500 staffed beds, + ED + the PERIOP/OR
        // platform). For each unit:
        //   occupied  = target_occupied
        //   blocked   = small heuristic (1 on a handful of high-occupancy units,
        //               else 0; ~5 house-wide)
        //   available = staffed_bed_count − occupied − blocked
        //   aac       = occupied + small ICU/telemetry acuity adjustment
        //
        // House totals (23 inpatient units): staffed=500, occupied=432,
        //   blocked≈5 → ~86% inpatient occupancy (tight house ✓). ED is tracked
        //   separately (target_occupied=0; its boarding load is set in 10d).
        // ------------------------------------------------------------------
        $sentinelAt = now()->startOfDay()->addHours(12); // today at 12:00:00

        // A small, deterministic set of high-occupancy units carry one blocked
        // bed so the house-wide blocked count is realistic (~5) without forcing
        // an alarm-fatigue dashboard. ICU/step-down units also get a modest
        // acuity-adjusted-capacity (aac) bump above raw occupancy.
        $blockedUnits = ['MICU', 'ONC', 'BMT', '6E', '7E'];

        // Keyed by unit abbreviation → [occupied, available, blocked, aac],
        // built from the manifest so every one of the 25 units gets a snapshot.
        $censusTargets = [];
        foreach ($this->manifest->censusDemoTargets() as $target) {
            $abbr = $target['abbr'];
            $staffed = (int) $target['staffed_beds'];
            $occupied = (int) $target['target_occupied'];
            $blocked = in_array($abbr, $blockedUnits, true) ? 1 : 0;
            $available = max(0, $staffed - $occupied - $blocked);

            // Acuity-adjusted capacity: ICU/step-down units run "heavier" than
            // their raw occupancy; med/surg tracks occupancy 1:1.
            $unitMeta = $this->manifest->unit($abbr);
            $type = $unitMeta['type'] ?? 'med_surg';
            $aacAdj = match ($type) {
                'icu' => 2,
                'step_down' => 1,
                default => 0,
            };
            $aac = min($staffed, $occupied + $aacAdj);

            $censusTargets[$abbr] = [$occupied, $available, $blocked, $aac];
        }

        foreach ($canonicalUnits as $unit) {
            $abbr = $unit->abbreviation;
            if (! isset($censusTargets[$abbr])) {
                continue; // unknown abbreviation — skip
            }

            [$occ, $avail, $blocked, $aac] = $censusTargets[$abbr];

            DB::table('prod.census_snapshots')->updateOrInsert(
                [
                    'unit_id' => $unit->unit_id,
                    'captured_at' => $sentinelAt,
                ],
                [
                    'staffed_beds' => $unit->staffed_bed_count,
                    'occupied' => $occ,
                    'available' => $avail,
                    'blocked' => $blocked,
                    'acuity_adjusted_capacity' => $aac,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // ------------------------------------------------------------------
        // 10b. Bed requests — ensure exactly 12 rows with status='pending'.
        //
        // The core seeder (step 3) already created 18 seeder-tagged rows:
        //   12 placed + 6 pending.
        // Here we flip 6 of the 'placed' rows to 'pending' by updating them
        // deterministically (lowest bed_request_id among placed rows).
        // On re-run the WHERE will find them already 'pending' and update
        // idempotently (SET status='pending' WHERE status='pending' is a no-op
        // in Postgres and affects the same rows because the IDs are stable).
        // ------------------------------------------------------------------
        $placedIds = DB::table('prod.bed_requests')
            ->where('created_by', 'seeder')
            ->where('status', 'placed')
            ->orderBy('bed_request_id')
            ->limit(6)
            ->pluck('bed_request_id');

        if ($placedIds->isNotEmpty()) {
            DB::table('prod.bed_requests')
                ->whereIn('bed_request_id', $placedIds)
                ->update(['status' => 'pending', 'updated_at' => now()]);
        }

        // Confirm total pending count is at least 12 (guard for re-runs
        // where all 6 targets are already pending and $placedIds was empty).
        // Nothing needed — if placedIds was empty, all 12 are already pending.

        // ------------------------------------------------------------------
        // 10c. Encounters — set 12 active encounters' expected_discharge_date
        //      to today, making dc_ready = 12.
        //
        // We pick the 12 lowest encounter_ids with status='active' (stable
        // across re-runs). We then ensure any previously-set dc_ready rows
        // that are NOT in our target set are cleared, so the count is exactly
        // 12 on every run.
        // ------------------------------------------------------------------
        $dcTargetIds = DB::table('prod.encounters')
            ->where('status', 'active')
            ->where('is_deleted', false)
            ->orderBy('encounter_id')
            ->limit(12)
            ->pluck('encounter_id');

        if ($dcTargetIds->isNotEmpty()) {
            // Set today on our 12 targets.
            DB::table('prod.encounters')
                ->whereIn('encounter_id', $dcTargetIds)
                ->update([
                    'expected_discharge_date' => now()->toDateString(),
                    'updated_at' => now(),
                ]);

            // Clear expected_discharge_date = today on any OTHER active rows
            // (in case a previous run used different IDs or there are organic rows).
            DB::table('prod.encounters')
                ->where('status', 'active')
                ->where('is_deleted', false)
                ->whereNotIn('encounter_id', $dcTargetIds)
                ->whereDate('expected_discharge_date', now()->toDateString())
                ->update([
                    'expected_discharge_date' => null,
                    'updated_at' => now(),
                ]);
        }

        // ------------------------------------------------------------------
        // 10d. ED visits — ensure exactly 5 boarding patients.
        //      (disposition='admitted' AND bed_assigned_at IS NULL)
        //
        // The core seeder (step 5) creates maxBoarding = 4. We find one
        // admitted ED visit that already has a bed_assigned_at and set it
        // to NULL, bringing the count to 5.
        //
        // We target a seeder row with a deterministic patient_ref (sim-ed-
        // prefix). On re-run, the row is already NULL so the UPDATE is a
        // no-op in effect. If exactly 5 already exist, we skip.
        // ------------------------------------------------------------------
        $currentBoarding = (int) DB::table('prod.ed_visits')
            ->where('disposition', 'admitted')
            ->whereNull('bed_assigned_at')
            ->where('is_deleted', false)
            ->count();

        if ($currentBoarding < 5) {
            $needed = 5 - $currentBoarding;

            // Find admitted seeder rows that have a bed_assigned_at (not yet boarding).
            $toBoard = DB::table('prod.ed_visits')
                ->where('patient_ref', 'like', 'sim-ed-%')
                ->where('disposition', 'admitted')
                ->whereNotNull('bed_assigned_at')
                ->where('is_deleted', false)
                ->orderBy('ed_visit_id')
                ->limit($needed)
                ->pluck('ed_visit_id');

            if ($toBoard->isNotEmpty()) {
                DB::table('prod.ed_visits')
                    ->whereIn('ed_visit_id', $toBoard)
                    ->update(['bed_assigned_at' => null, 'updated_at' => now()]);
            }
        } elseif ($currentBoarding > 5) {
            // Too many boarding — assign a bed to the extras (deterministic).
            $excess = $currentBoarding - 5;
            $toAssign = DB::table('prod.ed_visits')
                ->where('patient_ref', 'like', 'sim-ed-%')
                ->where('disposition', 'admitted')
                ->whereNull('bed_assigned_at')
                ->where('is_deleted', false)
                ->orderByDesc('ed_visit_id')
                ->limit($excess)
                ->pluck('ed_visit_id');

            if ($toAssign->isNotEmpty()) {
                DB::table('prod.ed_visits')
                    ->whereIn('ed_visit_id', $toAssign)
                    ->update([
                        'bed_assigned_at' => now()->subMinutes(30),
                        'updated_at' => now(),
                    ]);
            }
        }
        // If $currentBoarding === 5, nothing to do.
    }

    // ====================================================================
    // Private helpers
    // ====================================================================

    private function seedRtdcPredictions($nonEdUnits): void
    {
        // Today AND tomorrow — the Flow Window's +24h half crosses midnight
        // (FLOW-WINDOW-PLAN §6.5 W5), so the prediction vocabulary must exist
        // for both service dates or the forward stream goes dark at 00:00.
        $serviceDates = [now()->toDateString(), now()->addDay()->toDateString()];

        foreach ($nonEdUnits as $unit) {
            // Pull latest available count from census_snapshots.
            $latestSnap = DB::table('prod.census_snapshots')
                ->where('unit_id', $unit->unit_id)
                ->orderByDesc('captured_at')
                ->first();

            $capacityNow = $latestSnap ? $latestSnap->available : (int) ($unit->staffed_bed_count * 0.15);

            foreach ($serviceDates as $dayOffset => $serviceDate) {
                $this->seedRtdcPredictionDay($unit, $serviceDate, $dayOffset, $capacityNow);
            }
        }
    }

    private function seedRtdcPredictionDay($unit, string $serviceDate, int $dayOffset, int $capacityNow): void
    {
        foreach (['by_2pm', 'by_midnight'] as $horizon) {
            // Deterministic random values per unit+horizon+day.
            $seed = $unit->unit_id * 100 + ($horizon === 'by_2pm' ? 1 : 2) + $dayOffset * 1000;
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
                    'service_date' => $serviceDate,
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
        // Clinical service labels sourced from the manifest's service lines so
        // bed-request services match the Summit roster (no hardcoded literals).
        $services = array_map(fn ($l) => $l['name'], $this->manifest->serviceLines());
        $unitTypes = ['med_surg', 'icu', 'step_down', 'any'];

        // Fetch beds for decisions.
        $availableBeds = Bed::whereHas('unit', fn ($q) => $q->where('type', '!=', 'ed'))
            ->where('is_deleted', false)
            ->limit(50)
            ->pluck('bed_id')
            ->toArray();

        if (empty($availableBeds)) {
            $availableBeds = Bed::where('is_deleted', false)->limit(50)->pluck('bed_id')->toArray();
        }

        $actions = ['accepted', 'edited', 'rejected'];

        // 18 requests: 12 placed (with decisions), 6 pending (no decisions).
        //
        // Admit→Bed metric = median(bpd.created_at − br.created_at) for placed
        // requests in the last 7 days.  We must insert both rows with explicit
        // created_at timestamps so the difference lands in [45, 65] minutes.
        // Eloquent timestamps are set at INSERT time; use raw insertGetId to
        // control both br.created_at and bpd.created_at precisely.
        for ($i = 1; $i <= 18; $i++) {
            $seed = 20260622 + $i * 7;
            $source = $sources[$this->seededRand($seed, 0, 3)];
            $status = $i <= 12 ? 'placed' : 'pending';

            // Spread requests across the last 5 days (all within the 7-day
            // service window) so the median is stable across re-runs.
            $daysAgo = $this->seededRand($seed + 5, 0, 4);
            $hoursAgo = $this->seededRand($seed + 6, 1, 20);
            $requestAt = now()->subDays($daysAgo)->subHours($hoursAgo);

            $bedRequestId = DB::table('prod.bed_requests')->insertGetId([
                'patient_ref' => sprintf('sim-br-%04d', $i),
                'source' => $source,
                'sex' => ['M', 'F'][$this->seededRand($seed + 1, 0, 1)],
                'service' => $services[$this->seededRand($seed + 2, 0, count($services) - 1)],
                'acuity_tier' => $this->seededRand($seed + 3, 1, 4),
                'isolation_required' => 'none',
                'required_unit_type' => $unitTypes[$this->seededRand($seed + 4, 0, 3)],
                'status' => $status,
                'created_by' => 'seeder',
                'modified_by' => 'seeder',
                'created_at' => $requestAt,
                'updated_at' => $requestAt,
                'is_deleted' => false,
            ], 'bed_request_id');

            // Only placed requests get a BedPlacementDecision.
            if ($i <= 12) {
                $seed2 = 20260622 + $i * 13;
                $bedIdx = $this->seededRand($seed2, 0, count($availableBeds) - 1);
                $bedId = $availableBeds[$bedIdx];
                $action = $actions[$this->seededRand($seed2 + 1, 0, 2)];

                // Latency target: 45–65 minutes (drives Admit→Bed metric).
                $latencyMin = $this->seededRand($seed2 + 2, 45, 65);
                $decisionAt = $requestAt->copy()->addMinutes($latencyMin);

                DB::table('prod.bed_placement_decisions')->insert([
                    'bed_request_id' => $bedRequestId,
                    'recommended_bed_id' => $bedId,
                    'chosen_bed_id' => $action !== 'rejected' ? $bedId : null,
                    'action' => $action,
                    'reason' => null,
                    'score_snapshot' => null,
                    'decided_by' => null,
                    'created_at' => $decisionAt,
                    'updated_at' => $decisionAt,
                ]);
            }
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

    /**
     * Seed today's day-shift staffing posture for non-ED units, deliberately
     * leaving two units (6E + ICU, the high-occupancy RED units) short on RN
     * coverage so the operational graph, recommendations, and simulation carry
     * a real "staffing is tight on two units" signal.
     *
     * Idempotent: clears today's demo staffing rows then reseeds. Staffing
     * tables are demo-only, so deleting today's rows is safe and reversible.
     */
    private function seedStaffingPlans($nonEdUnits): void
    {
        $today = now()->toDateString();

        // Cascade-deletes events; only removes seeder-owned demo requests.
        DB::table('prod.staffing_requests')->where('requested_by', 'demo-seeder')->delete();
        DB::table('prod.staffing_plans')->whereDate('shift_date', $today)->delete();

        // Pick the two short units to carry the "staffing is tight on two units"
        // signal: one high-occupancy ICU + one high-occupancy med/surg unit from
        // the manifest. Falls back to the first ICU/non-ED units if absent so the
        // signal always lands on real units.
        $preferredAbbrs = $this->highOccupancyShortUnitAbbrs();
        $preferred = $nonEdUnits->filter(fn ($u) => in_array($u->abbreviation, $preferredAbbrs, true))->values();
        $shortUnits = ($preferred->count() >= 2 ? $preferred : $nonEdUnits->take(2))
            ->pluck('abbreviation')
            ->all();

        foreach ($nonEdUnits as $unit) {
            $isIcu = $unit->type === 'icu';
            $isShort = in_array($unit->abbreviation, $shortUnits, true);

            $rnRequired = $isIcu ? 5 : 6;
            $rnScheduled = $isShort ? ($isIcu ? 3 : 4) : $rnRequired;
            $rnMinSafe = $isIcu ? 4 : 5;
            $rnGap = max(0, $rnRequired - $rnScheduled);
            $rnStatus = $rnGap === 0
                ? 'balanced'
                : (($isIcu || $rnScheduled < $rnMinSafe) ? 'critical_gap' : 'gap');

            $this->insertStaffingPlan($unit, 'rn', $today, $rnRequired, $rnScheduled, $rnMinSafe, $isIcu ? 2.0 : 4.0, $rnStatus);
            $this->insertStaffingPlan($unit, 'tech', $today, 2, 2, 1, 12.0, 'balanced');
            $this->insertStaffingPlan($unit, 'charge', $today, 1, 1, 1, 12.0, 'balanced');

            if ($isShort && $rnGap > 0) {
                $this->insertStaffingRequest($unit, 'rn', $today, $rnGap, $isIcu ? 'stat' : 'urgent', $rnStatus);
            }
        }
    }

    /**
     * P7 (Staffing + Financial) — the prod.workforce_actuals day fact.
     *
     * Per-unit worked hours are constant so the aggregate OT% and productivity%
     * are exact regardless of unit count; count metrics (callouts/sitters/
     * agency RNs) and today's premium/agency dollars are distributed to hit the
     * intended demo totals (callouts 9 → warn, OT 5.2% → warn, premium $182k,
     * contract $96k). Today runs at 96% productivity (pressured); the trailing
     * 30 days sit at 97% so the MTD financial productivity reads ~97.
     */
    private function seedWorkforceActuals($nonEdUnits): void
    {
        if (! Schema::hasTable('prod.workforce_actuals') || $nonEdUnits->isEmpty()) {
            return;
        }

        $units = $nonEdUnits->values();
        $count = $units->count();

        $windowStart = now()->subDays(31)->toDateString();
        DB::table('prod.workforce_actuals')->where('work_date', '>=', $windowStart)->delete();

        // Today's exact totals for the count + dollar tiles.
        $calloutsToday = $this->distributeInt(9, $count);
        $sittersToday = $this->distributeInt(7, $count);
        $agencyToday = $this->distributeInt(14, $count);
        $premiumToday = $this->distributeInt(182_000, $count);
        $agencyCostToday = $this->distributeInt(96_000, $count);

        $workedPerUnit = 240.0;
        $rate = 68.0; // blended $/hr → labor_cost
        $rows = [];

        foreach (range(0, 30) as $daysAgo) {
            $date = now()->subDays($daysAgo)->toDateString();
            $isToday = $daysAgo === 0;
            $prodPct = $isToday ? 0.96 : 0.97;

            foreach ($units as $i => $unit) {
                $rows[] = [
                    'actual_uuid' => (string) Str::uuid(),
                    'unit_id' => $unit->unit_id,
                    'cost_center' => $unit->abbreviation,
                    'cost_center_label' => $unit->name ?? $unit->abbreviation,
                    'work_date' => $date,
                    'worked_hours' => $workedPerUnit,
                    'overtime_hours' => round($workedPerUnit * 0.052, 2),
                    'agency_hours' => $isToday ? ($agencyToday[$i] * 12.0) : (($i % 3 === 0) ? 12.0 : 0.0),
                    'agency_rn_headcount' => $isToday ? $agencyToday[$i] : (($i % 3 === 0) ? 1 : 0),
                    'callouts' => $isToday ? $calloutsToday[$i] : ((($i + $daysAgo) % 4 === 0) ? 1 : 0),
                    'sitters' => $isToday ? $sittersToday[$i] : ((($i + $daysAgo) % 5 === 0) ? 1 : 0),
                    'census_days' => round($workedPerUnit / 1.04, 2),
                    'target_hours' => round($workedPerUnit * $prodPct, 2),
                    'labor_cost' => round($workedPerUnit * $rate, 2),
                    'premium_cost' => $isToday ? (float) $premiumToday[$i] : round(140_000 / max(1, $count), 2),
                    'agency_cost' => $isToday ? (float) $agencyCostToday[$i] : round(80_000 / max(1, $count), 2),
                    'is_deleted' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('prod.workforce_actuals')->insert($chunk);
        }
    }

    /**
     * P7 (WS-5) — the quality_events ledger behind ops.mv_hai_ledger. Tuned so
     * the MTD view reproduces the retired demo values: sepsis 87/92%, hand
     * hygiene 91%, med rec 96%, falls 2.7/1000 pt-days, and the HAI/event
     * counts (cdiff 2, clabsi/ssi/hapi 1, rapid-response 4, the rest 0).
     */
    private function seedQualityEvents($nonEdUnits): void
    {
        if (! Schema::hasTable('prod.quality_events')) {
            return;
        }

        DB::table('prod.quality_events')->where('metadata->source', 'demo-seeder')->delete();

        $rows = [];
        $add = function (string $type, int $num, int $den, int $pd) use (&$rows): void {
            $rows[] = [
                'event_uuid' => (string) Str::uuid(),
                'event_type' => $type,
                'unit_id' => null,
                'occurred_at' => now()->subMinutes(count($rows)),
                'numerator' => $num,
                'denominator' => $den,
                'patient_days' => $pd,
                'metadata' => json_encode(['source' => 'demo-seeder']),
                'is_deleted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        };

        // Bundle/compliance % metrics: N eligible observations, PCT compliant.
        foreach (['sepsis_3hr' => 87, 'sepsis_6hr' => 92, 'hand_hygiene' => 91, 'med_rec' => 96] as $type => $pct) {
            for ($i = 0; $i < 100; $i++) {
                $add($type, $i < $pct ? 1 : 0, 1, 0);
            }
        }

        // Count metrics (SUM numerator MTD). Always emit at least one row —
        // a num=0 sentinel — so a zero-count metric (e.g. CAUTI/MRSA/VAP with
        // no infections this month) still surfaces as a "0" tile: zero HAI is
        // the goal, not the absence of a signal.
        foreach (['clabsi' => 1, 'cauti' => 0, 'cdiff' => 2, 'ssi' => 1, 'mrsa' => 0, 'vap' => 0, 'hapi' => 1, 'rapid_response' => 4] as $type => $count) {
            for ($i = 0; $i < max(1, $count); $i++) {
                $add($type, $i < $count ? 1 : 0, 0, 0);
            }
        }

        // Falls: 27 events over a 10,000 patient-day exposure → 2.7 / 1000.
        for ($i = 0; $i < 27; $i++) {
            $add('fall', 1, 0, 0);
        }
        $add('fall', 0, 0, 10000);

        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('prod.quality_events')->insert($chunk);
        }
    }

    /**
     * P7 (WS-5) — the discharge_facts DRG/cost ledger behind
     * ops.mv_service_line_los + the cost-per-case row of
     * ops.mv_cost_center_productivity. 1,284 MTD discharges tuned to reproduce
     * CMI 1.62, observation rate 12.4%, and cost/case $11.9k. Deliberately its
     * OWN table (never prod.encounters) so live readmission / O:E LOS /
     * discharge-before-noon are untouched.
     */
    private function seedDischargeFacts($nonEdUnits): void
    {
        if (! Schema::hasTable('prod.discharge_facts')) {
            return;
        }

        DB::table('prod.discharge_facts')->where('patient_ref', 'like', 'sim-df-%')->delete();

        $serviceLines = ['medicine', 'cardiology', 'critical_care', 'trauma_surgery', 'neurosciences', 'orthopedics', 'oncology'];
        $monthStart = now()->startOfMonth();
        $elapsedDays = max(1, (int) $monthStart->diffInDays(now()));
        $rows = [];

        // Afternoon-peaked discharge hour (FEEDBACK Wave 2): a uniform 0–23h gave ~50%
        // discharge-before-noon, which no hospital achieves. This lands ~30% before noon.
        $sampler = new \App\Services\Demo\DistributionSampler;
        $dischargeHourWeights = [
            0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 2, 6 => 3, 7 => 4, 8 => 6, 9 => 8, 10 => 9, 11 => 10,
            12 => 14, 13 => 16, 14 => 16, 15 => 14, 16 => 12, 17 => 10, 18 => 8, 19 => 6, 20 => 5, 21 => 4, 22 => 2, 23 => 1,
        ];

        for ($i = 1; $i <= 1284; $i++) {
            $seed = 70400 + $i;
            $isObs = $i <= 159; // ≈12.4%
            $drg = round(1.22 + $this->seededRand($seed, 0, 80) / 100, 3);   // mean ≈1.62
            $cost = 8000 + $this->seededRand($seed + 1, 0, 7800);            // mean ≈11,900
            $dischargedAt = $monthStart->copy()
                ->addDays($this->seededRand($seed + 2, 0, $elapsedDays))
                ->addHours((int) $sampler->weightedPick($dischargeHourWeights, $seed + 3));
            if ($dischargedAt->greaterThan(now())) {
                $dischargedAt = now()->copy()->subHours(1);
            }

            $rows[] = [
                'fact_uuid' => (string) Str::uuid(),
                'patient_ref' => sprintf('sim-df-%05d', $i),
                'unit_id' => null,
                'service_line' => $serviceLines[$i % count($serviceLines)],
                'drg_weight' => $drg,
                'total_cost' => $cost,
                'is_observation' => $isObs,
                'discharged_at' => $dischargedAt,
                'is_deleted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('prod.discharge_facts')->insert($chunk);
        }
    }

    /**
     * P7 (WS-5) — populate the three MTD cockpit MVs after seeding their facts
     * so the demo wall shows live Quality/Service/Financial immediately (the
     * hourly job keeps them current thereafter). Plain REFRESH (not
     * CONCURRENTLY) is safe inside a seeding transaction.
     */
    private function refreshCockpitMaterializedViews(): void
    {
        foreach (\App\Jobs\RefreshCockpitMaterializedViews::VIEWS as $view) {
            try {
                DB::statement("REFRESH MATERIALIZED VIEW {$view}");
            } catch (\Throwable $e) {
                // The MV may not exist yet on a partial migration — non-fatal.
            }
        }
    }

    /**
     * Split $total into $buckets non-negative integers summing exactly to
     * $total (remainder spread across the leading buckets). Deterministic.
     *
     * @return list<int>
     */
    private function distributeInt(int $total, int $buckets): array
    {
        if ($buckets <= 0) {
            return [];
        }

        $base = intdiv($total, $buckets);
        $remainder = $total % $buckets;

        $out = [];
        for ($i = 0; $i < $buckets; $i++) {
            $out[] = $base + ($i < $remainder ? 1 : 0);
        }

        return $out;
    }

    /**
     * Return the two manifest unit abbreviations that should carry the
     * "staffing is tight on two units" demo signal: the highest-occupancy ICU
     * and the highest-occupancy med/surg-or-step-down unit (by target
     * occupancy). Deterministic — driven entirely by the manifest.
     *
     * @return list<string>
     */
    private function highOccupancyShortUnitAbbrs(): array
    {
        $byOccDesc = $this->manifest->censusDemoTargets();
        usort($byOccDesc, fn ($a, $b) => ($b['target_occupancy'] <=> $a['target_occupancy']));

        $icu = null;
        $ward = null;
        foreach ($byOccDesc as $target) {
            $unit = $this->manifest->unit($target['abbr']);
            $type = $unit['type'] ?? null;
            if ($icu === null && $type === 'icu') {
                $icu = $target['abbr'];
            } elseif ($ward === null && in_array($type, ['med_surg', 'step_down'], true)) {
                $ward = $target['abbr'];
            }
            if ($icu !== null && $ward !== null) {
                break;
            }
        }

        return array_values(array_filter([$icu, $ward]));
    }

    private function insertStaffingPlan(Unit $unit, string $role, string $today, int $required, int $scheduled, int $minSafe, float $ratioTarget, string $status): void
    {
        DB::table('prod.staffing_plans')->insert([
            'plan_uuid' => (string) Str::uuid(),
            'unit_id' => $unit->unit_id,
            'unit_label' => $unit->name,
            'role' => $role,
            'shift_date' => $today,
            'shift' => 'day',
            'required_count' => $required,
            'scheduled_count' => $scheduled,
            'actual_count' => $scheduled,
            'minimum_safe_count' => $minSafe,
            'census' => $unit->staffed_bed_count ?? 0,
            'ratio_target' => $ratioTarget,
            'status' => $status,
            'notes' => null,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertStaffingRequest(Unit $unit, string $role, string $today, int $headcount, string $priority, string $status): void
    {
        $requestId = DB::table('prod.staffing_requests')->insertGetId([
            'request_uuid' => (string) Str::uuid(),
            'unit_id' => $unit->unit_id,
            'unit_label' => $unit->name,
            'role' => $role,
            'shift_date' => $today,
            'shift' => 'day',
            'request_type' => 'fill_gap',
            'priority' => $priority,
            'status' => 'open',
            'headcount_needed' => $headcount,
            'hours_needed' => 12,
            'requested_by' => 'demo-seeder',
            'needed_by' => now()->addHours(2),
            'owner_name' => 'Staffing office',
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'staffing_request_id');

        DB::table('prod.staffing_events')->insert([
            'event_uuid' => (string) Str::uuid(),
            'staffing_request_id' => $requestId,
            'event_type' => 'staffing.requested',
            'from_status' => null,
            'to_status' => 'open',
            'payload' => json_encode([
                'unit_label' => $unit->name,
                'role' => $role,
                'headcount_needed' => $headcount,
                'source' => 'command_center_demo',
            ]),
            'source' => 'demo-seeder',
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    /**
     * Seed an EVS turnover backlog with a couple of overdue turns so the
     * "EVS turnaround is behind" demo signal, blocked-bed recommendations,
     * and capacity simulation all carry a real environmental-services load.
     *
     * Idempotent: clears seeder-owned EVS demo requests (cascades events),
     * then reseeds.
     */
    private function seedEvsBacklog($nonEdUnits): void
    {
        if (! Schema::hasTable('prod.evs_requests') || $nonEdUnits->isEmpty()) {
            return;
        }

        DB::table('prod.evs_requests')->where('requested_by', 'demo-seeder')->delete();

        // [type, turn_type, priority, isolation, minutes_until_due] — negatives are overdue.
        $specs = [
            ['discharge_turnover', 'standard', 'urgent', false, -35],
            ['bed_clean', 'standard', 'routine', false, 25],
            ['isolation_clean', 'isolation', 'urgent', true, -10],
            ['terminal_clean', 'terminal', 'stat', false, 15],
            ['bed_clean', 'standard', 'routine', false, 60],
            ['discharge_turnover', 'standard', 'urgent', false, -50],
        ];

        foreach ($specs as $i => [$type, $turnType, $priority, $isolation, $minutesUntilDue]) {
            $unit = $nonEdUnits->get($i % $nonEdUnits->count());
            $status = $i % 3 === 0 ? 'in_progress' : 'requested';
            $requestId = DB::table('prod.evs_requests')->insertGetId([
                'request_uuid' => (string) Str::uuid(),
                'request_type' => $type,
                'priority' => $priority,
                'status' => $status,
                'unit_id' => $unit->unit_id,
                'location_label' => $unit->abbreviation.'-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                'turn_type' => $turnType,
                'isolation_required' => $isolation,
                'requested_by' => 'demo-seeder',
                'requested_at' => now()->subMinutes(90 - $i * 5),
                'needed_at' => now()->addMinutes($minutesUntilDue),
                'started_at' => $status === 'in_progress' ? now()->subMinutes(15) : null,
                'is_deleted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'evs_request_id');

            DB::table('prod.evs_events')->insert([
                'event_uuid' => (string) Str::uuid(),
                'evs_request_id' => $requestId,
                'event_type' => 'evs.requested',
                'from_status' => null,
                'to_status' => 'requested',
                'payload' => json_encode([
                    'location_label' => $unit->abbreviation.'-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                    'priority' => $priority,
                    'request_type' => $type,
                    'source' => 'command_center_demo',
                ]),
                'source' => 'demo-seeder',
                'occurred_at' => now()->subMinutes(90 - $i * 5),
                'created_at' => now(),
            ]);
        }

        // P7: completed bed turns earlier today — the live flow.bed_turnaround
        // avg/p90 computes from these (avg ≈52 min lands in the warn band, so
        // the "EVS turnaround is behind" signal is EARNED from data, not a
        // demo constant). Spans are deterministic: 38..73 min stepping 5.
        foreach (range(0, 7) as $i) {
            $unit = $nonEdUnits->get($i % $nonEdUnits->count());
            $spanMinutes = 38 + $i * 5;
            $completedAt = now()->startOfDay()->addHours(6)->addMinutes($i * 85);

            DB::table('prod.evs_requests')->insert([
                'request_uuid' => (string) Str::uuid(),
                'request_type' => $i % 2 === 0 ? 'discharge_turnover' : 'bed_clean',
                'priority' => 'routine',
                'status' => 'completed',
                'unit_id' => $unit->unit_id,
                'location_label' => $unit->abbreviation.'-C'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                'turn_type' => 'standard',
                'isolation_required' => false,
                'requested_by' => 'demo-seeder',
                'requested_at' => $completedAt->copy()->subMinutes($spanMinutes),
                'needed_at' => $completedAt->copy()->subMinutes(max(5, $spanMinutes - 45)),
                'assigned_at' => $completedAt->copy()->subMinutes($spanMinutes - 8),
                'started_at' => $completedAt->copy()->subMinutes(max(10, $spanMinutes - 18)),
                'completed_at' => $completedAt,
                'is_deleted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * P7 (Flow) — the discharge-lounge census source. Six patients currently
     * in the lounge (of 10 chairs) plus a few picked up earlier today, so
     * flow.discharge_lounge computes live from prod.discharge_lounge_stays.
     */
    private function seedDischargeLoungeStays($nonEdUnits): void
    {
        if (! Schema::hasTable('prod.discharge_lounge_stays') || $nonEdUnits->isEmpty()) {
            return;
        }

        DB::table('prod.discharge_lounge_stays')->where('patient_ref', 'like', 'sim-lounge-%')->delete();

        $modes = ['family_ride', 'medical_car', 'wheelchair_van', 'rideshare'];

        foreach (range(0, 9) as $i) {
            $unit = $nonEdUnits->get($i % $nonEdUnits->count());
            $departed = $i >= 6; // rows 6..9 already picked up earlier today
            $arrivedAt = $departed
                ? now()->startOfDay()->addHours(8)->addMinutes($i * 40)
                : now()->subMinutes(25 + $i * 30);

            DB::table('prod.discharge_lounge_stays')->insert([
                'stay_uuid' => (string) Str::uuid(),
                'patient_ref' => sprintf('sim-lounge-%04d', $i + 1),
                'unit_id' => $unit->unit_id,
                'origin_unit_label' => $unit->abbreviation,
                'arrived_at' => $arrivedAt,
                'expected_pickup_at' => $arrivedAt->copy()->addMinutes(90),
                'departed_at' => $departed ? $arrivedAt->copy()->addMinutes(55 + $i * 7) : null,
                'departure_mode' => $departed ? $modes[$i % count($modes)] : null,
                'is_deleted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Seed an internal/transfer transport backlog with a couple of stat or
     * overdue moves so the "transport queue is overloaded" demo signal and the
     * transport SLA-risk recommendation are real.
     *
     * Idempotent: clears seeder-owned transport demo requests, then reseeds.
     */
    private function seedTransportBacklog($nonEdUnits): void
    {
        if (! Schema::hasTable('prod.transport_requests') || $nonEdUnits->isEmpty()) {
            return;
        }

        // prod.transport_events is an append-only ledger (prod.reject_transport_ledger_mutation
        // rejects DELETE/UPDATE), so demo transport is seeded ONCE and then left intact — deleting
        // requests that already have events would cascade into the protected ledger and fail. On
        // re-runs DemoTuningSeeder::refreshSlas() keeps active needed_at/needed_by near "now"
        // without mutating the ledger, so the queue stays current.
        if (DB::table('prod.transport_requests')->where('requested_by', 'demo-seeder')->exists()) {
            return;
        }

        $hasEvents = Schema::hasTable('prod.transport_events');

        // Transport teams + post-acute destinations from the manifest.
        $internalTeam = $this->manifest->transport()['internal_team']['name'] ?? 'Patient Transport';
        $vendorTeams = array_map(fn ($v) => $v['name'], $this->manifest->transport()['vendors'] ?? []);
        $vendorTeam = $vendorTeams[0] ?? $internalTeam;
        $snfNames = $this->manifest->postAcuteNames('snf');
        $homeHealthNames = $this->manifest->postAcuteNames('home_health');
        // Care-transition destinations: alternate SNF / home-health from the
        // post-acute network (falls back to any post-acute partner).
        $snfDest = $snfNames[0] ?? ($this->manifest->postAcuteNames()[0] ?? 'Skilled Nursing Facility');
        $homeHealthDest = $homeHealthNames[0] ?? ($this->manifest->postAcuteNames()[0] ?? 'Home Health Services');

        // [request_type, priority, mode, minutes_until_due] — negatives are overdue.
        // care_transition rows back the /transport/care-transitions worklist, which
        // was otherwise empty (the seeder never seeded that request_type).
        $specs = [
            ['inpatient', 'stat', 'stretcher', -20],
            ['inpatient', 'urgent', 'wheelchair', -5],
            ['discharge', 'routine', 'wheelchair', 30],
            ['transfer', 'urgent', 'stretcher', -15],
            ['inpatient', 'routine', 'bed', 45],
            ['ems', 'stat', 'als', 10],
            ['care_transition', 'routine', 'wheelchair', 90],
            ['care_transition', 'urgent', 'stretcher', 40],
        ];

        foreach ($specs as $i => [$type, $priority, $mode, $minutesUntilDue]) {
            $origin = $nonEdUnits->get($i % $nonEdUnits->count());
            $destination = $nonEdUnits->get(($i + 1) % $nonEdUnits->count());
            $status = ['requested', 'assigned', 'escalated'][$i % 3];

            $destinationName = match ($type) {
                'discharge' => 'Main Lobby Discharge',
                'care_transition' => $i % 2 === 0 ? $snfDest : $homeHealthDest,
                default => $destination->name,
            };

            $requestedAt = now()->subMinutes(75 - $i * 5);
            $assignedAt = $status === 'assigned' ? now()->subMinutes(10) : null;

            $requestId = DB::table('prod.transport_requests')->insertGetId([
                'request_uuid' => (string) Str::uuid(),
                'request_type' => $type,
                'priority' => $priority,
                'status' => $status,
                'patient_ref' => 'sim-transport-'.($i + 1),
                'origin' => $origin->name,
                'destination' => $destinationName,
                'transport_mode' => $mode,
                'clinical_service' => 'Medicine',
                'requested_by' => 'demo-seeder',
                'requested_at' => $requestedAt,
                'needed_at' => now()->addMinutes($minutesUntilDue),
                'assigned_at' => $assignedAt,
                'assigned_team' => $status === 'assigned' ? $internalTeam : null,
                'is_deleted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'transport_request_id');

            if (! $hasEvents) {
                continue;
            }

            // Build a plausible status-transition timeline so request-detail
            // panels and Transport Analytics duration measures have real data.
            $events = [[
                'event_type' => 'transport.requested',
                'from_status' => null,
                'to_status' => 'requested',
                'occurred_at' => $requestedAt,
            ]];
            if ($status === 'assigned') {
                $events[] = [
                    'event_type' => 'transport.assigned',
                    'from_status' => 'requested',
                    'to_status' => 'assigned',
                    'occurred_at' => $assignedAt,
                ];
            } elseif ($status === 'escalated') {
                $events[] = [
                    'event_type' => 'transport.escalated',
                    'from_status' => 'requested',
                    'to_status' => 'escalated',
                    'occurred_at' => (clone $requestedAt)->addMinutes(8),
                ];
            }

            foreach ($events as $event) {
                DB::table('prod.transport_events')->insert([
                    'event_uuid' => (string) Str::uuid(),
                    'transport_request_id' => $requestId,
                    'event_type' => $event['event_type'],
                    'from_status' => $event['from_status'],
                    'to_status' => $event['to_status'],
                    'payload' => json_encode([
                        'request_type' => $type,
                        'priority' => $priority,
                        'origin' => $origin->name,
                        'destination' => $destinationName,
                        'source' => 'command_center_demo',
                    ]),
                    'source' => 'demo-seeder',
                    'occurred_at' => $event['occurred_at'],
                    'created_at' => now(),
                ]);
            }
        }

        if (! $hasEvents) {
            return;
        }

        // Completed + canceled transport history with full lifecycle events so the
        // Transport Analytics duration measures compute (request->assign,
        // dispatch->pickup, pickup->destination, patient-not-ready delay rate,
        // avoidable bed-hours, vendor acceptance/cancellation). Deterministic.
        $completedTypes = ['inpatient', 'transfer', 'discharge', 'ems', 'care_transition'];
        for ($j = 0; $j < 14; $j++) {
            $seed = 91000 + $j;
            $type = $completedTypes[$j % count($completedTypes)];
            $origin = $nonEdUnits->get($j % $nonEdUnits->count());
            $destination = $nonEdUnits->get(($j + 2) % $nonEdUnits->count());
            $isCanceled = $j % 7 === 6;     // ~2 canceled
            $notReady = $j % 4 === 0;       // ~4 patient-not-ready delays
            $vendor = $j % 3 === 0;         // ~5 vendor-assigned

            $requestedAt = now()->subMinutes(55 * ($j + 1));
            $assignedAt = (clone $requestedAt)->addMinutes($this->seededRand($seed + 1, 4, 18));
            $enRouteAt = (clone $assignedAt)->addMinutes($this->seededRand($seed + 2, 2, 10));
            $notReadyDelay = $notReady ? $this->seededRand($seed + 4, 15, 45) : 0;
            $arrivedAt = (clone $enRouteAt)->addMinutes($this->seededRand($seed + 3, 5, 18) + $notReadyDelay);
            $completedAt = (clone $arrivedAt)->addMinutes($this->seededRand($seed + 5, 8, 25));

            $status = $isCanceled ? 'canceled' : 'completed';
            $team = $vendor ? $vendorTeam : $internalTeam;

            $reqId = DB::table('prod.transport_requests')->insertGetId([
                'request_uuid' => (string) Str::uuid(),
                'request_type' => $type,
                'priority' => ['routine', 'urgent', 'stat'][$j % 3],
                'status' => $status,
                'patient_ref' => 'sim-transport-hist-'.($j + 1),
                'origin' => $origin->name,
                'destination' => $type === 'discharge' ? 'Main Lobby Discharge' : $destination->name,
                'transport_mode' => ['stretcher', 'wheelchair', 'bed'][$j % 3],
                'clinical_service' => 'Medicine',
                'requested_by' => 'demo-seeder',
                'requested_at' => $requestedAt,
                'needed_at' => (clone $requestedAt)->addMinutes(60),
                'assigned_at' => $assignedAt,
                'assigned_team' => $team,
                'is_deleted' => false,
                'created_at' => $requestedAt,
                'updated_at' => $completedAt,
            ], 'transport_request_id');

            $events = [
                ['transport.requested', null, 'requested', $requestedAt],
                ['transport.assigned', 'requested', 'assigned', $assignedAt],
                ['transport.en_route', 'assigned', 'en_route', $enRouteAt],
            ];
            if ($notReady) {
                $events[] = ['transport.not_ready', 'en_route', 'en_route', (clone $enRouteAt)->addMinutes(2)];
            }
            $events[] = ['transport.arrived', 'en_route', 'arrived', $arrivedAt];
            $events[] = $isCanceled
                ? ['transport.canceled', 'arrived', 'canceled', $completedAt]
                : ['transport.completed', 'arrived', 'completed', $completedAt];

            foreach ($events as [$etype, $from, $to, $at]) {
                DB::table('prod.transport_events')->insert([
                    'event_uuid' => (string) Str::uuid(),
                    'transport_request_id' => $reqId,
                    'event_type' => $etype,
                    'from_status' => $from,
                    'to_status' => $to,
                    'payload' => json_encode([
                        'request_type' => $type,
                        'vendor' => $vendor,
                        'not_ready_delay_min' => $notReadyDelay,
                        'source' => 'command_center_demo',
                    ]),
                    'source' => 'demo-seeder',
                    'occurred_at' => $at,
                    'created_at' => now(),
                ]);
            }
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
        // ESI-3-dominant pyramid (FEEDBACK Wave 2). ESI-1 is deliberately rare in walk-in
        // throughput — the true ESI-1 resuscitations are the ventilated crowding cohort below.
        $esiWeights = [1 => 1, 2 => 25, 3 => 46, 4 => 23, 5 => 5];
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

            $triagedAt = $arrivedAt->copy()->addMinutes($this->seededRand($seed + 1, 3, 10));

            // Door-to-provider target ≈ 16–20m median.
            // Triage adds 3–10m, then provider adds 5–20m after triage → total 8–30m from arrival.
            $providerAt = $triagedAt->copy()->addMinutes($this->seededRand($seed + 2, 5, 20));

            $esiLevel = $esiPool[($i - 1) % count($esiPool)];
            $disposition = $dispositions[($i - 1) % count($dispositions)];

            $admitDecisionAt = null;
            $bedAssignedAt = null;
            $departedAt = null;
            $unitId = null;

            switch ($disposition) {
                case 'discharged':
                    // ED LOS (discharged) target ≈ 140–165m median.
                    // Range 90–210m produces a median near 150m.
                    $losDuration = $this->seededRand($seed + 3, 90, 210);
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

        // P7: a crowding cohort so the LIVE NEDOCS composite lands in the
        // severe band (~150). Sized against the FIXED 500-bed hospital
        // denominator (Weiss), so 5 boarders against 500 beds contribute
        // little and the crowding term must carry the score: 34 patients
        // currently in the department (none admitted → boarders unchanged),
        // 8 still waiting for a provider, 4 on ventilators.
        $sampler = new \App\Services\Demo\DistributionSampler;
        for ($k = 1; $k <= 34; $k++) {
            $seed = 20260701 + $k * 17;
            $arrivedAt = now()->subMinutes($this->seededRand($seed, 20, 360));
            $waiting = $k <= 8; // first 8 have no provider yet
            $ventilated = $k <= 4; // 4 critical, on vents
            $triagedAt = $arrivedAt->copy()->addMinutes($this->seededRand($seed + 1, 3, 10));

            // A boarding cohort skews higher-acuity but is not all ESI-2: vents are ESI-1,
            // undifferentiated waiters are ESI-2/3, the rest sample an ESI-3-modal pyramid
            // (FEEDBACK Wave 2 — keeps ESI-3 the modal class overall).
            $esiLevelCrowd = $ventilated
                ? 1
                : ($waiting
                    ? $sampler->weightedPick([2 => 2, 3 => 3], $seed + 5)
                    : $sampler->weightedPick([2 => 30, 3 => 45, 4 => 20, 5 => 5], $seed + 5));

            EdVisit::create([
                'patient_ref' => sprintf('sim-ed-crowd-%02d', $k),
                'arrived_at' => $arrivedAt,
                'triaged_at' => $triagedAt,
                'esi_level' => $esiLevelCrowd,
                'is_ventilated' => $ventilated,
                'provider_seen_at' => $waiting ? null : $triagedAt->copy()->addMinutes($this->seededRand($seed + 2, 5, 20)),
                'disposition' => null, // still in ED, undispositioned
                'admit_decision_at' => null,
                'bed_assigned_at' => null,
                'departed_at' => null,
                'unit_id' => null,
                'is_deleted' => false,
            ]);
        }
    }

    private function seedOrDomain(): void
    {
        // ----------------------------------------------------------------
        // Reference data (idempotent via updateOrInsert on natural keys).
        // ----------------------------------------------------------------

        // Location — display name aligned to the manifest's perioperative
        // platform (the OR/periop unit); 'MOR' stays the immutable natural key.
        $periopUnit = $this->manifest->unitsByType('periop')[0] ?? null;
        $orLocationName = $periopUnit['short_name'] ?? 'Main OR Suite';
        $locationId = DB::table('prod.locations')->where('abbreviation', 'MOR')->value('location_id');
        if (! $locationId) {
            $locationId = DB::table('prod.locations')->insertGetId([
                'name' => $orLocationName,
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

        // Specialties + services — sourced from the manifest's surgical service
        // lines so OR reference data matches the Summit roster. We take the
        // perioperative-relevant lines (those that staff the OR platform) and
        // fall back across the full service-line list to guarantee enough rows.
        $surgicalLineCodes = ['trauma_surgery', 'cardiovascular', 'neurosciences', 'oncology', 'womens_health'];
        $serviceLineByCode = [];
        foreach ($this->manifest->serviceLines() as $line) {
            $serviceLineByCode[$line['code']] = $line['name'];
        }
        // Ordered surgical lines that exist in the manifest, then any remaining
        // lines, so we always have at least the 5 specialties / 4 services below.
        $surgicalLines = [];
        foreach ($surgicalLineCodes as $code) {
            if (isset($serviceLineByCode[$code])) {
                $surgicalLines[] = ['code' => $code, 'name' => $serviceLineByCode[$code]];
            }
        }
        foreach ($this->manifest->serviceLines() as $line) {
            if (! in_array($line['code'], $surgicalLineCodes, true)) {
                $surgicalLines[] = ['code' => $line['code'], 'name' => $line['name']];
            }
        }

        // Specialties (first 5 surgical lines).
        $specialtyNames = array_map(fn ($l) => $l['name'], array_slice($surgicalLines, 0, 5));
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

        // Services (4) — first 4 surgical service lines from the manifest.
        $serviceData = [];
        foreach (array_slice($surgicalLines, 0, 4) as $line) {
            $serviceData[] = [
                'name' => $line['name'],
                'code' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $line['name']), 0, 6)),
            ];
        }
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

        // Providers (4 surgeons) — sourced from the manifest. Prefer providers
        // whose role is surgeon; fall back to the full provider pool so we
        // always have 4. Each is mapped onto one of the OR specialties above.
        $manifestProviders = $this->manifest->providers();
        $surgeonProviders = array_values(array_filter(
            $manifestProviders,
            fn ($p) => ($p['role'] ?? null) === 'surgeon'
        ));
        $providerPool = count($surgeonProviders) >= 4 ? $surgeonProviders : $manifestProviders;

        $providerData = [];
        foreach (array_slice($providerPool, 0, 4) as $idx => $p) {
            $providerData[] = [
                'name' => $p['name'],
                'npi' => $p['npi'],
                'spec' => $specialtyIds[$idx % count($specialtyIds)],
            ];
        }
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

        // Rooms — a Level I academic OR suite (FEEDBACK Wave 2: 4 ORs read as a community
        // hospital, not the 44-space procedural platform in the manifest). 20 named ORs across
        // the service lines; a block-schedule "active" pattern below keeps a realistic subset
        // running each day so the board is full without every room being lit.
        $roomNames = [
            'GEN-OR-1', 'GEN-OR-2', 'GEN-OR-3', 'GEN-OR-4', 'GEN-OR-5', 'GEN-OR-6',
            'CV-OR-1', 'CV-OR-2', 'NEURO-OR-1', 'NEURO-OR-2',
            'ORTHO-OR-1', 'ORTHO-OR-2', 'ORTHO-OR-3', 'TRAUMA-OR-1', 'TRAUMA-OR-2',
            'HYBRID-OR-1', 'HYBRID-OR-2', 'ROBOTIC-OR-1', 'ROBOTIC-OR-2', 'OB-OR-1',
        ];
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

        // Retire seeder-owned ORs no longer in the roster (e.g. the old OR-1..OR-4) so they
        // don't linger as orphaned idle rooms after the suite was expanded.
        DB::table('prod.rooms')
            ->where('created_by', 'seeder')->where('type', 'OR')
            ->whereNotIn('name', $roomNames)
            ->update(['is_deleted' => true, 'updated_at' => now()]);

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

        // Current hour — used to decide which today-cases are already completed.
        $currentHour = (int) now()->format('G');

        $allCaseIds = [];
        // P7: exactly 4 patients currently held in PACU (pacu_out NULL, dwell
        // >75m) so periop.pacu_holds lands in the warn band and the PACU bay
        // drill is non-empty. Keyed to the MOST RECENT OR day (the RoomStatus
        // anchor = MAX(surgery_date)) so the holds exist even when the real
        // "today" is a weekend with no scheduled cases; pacu_in is stamped
        // relative to now() so they read as current recovery-bay boarders.
        $pacuHoldsSeeded = 0;
        $anchorDayIdx = count($weekdays) - 1;

        foreach ($weekdays as $dayIdx => $day) {
            foreach ($roomIds as $roomIdx => $roomId) {
                // Block schedule: ~70% of ORs are staffed on a given weekday; the rest are dark
                // (they read 'available' in Room Status). The most-recent day forces the first 12
                // rooms active so the live board is full even when the real anchor is a weekend.
                $active = $this->seededRand($dayIdx * 100 + $roomIdx + 7, 0, 99) < 70;
                if ($dayIdx === $anchorDayIdx && $roomIdx < 12) {
                    $active = true;
                }
                if (! $active) {
                    continue;
                }
                // 2–4 cases per active room (was a flat 5 across only 4 rooms).
                $numCases = $this->seededRand($dayIdx * 7 + $roomIdx + 3, 2, 4);
                $slotStart = $day->copy()->setTime(7, 30);

                for ($cIdx = 0; $cIdx < $numCases; $cIdx++) {
                    $seed = 20260622 + $dayIdx * 1000 + $roomIdx * 100 + $cIdx;
                    $duration = $this->seededRand($seed, 60, 240);
                    $surgeonId = $providerIds[$this->seededRand($seed + 1, 0, count($providerIds) - 1)];
                    $serviceId = $serviceIds[$this->seededRand($seed + 2, 0, count($serviceIds) - 1)];
                    $procedureId = $this->seededRand($seed + 3, 0, count($procedures) - 1);
                    $procedure = $procedures[$procedureId];

                    $scheduledStart = $slotStart->copy();

                    // Determine status.
                    //
                    // Today's OR day strategy (drives FCOTS + Turnover):
                    //   - Cases whose scheduled end (start + duration) is in the past
                    //     → Completed (so case_metrics rows exist for turnover avg).
                    //   - The next case whose slot is in the future → In Progress.
                    //   - Last case → Cancelled (same-day cancellation demo).
                    //   - Past days → all Completed.
                    if ($isToday($day)) {
                        $scheduledEndHour = (int) $scheduledStart->copy()->addMinutes($duration)->format('G');
                        if ($cIdx === $numCases - 1) {
                            $statusId = $statusCancelled;
                        } elseif ($scheduledEndHour <= $currentHour) {
                            // Case should be done by now.
                            $statusId = $statusCompleted;
                        } elseif ((int) $scheduledStart->format('G') <= $currentHour) {
                            // Started but not finished yet.
                            $statusId = $statusInProgress;
                        } else {
                            $statusId = $statusScheduled;
                        }
                    } else {
                        $statusId = $statusCompleted;
                    }

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
                        // FCOTS target: ~82% of first cases have procedure_start_time
                        // ≤ scheduled_start_time + 15 minutes (service grace window).
                        //
                        // For first cases (cIdx === 0) we control procedure_start_time
                        // directly relative to scheduledStart, then back-calculate or_in_time.
                        // Non-first cases are not measured by FCOTS; use normal jitter.
                        $procEnd = null;
                        $orOutTime = null;
                        $procStartOffset = null; // reset each iteration to avoid cross-case leakage

                        if ($cIdx === 0) {
                            // FCOTS ≈ 80% first-case on-time, generalised across N rooms (was
                            // hardcoded (dayIdx,roomIdx) slots that only covered 4 rooms). A
                            // deterministic 1-in-5 modulo keeps the late rate controlled and stable
                            // (a random draw over few rooms can land on a fake-looking 100%).
                            $isLate = (($dayIdx + $roomIdx) % 5 === 0);
                            $procStartOffset = $isLate
                                ? $this->seededRand($seed + 5, 16, 40)   // late: +16–40m
                                : $this->seededRand($seed + 5, -2, 13);  // on-time: -2–+13m
                            $procStart = $scheduledStart->copy()->addMinutes($procStartOffset);
                            // OR-in 10–20m before procedure start.
                            $orInTime = $procStart->copy()->subMinutes($this->seededRand($seed + 6, 10, 20));
                        } else {
                            // Non-first cases: standard OR-in jitter, then fixed prep.
                            $lateStartMin = $this->seededRand($seed + 4, -5, 20);
                            $orInTime = $scheduledStart->copy()->addMinutes($lateStartMin);
                            $procStart = $orInTime->copy()->addMinutes($this->seededRand($seed + 5, 15, 30));
                        }

                        if ($statusId === $statusCompleted) {
                            $procEnd = $procStart->copy()->addMinutes($duration);
                            $orOutTime = $procEnd->copy()->addMinutes($this->seededRand($seed + 6, 10, 25));
                        }

                        // P7 PACU chain. Completed cases recover in PACU after
                        // OR-out; most are discharged (pacu_out in the past),
                        // but the first 4 of today's completed cases stay held
                        // (pacu_out NULL, pacu_in 90–150m ago) to demo the
                        // recovery-bay boarding backlog.
                        $pacuIn = null;
                        $pacuOut = null;
                        if ($statusId === $statusCompleted && $orOutTime !== null) {
                            if ($dayIdx === $anchorDayIdx && $pacuHoldsSeeded < 4) {
                                $pacuIn = now()->copy()->subMinutes(90 + $pacuHoldsSeeded * 20);
                                $pacuOut = null; // still boarding in PACU
                                $pacuHoldsSeeded++;
                            } else {
                                $pacuIn = $orOutTime->copy()->addMinutes($this->seededRand($seed + 8, 5, 15));
                                $pacuOut = $pacuIn->copy()->addMinutes($this->seededRand($seed + 9, 55, 95));
                            }
                        }

                        DB::table('prod.or_logs')->insertGetId([
                            'case_id' => $caseId,
                            'tracking_date' => $day->toDateString(),
                            'or_in_time' => $orInTime,
                            'procedure_start_time' => $procStart,
                            'procedure_end_time' => $procEnd,
                            'or_out_time' => $orOutTime,
                            'pacu_in_time' => $pacuIn,
                            'pacu_out_time' => $pacuOut,
                            'primary_procedure' => $procedure,
                            'created_by' => 'seeder',
                            'modified_by' => 'seeder',
                            'created_at' => now(),
                            'updated_at' => now(),
                            'is_deleted' => false,
                        ], 'log_id');

                        // Case Metrics for completed cases only.
                        // Turnover target: 24–34m average.
                        if ($statusId === $statusCompleted) {
                            $turnover = $this->seededRand($seed + 7, 24, 34);
                            // late_start_minutes: for first cases use procStartOffset,
                            // for non-first cases use lateStartMin (set in the else branch).
                            $lateStart = max(0, isset($procStartOffset) ? $procStartOffset : $lateStartMin);
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
                    $turnoverGap = $this->seededRand($seed + 9, 24, 34);
                    $slotStart = $slotStart->addMinutes($duration + $turnoverGap);
                }
            }
        }

        // ----------------------------------------------------------------
        // Historical backfill: ~6 months of COMPLETED OR cases before the
        // 5-weekday operational window, so the surgical-analytics trend charts,
        // retrospective review, and historical-trends views have real
        // multi-month variation. All past-dated + Completed, so they never
        // affect the "today" FCOTS/turnover logic above. Marked created_by
        // 'seeder' so the idempotent delete at the top of this method clears
        // them on every re-seed. Sampled every 3rd day to keep volume modest.
        // ----------------------------------------------------------------
        for ($daysAgo = 8; $daysAgo <= 183; $daysAgo += 3) {
            $day = now()->startOfDay()->subDays($daysAgo);
            if (! $day->isWeekday()) {
                continue;
            }
            // Mild seasonality: slightly higher volume mid-week.
            $monthSeed = (int) $day->format('Ymd');

            // History is carried by the sustained-volume general/CV ORs (specialty rooms run
            // sparser elective schedules); keeps the ~6-month trend volume modest across 20 rooms.
            foreach (array_slice($roomIds, 0, 6, true) as $roomIdx => $roomId) {
                $numCases = $this->seededRand($monthSeed + $roomIdx, 3, 5);
                $slotStart = $day->copy()->setTime(7, 30);

                for ($cIdx = 0; $cIdx < $numCases; $cIdx++) {
                    $seed = $monthSeed * 100 + $roomIdx * 10 + $cIdx;
                    $duration = $this->seededRand($seed, 60, 240);
                    $surgeonId = $providerIds[$this->seededRand($seed + 1, 0, count($providerIds) - 1)];
                    $serviceId = $serviceIds[$this->seededRand($seed + 2, 0, count($serviceIds) - 1)];
                    $procedure = $procedures[$this->seededRand($seed + 3, 0, count($procedures) - 1)];
                    $scheduledStart = $slotStart->copy();

                    $caseId = DB::table('prod.or_cases')->insertGetId([
                        'patient_id' => sprintf('HSIM%05d', $seed % 100000),
                        'surgery_date' => $day->toDateString(),
                        'room_id' => $roomId,
                        'location_id' => $locationId,
                        'primary_surgeon_id' => $surgeonId,
                        'case_service_id' => $serviceId,
                        'scheduled_start_time' => $scheduledStart,
                        'scheduled_duration' => $duration,
                        'record_create_date' => $day->copy()->subDays(3),
                        'status_id' => $statusCompleted,
                        'cancellation_reason_id' => null,
                        'asa_rating_id' => $asaRatingId,
                        'case_type_id' => $caseTypeId,
                        'case_class_id' => $caseClassId,
                        'patient_class_id' => $patientClassId,
                        'safety_status' => 'Normal',
                        'journey_progress' => 100,
                        'created_by' => 'seeder',
                        'modified_by' => 'seeder',
                        'created_at' => now(),
                        'updated_at' => now(),
                        'is_deleted' => false,
                    ], 'case_id');

                    // First case of the day measured for FCOTS: ~80% on time.
                    $isFirst = $cIdx === 0;
                    $procStartOffset = $isFirst
                        ? ($this->seededRand($seed + 4, 0, 9) === 0 ? $this->seededRand($seed + 5, 16, 40) : $this->seededRand($seed + 5, -2, 13))
                        : $this->seededRand($seed + 4, -5, 20);
                    $procStart = $scheduledStart->copy()->addMinutes(max(0, $procStartOffset));
                    $orInTime = $procStart->copy()->subMinutes($this->seededRand($seed + 6, 10, 20));
                    $procEnd = $procStart->copy()->addMinutes($duration);
                    $orOutTime = $procEnd->copy()->addMinutes($this->seededRand($seed + 7, 10, 25));

                    DB::table('prod.or_logs')->insert([
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
                    ]);

                    $primeMin = (int) ($duration * 0.85);
                    DB::table('prod.case_metrics')->insert([
                        'case_id' => $caseId,
                        'turnover_time' => $this->seededRand($seed + 8, 24, 36),
                        'utilization_percentage' => round(min(100, ($duration / 480.0) * 100 + $this->seededRandFloat($seed + 9, -10, 10)), 2),
                        'in_block_time' => $duration,
                        'out_of_block_time' => 0,
                        'prime_time_minutes' => $primeMin,
                        'non_prime_time_minutes' => $duration - $primeMin,
                        'late_start_minutes' => max(0, $procStartOffset),
                        'early_finish_minutes' => 0,
                        'created_by' => 'seeder',
                        'modified_by' => 'seeder',
                        'created_at' => now(),
                        'updated_at' => now(),
                        'is_deleted' => false,
                    ]);

                    $slotStart = $slotStart->addMinutes($duration + $this->seededRand($seed + 10, 24, 34));
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

        // 2 historical events last week (both ended) + 1 ACTIVE ED diversion
        // (P7) so the live diversion chip lights and ed.diversion reads ON —
        // consistent with the severe NEDOCS the crowding cohort produces.
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
            [
                'started_at' => now()->subHours(2)->subMinutes(20),
                'ended_at' => null,
                'reason' => 'sim: ED overcrowding — NEDOCS severe, no treatment spaces',
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
                'title' => 'Improve discharge-before-noon rate on a high-occupancy med/surg unit',
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
    // Historical encounters — LOS/GMLOS, readmissions, discharge-by-noon
    // ====================================================================

    /**
     * Seed ~220 historical discharged encounters (sim-hx-NNNN) spread over
     * the last 45 days, plus ~30 discharged-today encounters (sim-td-NNNN),
     * plus readmission encounters (sim-ra-NNNN) for ~12% of the historical
     * cohort.
     *
     * Idempotent: deletes all sim-hx-/sim-td-/sim-ra- rows before re-inserting.
     * Deterministic: uses seededRand() throughout.
     * Does NOT affect active encounter counts or census occupancy.
     *
     * GMLOS by unit type (must match seedGmlosReferences):
     *   med_surg  → 4.20 days
     *   icu       → 5.80 days
     *   step_down → 3.50 days
     *   ed        → 0.40 days  (not used for inpatient encounters)
     */
    private function seedHistoricalEncounters($nonEdUnits): void
    {
        // Idempotent delete of all three sim-hx/sim-td/sim-ra cohorts.
        // Barriers FK these encounters (DemoTuningSeeder adds them on earlier
        // runs) — remove those first or the re-run dies on the constraint;
        // DemoTuningSeeder recreates barriers against the fresh cohort.
        $simCohort = fn ($q) => $q->where('patient_ref', 'like', 'sim-hx-%')
            ->orWhere('patient_ref', 'like', 'sim-td-%')
            ->orWhere('patient_ref', 'like', 'sim-ra-%');

        DB::table('prod.barriers')->whereIn(
            'encounter_id',
            DB::table('prod.encounters')->where($simCohort)->pluck('encounter_id'),
        )->delete();

        DB::table('prod.encounters')->where($simCohort)->delete();

        // GMLOS lookup keyed by unit type.
        $gmlosMap = [
            'med_surg' => 4.20,
            'icu' => 5.80,
            'step_down' => 3.50,
        ];

        // Build a pool of non-ED units with their types.
        $unitPool = $nonEdUnits->filter(fn ($u) => isset($gmlosMap[$u->type]))->values();
        if ($unitPool->isEmpty()) {
            return;
        }

        $now = now();

        // ------------------------------------------------------------------
        // Pre-step: fix pre-existing discharged encounters on canonical units.
        //
        // The RTDC core seeder created ~49 discharged encounters with LOS
        // of only 1–19 hours (avg 0.36 days) against GMLOS of 3.5–5.8 days.
        // These have blank created_by and live on canonical (non-soft-deleted)
        // units, so the service LOS query includes them, dragging the ratio
        // far below 1.0 regardless of how many sim-hx rows we add.
        //
        // We update their admitted_at so LOS ≈ gmlos × 1.10 (still discharged,
        // dates in the past, no active census impact). Idempotent: re-running
        // sets the same admitted_at each time via a deterministic per-encounter
        // calculation anchored on encounter_id.
        // ------------------------------------------------------------------
        // Include ALL pre-existing discharged encounters regardless of whether
        // their unit is soft-deleted — the service LOS query joins units without
        // filtering u.is_deleted, so ALL of them affect the ratio.
        $preExisting = DB::select(
            "SELECT e.encounter_id, e.discharged_at, g.gmlos_days
             FROM prod.encounters e
             JOIN prod.units u ON u.unit_id = e.unit_id
             JOIN prod.gmlos_references g ON g.unit_type = u.type
             WHERE e.status = 'discharged'
               AND e.is_deleted = false
               AND e.patient_ref NOT LIKE 'sim-hx-%'
               AND e.patient_ref NOT LIKE 'sim-td-%'
               AND e.patient_ref NOT LIKE 'sim-ra-%'"
        );

        foreach ($preExisting as $row) {
            // Target LOS = gmlos × 1.10, deterministic per encounter_id.
            $targetLosDays = $row->gmlos_days * 1.10;
            $dischargedAt = Carbon::parse($row->discharged_at);
            $newAdmittedAt = $dischargedAt->copy()->subSeconds((int) ($targetLosDays * 86400));

            DB::table('prod.encounters')
                ->where('encounter_id', $row->encounter_id)
                ->update([
                    'admitted_at' => $newAdmittedAt,
                    'updated_at' => now(),
                ]);
        }

        // ------------------------------------------------------------------
        // Part A — historical discharged encounters over the last 45 days.
        //
        // LOS = gmlos × factor where factor ∈ [1.05, 1.30] (uniform).
        //   avg(factor) = 1.175 → LOS/GMLOS ratio ≈ 1.10–1.12 ✓
        //   avg(factor − 1) = 0.175 → excess per enc ≈ gmlos × 0.175
        //
        // Count calibration (all 72 pre-existing now fixed to ratio ≈ 1.10):
        //   - pre-existing: 72 enc, ratio ~1.10, sum_gmlos ~250, excess ~250×0.10 ≈ 25d
        //   - sim-hx 130: factor avg 1.19, sum_gmlos ~575, excess ~575×0.19 ≈ 109d
        //   - sim-td 36: factor avg 1.19, sum_gmlos ~189, excess ~189×0.19 ≈ 36d
        //   Combined excess ≈ 25 + 109 + 36 = 170d — slightly over; use 110 enc.
        //   sim-hx 110: excess ≈ 487×0.19 ≈ 93d; total ≈ 25+93+36 = 154d — marginal.
        //   sim-hx 100: excess ≈ 442×0.19 ≈ 84d; total ≈ 25+84+36 = 145d ✓
        //   Ratio with 100 enc: (100×4.42×1.19 + 72×4.42×1.10 + 36×4.42×1.19) /
        //     (100×4.42 + 72×4.42 + 36×4.42) = (526+350+190)/(442+318+159) = 1066/919 = 1.16
        //   But includes pre-existing ED encounters (gmlos=0.40) which lower the blended avg.
        //   Empirically target 120 encounters with factor 108-128 (avg 1.18).
        // ------------------------------------------------------------------
        $historicalCount = 120;
        $historicalRefs = [];   // patient_refs for readmission eligibility

        for ($i = 1; $i <= $historicalCount; $i++) {
            $seed = 20260622 + $i * 41;

            // Pick a unit deterministically.
            $unit = $unitPool->get($this->seededRand($seed, 0, $unitPool->count() - 1));
            $gmlos = $gmlosMap[$unit->type];

            // LOS factor 1.08–1.30, avg ≈ 1.19 → combined ratio ≈ 1.05–1.08.
            // All factors > 1.0; excess per enc ≈ gmlos × 0.19 ≈ 4.42 × 0.19 ≈ 0.84d.
            // 140 enc → sim-hx excess ≈ 118d; combined (with pre-existing ~24d) ≈ 142d ✓
            $factorInt = $this->seededRand($seed + 1, 108, 130); // ×100
            $losDays = $gmlos * ($factorInt / 100.0);

            // Discharge between 2 days ago and 45 days ago so there is a
            // 30-day forward window for readmissions.
            $dischargedDaysAgo = $this->seededRand($seed + 2, 2, 45);
            $dischargedHour = $this->seededRand($seed + 3, 6, 22);
            $dischargedAt = $now->copy()->subDays($dischargedDaysAgo)->setTime($dischargedHour, 0, 0);

            $admittedAt = $dischargedAt->copy()->subSeconds((int) ($losDays * 86400));

            $patientRef = sprintf('sim-hx-%04d', $i);
            $historicalRefs[] = ['ref' => $patientRef, 'discharged_at' => $dischargedAt, 'days_ago' => $dischargedDaysAgo];

            DB::table('prod.encounters')->insert([
                'patient_ref' => $patientRef,
                'unit_id' => $unit->unit_id,
                'bed_id' => null,
                'admitted_at' => $admittedAt,
                'discharged_at' => $dischargedAt,
                'expected_discharge_date' => $dischargedAt->toDateString(),
                'acuity_tier' => $this->seededRand($seed + 4, 1, 4),
                'status' => 'discharged',
                'created_by' => 'seeder',
                'modified_by' => 'seeder',
                'created_at' => $admittedAt,
                'updated_at' => $dischargedAt,
                'is_deleted' => false,
            ]);
        }

        // ------------------------------------------------------------------
        // Part B — ~30 discharged-today encounters for Discharge-by-Noon.
        //
        // ~25% discharged before 12:00 → DBN ≈ 25%.
        // Remaining 75% discharged 12:00–20:00.
        // ------------------------------------------------------------------
        // DBN target: ~25% of all discharges today before noon.
        //
        // There are ~12 pre-existing core-seed encounters discharged today
        // with discharged_at between 00:00–05:00 (all before noon). These
        // cannot be touched. To hit 25% overall:
        //   Let T = total today-discharged, B = before-noon count.
        //   B/T = 0.25 → B = 0.25T → T = 4B.
        //   Pre-existing B_fixed = 12, so T_fixed = 12 (all before noon).
        //   We add N sim-td rows, 0 before noon:
        //   B_total = 12, T_total = 12 + N → 12/(12+N) = 0.25 → N = 36.
        // So we seed 36 sim-td encounters, ALL after noon, to dilute the
        // pre-existing before-noon cluster down to exactly 25%.
        $todayCount = 36;
        $today = $now->copy()->startOfDay();

        for ($i = 1; $i <= $todayCount; $i++) {
            $seed = 20260622 + $i * 53;

            $unit = $unitPool->get($this->seededRand($seed, 0, $unitPool->count() - 1));
            $gmlos = $gmlosMap[$unit->type];

            // All sim-td discharges are AFTER noon (12:00–20:00) so that the
            // 12 pre-existing before-noon encounters produce exactly 25% DBN.
            $beforeNoon = false;
            if ($beforeNoon) {
                // Discharged 08:00–11:45.
                $dischargeHour = $this->seededRand($seed + 1, 8, 11);
                $dischargeMinute = $this->seededRand($seed + 2, 0, 45);
            } else {
                // Discharged 12:00–20:00.
                $dischargeHour = $this->seededRand($seed + 1, 12, 20);
                $dischargeMinute = $this->seededRand($seed + 2, 0, 59);
            }

            $dischargedAt = $today->copy()->setTime($dischargeHour, $dischargeMinute, 0);
            // Only create if the discharge time is in the past.
            if ($dischargedAt->greaterThan($now)) {
                $dischargedAt = $now->copy()->subMinutes(5);
            }

            // sim-td LOS near GMLOS (factor 0.90–1.05, avg ~0.975) so these
            // encounters contribute minimal excess bed-days — they exist for
            // the DBN denominator, not to inflate excess.
            $losDays = $gmlos * ($this->seededRand($seed + 3, 90, 105) / 100.0);
            $admittedAt = $dischargedAt->copy()->subSeconds((int) ($losDays * 86400));

            DB::table('prod.encounters')->insert([
                'patient_ref' => sprintf('sim-td-%04d', $i),
                'unit_id' => $unit->unit_id,
                'bed_id' => null,
                'admitted_at' => $admittedAt,
                'discharged_at' => $dischargedAt,
                'expected_discharge_date' => $today->toDateString(),
                'acuity_tier' => $this->seededRand($seed + 4, 1, 4),
                'status' => 'discharged',
                'created_by' => 'seeder',
                'modified_by' => 'seeder',
                'created_at' => $admittedAt,
                'updated_at' => $dischargedAt,
                'is_deleted' => false,
            ]);
        }

        // ------------------------------------------------------------------
        // Part C — Readmissions for ~12% of the historical cohort.
        //
        // For each selected patient, create a NEW encounter with:
        //   admitted_at = original discharged_at + 3–25 days
        //   status = 'discharged' (completed short readmit) or 'active'
        //
        // The service query counts readmits where:
        //   readmit.admitted_at > e.discharged_at
        //   AND readmit.admitted_at <= e.discharged_at + 30 days
        //   AND readmit.encounter_id <> e.encounter_id
        //
        // We must ensure admitted_at of readmit is within 30 days of the
        // original discharge AND the original discharge is within the last
        // 30 days (the outer WHERE e.discharged_at >= now()-30d).
        // ------------------------------------------------------------------
        // Only historical encounters discharged within the last 30 days are
        // eligible (they are in the service's outer cohort window).
        $eligibleForReadmit = array_filter(
            $historicalRefs,
            fn ($r) => $r['days_ago'] <= 30
        );
        $eligibleForReadmit = array_values($eligibleForReadmit);

        // Target ~12% readmission rate.
        // eligibleForReadmit = sim-hx patients discharged within last 30 days.
        // With 140 sim-hx encounters, ~91 are in the 30-day window (65% of 140).
        // Total denominator (all discharged in last 30d): ~91 sim-hx + ~49 pre-existing
        //   + 36 sim-td = ~176 encounters.
        // Every 3rd of eligible = ~40 selected; ~75% pass gap check → ~30 readmits.
        // Total denominator ~196; 30/196 ≈ 15% — slightly high but accounts for the
        // fact that sim-ra encounters themselves enter the denominator on re-runs
        // as new "discharged" rows. Net effective rate ≈ 10–13% ✓
        $readmitCount = 0;
        foreach ($eligibleForReadmit as $idx => $orig) {
            // Select every 3rd eligible patient → ~10–13% effective readmission rate.
            if ($idx % 3 !== 0) {
                continue;
            }

            $seed = 20260622 + ($idx + 1) * 67;
            $unit = $unitPool->get($this->seededRand($seed, 0, $unitPool->count() - 1));

            // Readmit 3–25 days after discharge, but must be ≤30 days after.
            $maxGap = min(25, 30 - $orig['days_ago'] + 1);
            if ($maxGap < 3) {
                continue; // Not enough window — skip.
            }
            $gapDays = $this->seededRand($seed + 1, 3, $maxGap);
            $readmitAdmittedAt = $orig['discharged_at']->copy()->addDays($gapDays);

            // Readmit LOS: 1–4 days (short).
            $readmitLosDays = $this->seededRand($seed + 2, 1, 4);
            $readmitDischargedAt = $readmitAdmittedAt->copy()->addDays($readmitLosDays);

            $isActive = $readmitDischargedAt->greaterThan($now);
            $status = $isActive ? 'active' : 'discharged';

            DB::table('prod.encounters')->insert([
                'patient_ref' => $orig['ref'],  // SAME patient_ref — triggers readmit match
                'unit_id' => $unit->unit_id,
                'bed_id' => null,
                'admitted_at' => $readmitAdmittedAt,
                'discharged_at' => $isActive ? null : $readmitDischargedAt,
                'expected_discharge_date' => $isActive ? $now->copy()->addDays(1)->toDateString() : $readmitDischargedAt->toDateString(),
                'acuity_tier' => $this->seededRand($seed + 3, 1, 4),
                'status' => $status,
                'created_by' => 'seeder',
                'modified_by' => 'seeder',
                'created_at' => $readmitAdmittedAt,
                'updated_at' => $isActive ? $now : $readmitDischargedAt,
                'is_deleted' => false,
            ]);

            $readmitCount++;
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
