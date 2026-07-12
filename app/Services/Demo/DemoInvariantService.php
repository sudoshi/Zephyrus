<?php

namespace App\Services\Demo;

use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use Illuminate\Support\Facades\DB;

/**
 * Read-only demo invariants (plan §11 / FEEDBACK Wave 6 + the Wave-0 pre-demo gate).
 *
 * Runs SELECT-only checks against prod.* and reports temporal, capacity/reconciliation,
 * freshness, and plausibility findings. It NEVER writes — it is safe to run against the
 * canonical demo database as a pre-demo gate and in CI. The regeneration pipeline (Wave 1/2)
 * will publish a refreshed cockpit snapshot ONLY if the critical findings here all pass.
 *
 * Each finding: key, category, severity (critical|warning|info), passed, observed, expected,
 * detail. A refresh/gate should fail iff any severity=critical finding has passed=false.
 *
 * The specific violations this was built to catch (measured 2026-07-10 on the live demo):
 *   - 100 future-dated census snapshots, 3 future admits, 10 discharge-before-admit rows,
 *     96 RTDC predictions dated in the past, 46 ED visits open older than the window;
 *   - the house denominator silently including ED (148) + periop (44) beds;
 *   - flat ~84.5% occupancy across every unit type, an ESI-2-heavy (not ESI-3) mix,
 *     49.9% discharge-before-noon, and a STAT≈urgent transport queue.
 */
final class DemoInvariantService
{
    public function __construct(private readonly DistributionProfile $profile) {}

    /**
     * @return list<array{key:string,category:string,severity:string,passed:bool,observed:string,expected:string,detail:string}>
     */
    public function run(DemoClock $clock): array
    {
        return [
            ...$this->temporal($clock),
            ...$this->capacity($clock),
            ...$this->freshness($clock),
            ...$this->ancillary($clock),
            ...$this->plausibility($clock),
        ];
    }

    /** True iff no critical finding failed. */
    public function passed(array $findings): bool
    {
        foreach ($findings as $f) {
            if ($f['severity'] === 'critical' && $f['passed'] === false) {
                return false;
            }
        }

        return true;
    }

    // ---- temporal coherence (plan §11.1) ----

    private function temporal(DemoClock $clock): array
    {
        $anchor = $clock->anchor()->toDateTimeString();
        $windowStart = $clock->windowStart()->toDateTimeString();
        $anchorDate = $clock->anchor()->toDateString();
        // The anchor is frozen at batch start, but the seeders write at live now(), which drifts
        // forward while a batch runs. A small grace on the "not in future" upper-bound checks
        // absorbs that batch duration — a genuinely-future row is hours/days ahead, far past this.
        $ceiling = $clock->anchor()->addMinutes(15)->toDateTimeString();
        $out = [];

        $futureCensus = $this->scalar(
            'SELECT count(*) FROM prod.census_snapshots WHERE captured_at > ?', [$ceiling]
        );
        $out[] = $this->finding('temporal.census_not_in_future', 'temporal', 'critical',
            $futureCensus === 0, "{$futureCensus} snapshots after anchor", '0',
            'census_snapshots.captured_at must not exceed the anchor (+15m batch grace)');

        $futureAdmit = $this->scalar(
            'SELECT count(*) FROM prod.encounters WHERE admitted_at > ? AND discharged_at IS NULL AND is_deleted = false', [$ceiling]
        );
        $out[] = $this->finding('temporal.admit_not_in_future', 'temporal', 'critical',
            $futureAdmit === 0, "{$futureAdmit} active encounters admitted after anchor", '0',
            'an active encounter cannot be admitted in the future');

        $dcBeforeAdmit = $this->scalar(
            'SELECT count(*) FROM prod.encounters
             WHERE is_deleted = false AND expected_discharge_date IS NOT NULL
               AND expected_discharge_date < admitted_at', []
        );
        $out[] = $this->finding('temporal.discharge_after_admit', 'temporal', 'critical',
            $dcBeforeAdmit === 0, "{$dcBeforeAdmit} encounters discharge-before-admit", '0',
            'expected_discharge_date must be >= admitted_at');

        $staleForecast = $this->scalar(
            'SELECT count(*) FROM prod.rtdc_predictions WHERE service_date < ?::date', [$anchorDate]
        );
        $totalForecast = $this->scalar('SELECT count(*) FROM prod.rtdc_predictions', []);
        $out[] = $this->finding('temporal.forecasts_current', 'temporal', 'critical',
            $staleForecast === 0, "{$staleForecast}/{$totalForecast} predictions before anchor date", '0 stale',
            'RTDC predictions are forecasts — service_date must be >= the anchor date');

        $agedEd = $this->scalar(
            'SELECT count(*) FROM prod.ed_visits WHERE departed_at IS NULL AND is_deleted = false AND arrived_at < ?', [$windowStart]
        );
        $out[] = $this->finding('temporal.no_aged_active_ed', 'temporal', 'critical',
            $agedEd === 0, "{$agedEd} open ED visits older than the 24h window", '0',
            'an active ED visit older than anchor-24h is not a plausible current boarder');

        $edOrder = $this->scalar(
            'SELECT count(*) FROM prod.ed_visits
             WHERE is_deleted = false AND (
                 (triaged_at IS NOT NULL AND triaged_at < arrived_at) OR
                 (provider_seen_at IS NOT NULL AND triaged_at IS NOT NULL AND provider_seen_at < triaged_at) OR
                 (departed_at IS NOT NULL AND departed_at < arrived_at))', []
        );
        $out[] = $this->finding('temporal.ed_lifecycle_monotonic', 'temporal', 'critical',
            $edOrder === 0, "{$edOrder} ED rows with out-of-order lifecycle", '0',
            'arrived <= triaged <= provider_seen <= departed');

        return $out;
    }

    // ---- capacity & reconciliation (plan §8.1 / §11.2) ----

    private function capacity(DemoClock $clock): array
    {
        $out = [];
        $inpatientTypes = $this->profile->inpatientUnitTypes();
        $placeholders = implode(',', array_fill(0, count($inpatientTypes), '?'));

        // Bed-board occupancy by unit type (deterministic, matches prod.beds ground truth).
        $rows = DB::select(
            "SELECT u.type,
                    count(b.bed_id) AS inventory,
                    count(b.bed_id) FILTER (WHERE b.status = 'occupied') AS occupied,
                    count(b.bed_id) FILTER (WHERE b.status = 'available') AS available,
                    count(b.bed_id) FILTER (WHERE b.status IN ('blocked','dirty')) AS blocked
             FROM prod.units u
             JOIN prod.beds b ON b.unit_id = u.unit_id AND b.is_deleted = false
             WHERE u.is_deleted = false
             GROUP BY u.type"
        );

        $inpatientInventory = 0;
        $inpatientOccupied = 0;
        $reconcileOk = true;
        foreach ($rows as $r) {
            if ((int) $r->occupied + (int) $r->available + (int) $r->blocked !== (int) $r->inventory) {
                $reconcileOk = false;
            }
            if (in_array($r->type, $inpatientTypes, true)) {
                $inpatientInventory += (int) $r->inventory;
                $inpatientOccupied += (int) $r->occupied;
            }
        }

        // The canonical house denominator (HouseCensusService::houseTotals) must be inpatient-only.
        // ED + periop beds legitimately exist; the invariant is that the CODE excludes them, not
        // that they are absent. We assert the returned denominator does not exceed the inpatient plant.
        $licensed = $this->profile->licensedInpatientBeds();
        $house = app(\App\Services\Rtdc\HouseCensusService::class)->houseTotals();
        $houseStaffed = (int) ($house['staffedBeds'] ?? 0);
        $ceiling = $licensed > 0 ? $licensed : $inpatientInventory;
        $out[] = $this->finding('capacity.house_denominator_inpatient_only', 'capacity', 'critical',
            $houseStaffed <= $ceiling,
            "houseTotals staffedBeds {$houseStaffed}",
            "<= inpatient plant {$ceiling} (ED + periop excluded)",
            'HouseCensusService::houseTotals() must scope the denominator to inpatient unit types');

        $licensed = $this->profile->licensedInpatientBeds();
        $out[] = $this->finding('capacity.inpatient_matches_licensed', 'capacity', 'warning',
            $licensed === 0 || $inpatientInventory === $licensed,
            "inpatient inventory {$inpatientInventory}", "licensed {$licensed}",
            'physical inpatient beds should equal the licensed count');

        $out[] = $this->finding('capacity.bed_states_reconcile', 'capacity', 'critical',
            $reconcileOk, $reconcileOk ? 'all units reconcile' : 'a unit does not reconcile',
            'occupied + available + blocked = inventory', 'per-unit bed-state sum must equal inventory');

        // Occupied inpatient beds vs active inpatient encounters.
        $activeEnc = $this->scalar(
            "SELECT count(*) FROM prod.encounters e JOIN prod.units u ON u.unit_id = e.unit_id
             WHERE e.discharged_at IS NULL AND e.is_deleted = false AND u.type IN ($placeholders)",
            $inpatientTypes
        );
        $diff = abs($activeEnc - $inpatientOccupied);
        $out[] = $this->finding('capacity.encounters_match_occupied', 'capacity', 'warning',
            $diff <= max(5, (int) round($inpatientOccupied * 0.05)),
            "active inpatient encounters {$activeEnc} vs occupied inpatient beds {$inpatientOccupied}",
            'within 5%', 'active encounters should track occupied inpatient beds');

        return $out;
    }

    // ---- source freshness (plan §10.1 — read the ALREADY-EXISTING ops.source_freshness) ----

    private function freshness(DemoClock $clock): array
    {
        $out = [];
        $decisionCritical = ['ed_flow', 'encounters', 'capacity_census', 'bed_placement', 'rtdc_predictions', 'ancillary_orders', 'ancillary_milestones'];

        $rows = DB::select('SELECT source_key, source_label, status, latest_observed_at FROM ops.source_freshness');
        $critical = [];
        foreach ($rows as $r) {
            if ($r->status === 'critical') {
                $critical[] = $r->source_key;
            }
        }
        $decisionStale = array_values(array_intersect($critical, $decisionCritical));

        $out[] = $this->finding('freshness.decision_sources_current', 'freshness',
            $decisionStale === [] ? 'info' : 'critical',
            $decisionStale === [],
            $decisionStale === [] ? 'all decision sources current' : implode(', ', $decisionStale).' = critical',
            'no decision-critical source stale',
            'ops.source_freshness marks these as stale against wall-clock now(); a green cockpit tile must not be built from them');

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function ancillary(DemoClock $clock): array
    {
        $owner = AncillaryDemoScenarioService::OWNER;
        $anchor = $clock->anchor()->toDateTimeString();
        $out = [];

        $orphaned = $this->scalar(
            'SELECT count(*) FROM prod.ancillary_milestones m
             LEFT JOIN prod.ancillary_orders o ON o.ancillary_order_id = m.ancillary_order_id
             LEFT JOIN hosp_ref.ancillary_milestone_types t ON t.code = m.milestone_code
             WHERE o.ancillary_order_id IS NULL OR t.code IS NULL OR t.department <> o.department',
            [],
        );
        $out[] = $this->finding('ancillary.no_orphan_or_mismatched_milestones', 'ancillary', 'critical',
            $orphaned === 0, "{$orphaned} invalid milestones", '0',
            'every milestone must resolve to an order and same-department governed code');

        $terminalBeforeOrder = $this->scalar(
            "SELECT count(*) FROM prod.ancillary_milestones m
             JOIN prod.ancillary_orders o ON o.ancillary_order_id = m.ancillary_order_id
             JOIN hosp_ref.ancillary_milestone_types t ON t.code = m.milestone_code
             WHERE t.is_terminal = true AND m.occurred_at < o.ordered_at
               AND COALESCE((m.metadata->>'correction')::boolean, false) = false",
            [],
        );
        $out[] = $this->finding('ancillary.terminal_not_before_order', 'ancillary', 'critical',
            $terminalBeforeOrder === 0, "{$terminalBeforeOrder} invalid terminal assertions", '0',
            'terminal milestones cannot precede order time unless explicitly corrected');

        $invalidOpen = $this->scalar(
            'SELECT count(*) FROM prod.ancillary_breaches b
             JOIN prod.ancillary_sla_definitions d ON d.ancillary_sla_definition_id = b.ancillary_sla_definition_id
             LEFT JOIN prod.ancillary_milestones s ON s.ancillary_milestone_id = b.start_assertion_id
             WHERE b.status = \'open\' AND (s.ancillary_milestone_id IS NULL OR d.breach_minutes IS NULL
               OR EXTRACT(EPOCH FROM (?::timestamp - s.occurred_at)) / 60 < d.breach_minutes)',
            [$anchor],
        );
        $out[] = $this->finding('ancillary.open_breaches_mathematically_valid', 'ancillary', 'critical',
            $invalidOpen === 0, "{$invalidOpen} invalid open breaches", '0',
            'every open breach must have a valid start and be beyond its governed threshold at the frozen anchor');

        $invalidCleared = $this->scalar(
            "SELECT count(*) FROM prod.ancillary_breaches b
             LEFT JOIN prod.ancillary_milestones s ON s.ancillary_milestone_id = b.start_assertion_id
             LEFT JOIN prod.ancillary_milestones e ON e.ancillary_milestone_id = b.stop_assertion_id
             WHERE b.status = 'cleared' AND (s.ancillary_milestone_id IS NULL OR e.ancillary_milestone_id IS NULL
               OR e.occurred_at < s.occurred_at OR b.elapsed_minutes_at_clear < 0)",
            [],
        );
        $out[] = $this->finding('ancillary.cleared_breaches_have_valid_stop', 'ancillary', 'critical',
            $invalidCleared === 0, "{$invalidCleared} invalid cleared breaches", '0',
            'cleared breaches retain a nonnegative exact selected stop interval');

        $ownershipViolations = $this->scalar(
            "SELECT count(*) FROM prod.ancillary_orders o
             JOIN integration.sources s ON s.source_id = o.source_id
             WHERE s.source_key LIKE 'demo.ancillary.%'
               AND o.demo_owner IS DISTINCT FROM ?",
            [$owner],
        );
        $out[] = $this->finding('ancillary.demo_ownership_exact', 'ancillary', 'critical',
            $ownershipViolations === 0, "{$ownershipViolations} ownership violations", '0',
            'demo-source rows must carry the exact ancillary owner and refresh cannot select non-owned facts');

        $invalidDischarge = $this->scalar(
            "SELECT count(*) FROM prod.ancillary_orders
             WHERE demo_owner = ? AND COALESCE((metadata->>'discharge_blocking')::boolean, false) = true
               AND terminal_at IS NOT NULL",
            [$owner],
        );
        $out[] = $this->finding('ancillary.discharge_blockers_are_live', 'ancillary', 'critical',
            $invalidDischarge === 0, "{$invalidDischarge} terminal discharge blockers", '0',
            'a discharge-blocking demo order must still be live');

        $missingWarehouseCutoff = $this->scalar(
            "SELECT count(*) FROM prod.ancillary_milestones m
             JOIN prod.ancillary_orders o ON o.ancillary_order_id = m.ancillary_order_id
             JOIN integration.sources s ON s.source_id = m.source_id
             WHERE o.demo_owner = ? AND m.milestone_code = 'RX_ADMINISTERED'
               AND (s.system_class = 'clinical_warehouse' AND o.source_cutoff_at IS NULL)",
            [$owner],
        );
        $out[] = $this->finding('ancillary.warehouse_administration_cutoff_present', 'ancillary', 'critical',
            $missingWarehouseCutoff === 0, "{$missingWarehouseCutoff} administered rows without cutoff", '0',
            'warehouse-derived administration evidence must remain cutoff-qualified');

        $conflicts = $this->scalar(
            'SELECT count(*) FROM prod.ancillary_current_assertions v
             JOIN prod.ancillary_orders o ON o.ancillary_order_id = v.ancillary_order_id
             WHERE o.demo_owner = ? AND v.assertion_count > 1',
            [$owner],
        );
        $out[] = $this->finding('ancillary.source_conflict_represented', 'ancillary', 'warning',
            $conflicts > 0, "{$conflicts} selected-source conflicts", '>= 1',
            'the deterministic demo should retain at least one competing source assertion');

        $invalidRadiologySatellites = $this->scalar(
            'SELECT
                (SELECT count(*) FROM prod.rad_exams e LEFT JOIN prod.ancillary_orders o ON o.ancillary_order_id = e.ancillary_order_id
                 WHERE e.demo_owner = ? AND (o.ancillary_order_id IS NULL OR o.demo_owner IS DISTINCT FROM ?))
              + (SELECT count(*) FROM prod.rad_reads r LEFT JOIN prod.rad_exams e ON e.rad_exam_id = r.rad_exam_id
                 WHERE r.demo_owner = ? AND (e.rad_exam_id IS NULL OR e.demo_owner IS DISTINCT FROM ?))
              + (SELECT count(*) FROM prod.rad_critical_results c LEFT JOIN prod.rad_exams e ON e.rad_exam_id = c.rad_exam_id
                 WHERE c.demo_owner = ? AND (e.rad_exam_id IS NULL OR e.demo_owner IS DISTINCT FROM ?))',
            [$owner, $owner, $owner, $owner, $owner, $owner],
        );
        $out[] = $this->finding('ancillary.radiology_satellites_owned_and_linked', 'ancillary', 'critical',
            $invalidRadiologySatellites === 0, "{$invalidRadiologySatellites} invalid Radiology satellites", '0',
            'demo exams, reads, and critical loops must resolve to exact-owner parent records');

        $invalidRadiologyEd = $this->scalar(
            "SELECT count(*) FROM prod.rad_exams x
             JOIN prod.ancillary_orders o ON o.ancillary_order_id = x.ancillary_order_id
             LEFT JOIN prod.ed_visits v ON v.ed_visit_id = NULLIF(x.metadata->>'ed_visit_id', '')::bigint
             WHERE x.demo_owner = ? AND x.metadata->>'demo_context' = 'ed'
               AND (v.ed_visit_id IS NULL OR v.is_deleted = true OR v.patient_ref IS DISTINCT FROM o.patient_ref)",
            [$owner],
        );
        $out[] = $this->finding('ancillary.radiology_ed_context_valid', 'ancillary', 'critical',
            $invalidRadiologyEd === 0, "{$invalidRadiologyEd} invalid Radiology ED contexts", '0',
            'every Radiology ED scenario must reference a real non-deleted ED visit for the same patient');

        $invalidRadiologyDischarge = $this->scalar(
            "SELECT count(*) FROM prod.ancillary_orders o
             LEFT JOIN prod.encounters e ON e.encounter_id = o.encounter_id
             WHERE o.demo_owner = ? AND o.department = 'rad'
               AND COALESCE((o.metadata->>'discharge_blocking')::boolean, false) = true
               AND (e.encounter_id IS NULL OR e.expected_discharge_date IS NULL OR o.terminal_at IS NOT NULL)",
            [$owner],
        );
        $out[] = $this->finding('ancillary.radiology_discharge_context_valid', 'ancillary', 'critical',
            $invalidRadiologyDischarge === 0, "{$invalidRadiologyDischarge} invalid Radiology discharge contexts", '0',
            'every Radiology discharge blocker must reference a current discharge candidate and live order');

        $invalidIr = $this->scalar(
            "SELECT count(*) FROM prod.rad_exams e
             LEFT JOIN prod.or_cases c ON c.case_id = NULLIF(e.metadata->>'or_case_id', '')::bigint
             WHERE e.demo_owner = ? AND e.is_ir = true AND jsonb_exists(e.metadata, 'or_case_id') AND c.case_id IS NULL",
            [$owner],
        );
        $out[] = $this->finding('ancillary.radiology_ir_context_valid', 'ancillary', 'critical',
            $invalidIr === 0, "{$invalidIr} IR exams without OR/procedural context", '0',
            'every linked demo IR exam must resolve to a real demo OR case');

        $distribution = DB::selectOne(
            "SELECT count(*) AS n,
                    percentile_cont(0.25) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (v.occurred_at - o.ordered_at)) / 60) AS q1,
                    percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (v.occurred_at - o.ordered_at)) / 60) AS median,
                    percentile_cont(0.75) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (v.occurred_at - o.ordered_at)) / 60) AS q3
             FROM prod.rad_exams e
             JOIN prod.ancillary_orders o ON o.ancillary_order_id = e.ancillary_order_id
             JOIN prod.ancillary_current_assertions v ON v.ancillary_order_id = o.ancillary_order_id AND v.milestone_code = 'RAD_EXAM_END'
             WHERE e.demo_owner = ? AND e.modality_code = 'CT' AND o.patient_class = 'emergency'",
            [$owner],
        );
        $distributionValid = (int) ($distribution->n ?? 0) >= 9
            && abs((float) ($distribution->median ?? 0) - 108) <= 1
            && abs((float) ($distribution->q1 ?? 0) - 57) <= 1
            && abs((float) ($distribution->q3 ?? 0) - 182) <= 1;
        $out[] = $this->finding('ancillary.radiology_distribution_plausible', 'ancillary', 'warning',
            $distributionValid,
            sprintf('%d ED CT intervals; median %.1f, IQR %.1f-%.1f', (int) ($distribution->n ?? 0), (float) ($distribution->median ?? 0), (float) ($distribution->q1 ?? 0), (float) ($distribution->q3 ?? 0)),
            'n >= 9, median 108, IQR 57-182',
            'fixed-seed Radiology acquisition distribution should remain anchored to the reference without overfitting every record');

        return $out;
    }

    // ---- plausibility bands (FEEDBACK §4 — the "deeply plausible" spec) ----

    private function plausibility(DemoClock $clock): array
    {
        $out = [];

        // 1. Occupancy by unit type — banded, and not suspiciously flat.
        $bands = $this->profile->occupancyBands();
        $rows = DB::select(
            "SELECT u.type,
                    count(b.bed_id) AS inventory,
                    count(b.bed_id) FILTER (WHERE b.status = 'occupied') AS occupied
             FROM prod.units u JOIN prod.beds b ON b.unit_id = u.unit_id AND b.is_deleted = false
             WHERE u.is_deleted = false GROUP BY u.type"
        );
        $pcts = [];
        foreach ($rows as $r) {
            $type = (string) $r->type;
            if (! isset($bands[$type]) || (int) $r->inventory === 0) {
                continue;
            }
            $pct = (int) $r->occupied / (int) $r->inventory;
            $pcts[$type] = $pct;
            [$min, $max] = $bands[$type];
            $out[] = $this->finding("plausibility.occupancy.$type", 'plausibility', 'warning',
                $pct >= $min && $pct <= $max,
                sprintf('%s %.1f%%', $type, $pct * 100),
                sprintf('%.0f–%.0f%%', $min * 100, $max * 100),
                'occupancy should sit inside the unit-type operating band');
        }
        if (count($pcts) >= 2) {
            $spread = max($pcts) - min($pcts);
            $out[] = $this->finding('plausibility.occupancy_differentiated', 'plausibility', 'warning',
                $spread >= 0.03,
                sprintf('spread %.1f points', $spread * 100), '>= 3 points',
                'a flat occupancy across all unit types reads as a global target, not a real house (ICUs run hotter)');
        }

        // 2. ED acuity pyramid — ESI-3 modal, each level in band.
        $esiRows = DB::select(
            'SELECT esi_level, count(*) AS n FROM prod.ed_visits WHERE is_deleted = false AND esi_level IS NOT NULL GROUP BY esi_level'
        );
        $esiCounts = [];
        $esiTotal = 0;
        foreach ($esiRows as $r) {
            $esiCounts[(int) $r->esi_level] = (int) $r->n;
            $esiTotal += (int) $r->n;
        }
        if ($esiTotal > 0) {
            $esiBands = $this->profile->esiBands();
            $inBand = true;
            $detail = [];
            foreach ($esiBands as $level => [$min, $max]) {
                $share = ($esiCounts[$level] ?? 0) / $esiTotal;
                $detail[] = sprintf('ESI-%d %.0f%%', $level, $share * 100);
                if ($share < $min || $share > $max) {
                    $inBand = false;
                }
            }
            $modal = array_keys($esiCounts, max($esiCounts))[0] ?? null;
            $out[] = $this->finding('plausibility.esi_bands', 'plausibility', 'warning',
                $inBand, implode(' · ', $detail), 'each level within band',
                'a real ED presents as an ESI-3-dominant pyramid');
            $out[] = $this->finding('plausibility.esi_modal_is_3', 'plausibility', 'warning',
                $modal === 3, "modal class ESI-{$modal}", 'ESI-3',
                'ESI-3 should be the largest presenting class');
        }

        // 3. Discharge-before-noon — realistic, not fantasy.
        $dcRow = DB::selectOne(
            'SELECT count(*) AS n, count(*) FILTER (WHERE extract(hour FROM discharged_at) < 12) AS before_noon
             FROM prod.discharge_facts WHERE discharged_at IS NOT NULL'
        );
        if ($dcRow && (int) $dcRow->n > 0) {
            $share = (int) $dcRow->before_noon / (int) $dcRow->n;
            [$min, $max] = $this->profile->dischargeBeforeNoonBand();
            $out[] = $this->finding('plausibility.discharge_before_noon', 'plausibility', 'warning',
                $share >= $min && $share <= $max,
                sprintf('%.1f%%', $share * 100), sprintf('%.0f–%.0f%%', $min * 100, $max * 100),
                'discharge-before-noon > ~45% is not achievable in reality');
        }

        // 4. Transport priority mix + overdue share.
        $tpRows = DB::select(
            'SELECT priority, count(*) AS n, count(*) FILTER (WHERE completed_at IS NULL) AS active FROM prod.transport_requests GROUP BY priority'
        );
        $tpTotal = 0;
        $tpCounts = [];
        $activeTotal = 0;
        foreach ($tpRows as $r) {
            $tpCounts[(string) $r->priority] = (int) $r->n;
            $tpTotal += (int) $r->n;
            $activeTotal += (int) $r->active;
        }
        if ($tpTotal > 0) {
            $tpBands = $this->profile->transportPriorityBands();
            $inBand = true;
            $detail = [];
            foreach ($tpBands as $prio => [$min, $max]) {
                $share = ($tpCounts[$prio] ?? 0) / $tpTotal;
                $detail[] = sprintf('%s %.0f%%', $prio, $share * 100);
                if ($share < $min || $share > $max) {
                    $inBand = false;
                }
            }
            $out[] = $this->finding('plausibility.transport_priority_mix', 'plausibility', 'warning',
                $inBand, implode(' · ', $detail), 'routine dominant, stat a thin slice',
                'STAT should not rival urgent or routine volume');
        }
        $activeOverdue = $this->scalar(
            'SELECT count(*) FROM prod.transport_requests WHERE completed_at IS NULL AND needed_at < ?',
            [$clock->anchor()->toDateTimeString()]
        );
        if ($activeTotal > 0) {
            $share = $activeOverdue / $activeTotal;
            $out[] = $this->finding('plausibility.transport_overdue_share', 'plausibility', 'warning',
                $share <= $this->profile->transportOverdueShareMax(),
                sprintf('%d/%d active overdue (%.0f%%)', $activeOverdue, $activeTotal, $share * 100),
                sprintf('<= %.0f%%', $this->profile->transportOverdueShareMax() * 100),
                'only a small intentional cohort should breach SLA');
        }

        // 5. OR physical rooms vs declared procedure spaces (identity consistency).
        $physicalRooms = $this->scalar("SELECT count(*) FROM prod.rooms WHERE type = 'OR' AND is_deleted = false", []);
        $declaredSpaces = 0;
        foreach ($this->profile->unitRoster() as $u) {
            if (($u['cadCode'] ?? null) === 'PERIOP') {
                $declaredSpaces = (int) ($u['beds'] ?? 0);
            }
        }
        if ($declaredSpaces > 0) {
            $out[] = $this->finding('plausibility.or_room_scale', 'plausibility', 'warning',
                $physicalRooms >= (int) round($declaredSpaces * 0.4),
                "OR rooms {$physicalRooms}", "~{$declaredSpaces} declared procedure spaces",
                'a Level I / 44-room identity backed by only a handful of rooms undercuts OR realism');
        }

        // 6. OR daily volume on the most-recent surgical day — a real suite runs many rooms.
        $orDay = DB::selectOne('SELECT max(surgery_date)::date AS d FROM prod.or_cases WHERE is_deleted = false');
        if ($orDay && $orDay->d !== null) {
            $vol = $this->scalar('SELECT count(*) FROM prod.or_cases WHERE surgery_date::date = ?::date AND is_deleted = false', [$orDay->d]);
            $roomsUsed = $this->scalar('SELECT count(DISTINCT room_id) FROM prod.or_cases WHERE surgery_date::date = ?::date AND is_deleted = false', [$orDay->d]);
            $out[] = $this->finding('plausibility.or_daily_volume', 'plausibility', 'warning',
                $vol >= 20 && $roomsUsed >= 8,
                "{$vol} cases across {$roomsUsed} rooms on {$orDay->d}",
                '>= 20 cases across >= 8 rooms',
                'a Level I OR suite runs many rooms with meaningful daily volume');
        }

        return $out;
    }

    // ---- helpers ----

    private function scalar(string $sql, array $bindings): int
    {
        $row = DB::selectOne($sql, $bindings);

        return (int) ($row->count ?? array_values((array) $row)[0] ?? 0);
    }

    private function finding(string $key, string $category, string $severity, bool $passed, string $observed, string $expected, string $detail): array
    {
        return compact('key', 'category', 'severity', 'passed', 'observed', 'expected', 'detail');
    }
}
