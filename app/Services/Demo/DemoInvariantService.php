<?php

namespace App\Services\Demo;

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
    public function __construct(private readonly DistributionProfile $profile)
    {
    }

    /**
     * @return list<array{key:string,category:string,severity:string,passed:bool,observed:string,expected:string,detail:string}>
     */
    public function run(DemoClock $clock): array
    {
        return [
            ...$this->temporal($clock),
            ...$this->capacity($clock),
            ...$this->freshness($clock),
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
        $out = [];

        $futureCensus = $this->scalar(
            'SELECT count(*) FROM prod.census_snapshots WHERE captured_at > ?', [$anchor]
        );
        $out[] = $this->finding('temporal.census_not_in_future', 'temporal', 'critical',
            $futureCensus === 0, "{$futureCensus} snapshots after anchor", '0',
            'census_snapshots.captured_at must not exceed the anchor');

        $futureAdmit = $this->scalar(
            'SELECT count(*) FROM prod.encounters WHERE admitted_at > ? AND discharged_at IS NULL AND is_deleted = false', [$anchor]
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
        $decisionCritical = ['ed_flow', 'encounters', 'capacity_census', 'bed_placement', 'rtdc_predictions'];

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
            "SELECT count(*) AS n, count(*) FILTER (WHERE extract(hour FROM discharged_at) < 12) AS before_noon
             FROM prod.discharge_facts WHERE discharged_at IS NOT NULL"
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
        $physicalRooms = $this->scalar('SELECT count(*) FROM prod.rooms', []);
        $declaredSpaces = 0;
        foreach ($this->profile->unitRoster() as $u) {
            if (($u['cadCode'] ?? null) === 'PERIOP') {
                $declaredSpaces = (int) ($u['beds'] ?? 0);
            }
        }
        if ($declaredSpaces > 0) {
            $out[] = $this->finding('plausibility.or_room_scale', 'plausibility', 'info',
                $physicalRooms >= (int) round($declaredSpaces * 0.4),
                "physical OR rooms {$physicalRooms}", "~{$declaredSpaces} declared procedure spaces",
                'a Level I / 44-room identity backed by only a handful of rooms undercuts OR realism');
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
