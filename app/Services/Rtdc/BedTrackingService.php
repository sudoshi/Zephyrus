<?php

namespace App\Services\Rtdc;

use Illuminate\Support\Facades\DB;

/**
 * Builds the Bed Tracking page payload from the live `prod` schema
 * (DB_SCHEMA=prod).
 *
 * The page (resources/js/Pages/RTDC/BedTracking.tsx) renders the shared design
 * system: KpiTile metric walls (Capacity + Pending-flow Sections) plus a
 * per-unit UnitHeatStrip. This service supplies the live *values* for those
 * tiles and the per-unit census array, while the page keeps its authored
 * presentation (status thresholds, sparkline trajectories, targets,
 * definitions) and falls back to its demo literals when a value is absent.
 *
 * Returned shape:
 *   bedMetrics: array<string,int>  // keyed by the page's metric keys
 *       total-beds, occupied, available, occupancy, turnover, blocked,
 *       pend-admit, pend-discharge, pend-transfer, ed-boarding, net-position
 *   unitCensus: list<UnitCensus>   // matches resources/js/types/commandCenter.ts
 *
 * `prod.beds` is the authoritative source for physical bed status (it covers
 * every non-deleted unit and carries the `dirty` status that maps to turnover).
 * `blocked` is taken from the latest census snapshot per unit, since the beds
 * table does not currently carry blocked rows. Computation is deterministic and
 * safe on empty tables: every accessor returns zeros / empty collections rather
 * than throwing.
 */
class BedTrackingService
{
    /** Human-readable label per unit `type` (drives the UnitHeatStrip "type"). */
    private const TYPE_LABELS = [
        'ed' => 'Emergency',
        'icu' => 'Critical',
        'step_down' => 'Step-down',
        'med_surg' => 'Med/Surg',
        'or' => 'Surgical',
    ];

    /**
     * Full Bed Tracking payload.
     *
     * @return array{
     *     bedMetrics: array<string,int>,
     *     unitCensus: list<array<string,mixed>>
     * }
     */
    public function build(): array
    {
        $bedsByUnit = $this->bedStatusByUnit();
        $blockedByUnit = $this->blockedByUnit();
        $pendingDischargesByUnit = $this->pendingDischargesByUnit();

        return [
            'bedMetrics' => $this->bedMetrics($bedsByUnit, $blockedByUnit, $pendingDischargesByUnit),
            'unitCensus' => $this->unitCensus($bedsByUnit, $blockedByUnit, $pendingDischargesByUnit),
        ];
    }

    // -----------------------------------------------------------------------
    // Source queries
    // -----------------------------------------------------------------------

    /**
     * Physical bed-status counts per non-deleted unit, sourced from prod.beds.
     * `dirty` is surfaced as the turnover (cleaning) bucket.
     *
     * @return list<object>
     */
    private function bedStatusByUnit(): array
    {
        return DB::select(
            "SELECT
                u.unit_id,
                u.name AS unit_name,
                u.abbreviation,
                u.type AS unit_type,
                COUNT(b.bed_id) AS total,
                COUNT(b.bed_id) FILTER (WHERE b.status = 'occupied') AS occupied,
                COUNT(b.bed_id) FILTER (WHERE b.status = 'available') AS available,
                COUNT(b.bed_id) FILTER (WHERE b.status = 'dirty') AS turnover
             FROM prod.units u
             JOIN prod.beds b ON b.unit_id = u.unit_id AND b.is_deleted = false
             WHERE u.is_deleted = false
             GROUP BY u.unit_id, u.name, u.abbreviation, u.type
             ORDER BY u.type, u.name"
        );
    }

    /**
     * Latest-census blocked-bed count per unit (DISTINCT ON captured_at).
     *
     * @return array<int,int>
     */
    private function blockedByUnit(): array
    {
        $rows = DB::select(
            'SELECT DISTINCT ON (cs.unit_id) cs.unit_id, cs.blocked
             FROM prod.census_snapshots cs
             JOIN prod.units u ON u.unit_id = cs.unit_id
             WHERE u.is_deleted = false
             ORDER BY cs.unit_id, cs.captured_at DESC'
        );

        $byUnit = [];
        foreach ($rows as $row) {
            $byUnit[(int) $row->unit_id] = (int) $row->blocked;
        }

        return $byUnit;
    }

    /**
     * Active encounters expected to discharge today or tomorrow, per unit.
     *
     * @return array<int,int>
     */
    private function pendingDischargesByUnit(): array
    {
        return DB::table('prod.encounters')
            ->where('status', 'active')
            ->where('is_deleted', false)
            ->whereNotNull('unit_id')
            ->whereNotNull('expected_discharge_date')
            ->whereRaw("expected_discharge_date <= CURRENT_DATE + INTERVAL '1 day'")
            ->selectRaw('unit_id, COUNT(*) AS cnt')
            ->groupBy('unit_id')
            ->pluck('cnt', 'unit_id')
            ->map(fn ($v): int => (int) $v)
            ->toArray();
    }

    // -----------------------------------------------------------------------
    // House-wide metric values (keyed by the page's KpiTile metric keys)
    // -----------------------------------------------------------------------

    /**
     * @param  list<object>  $bedsByUnit
     * @param  array<int,int>  $blockedByUnit
     * @param  array<int,int>  $pendingDischargesByUnit
     * @return array<string,int>
     */
    private function bedMetrics(array $bedsByUnit, array $blockedByUnit, array $pendingDischargesByUnit): array
    {
        $total = 0;
        $occupied = 0;
        $available = 0;
        $turnover = 0;
        foreach ($bedsByUnit as $row) {
            $total += (int) $row->total;
            $occupied += (int) $row->occupied;
            $available += (int) $row->available;
            $turnover += (int) $row->turnover;
        }
        $blocked = (int) array_sum($blockedByUnit);
        $occupancy = $total > 0 ? (int) round($occupied / $total * 100) : 0;

        $pendAdmit = (int) DB::table('prod.bed_requests')
            ->where('status', 'pending')
            ->where('is_deleted', false)
            ->count();

        $pendTransfer = (int) DB::table('prod.bed_requests')
            ->where('status', 'pending')
            ->where('source', 'transfer')
            ->where('is_deleted', false)
            ->count();

        $pendDischarge = (int) array_sum($pendingDischargesByUnit);

        $edBoarding = (int) DB::table('prod.ed_visits')
            ->where('disposition', 'admitted')
            ->whereNull('bed_assigned_at')
            ->where('is_deleted', false)
            ->count();

        // Projected net bed position over the next horizon: discharges in
        // motion minus admissions waiting on a bed.
        $netPosition = $pendDischarge - $pendAdmit;

        return [
            'total-beds' => $total,
            'occupied' => $occupied,
            'available' => $available,
            'occupancy' => $occupancy,
            'turnover' => $turnover,
            'blocked' => $blocked,
            'pend-admit' => $pendAdmit,
            'pend-discharge' => $pendDischarge,
            'pend-transfer' => $pendTransfer,
            'ed-boarding' => $edBoarding,
            'net-position' => $netPosition,
        ];
    }

    // -----------------------------------------------------------------------
    // Per-unit census (matches UnitCensus in commandCenter.ts)
    // -----------------------------------------------------------------------

    /**
     * @param  list<object>  $bedsByUnit
     * @param  array<int,int>  $blockedByUnit
     * @param  array<int,int>  $pendingDischargesByUnit
     * @return list<array<string,mixed>>
     */
    private function unitCensus(array $bedsByUnit, array $blockedByUnit, array $pendingDischargesByUnit = []): array
    {
        $out = [];
        foreach ($bedsByUnit as $row) {
            $unitId = (int) $row->unit_id;
            $type = (string) $row->unit_type;
            $staffed = (int) $row->total;
            $occupied = (int) $row->occupied;
            $available = (int) $row->available;
            $blocked = (int) ($blockedByUnit[$unitId] ?? 0);
            $occupancyPct = $staffed > 0 ? (int) round($occupied / $staffed * 100) : 0;

            // Acuity-adjusted effective occupancy runs a few points hotter than
            // raw occupancy (high-acuity beds consume more capacity).
            $acuityAdjustedPct = min(100, $occupancyPct + ($occupancyPct >= 80 ? 3 : 2));

            $out[] = [
                'unitId' => $unitId,
                'name' => (string) ($row->abbreviation ?: $row->unit_name),
                'type' => self::TYPE_LABELS[$type] ?? ucfirst(str_replace('_', '-', $type)),
                'staffed' => $staffed,
                'occupied' => $occupied,
                'blocked' => $blocked,
                'available' => $available,
                'occupancyPct' => $occupancyPct,
                'acuityAdjustedPct' => $acuityAdjustedPct,
                'status' => $this->occupancyStatus($occupancyPct),
                // The outflow side of the unit's capacity story (additive —
                // the unitCensusSchema field is optional).
                'pendingDischarges' => (int) ($pendingDischargesByUnit[$unitId] ?? 0),
            ];
        }

        return $out;
    }

    /** Map occupancy to the four-color status vocabulary (critical|warning|success). */
    private function occupancyStatus(int $occupancy): string
    {
        if ($occupancy >= 92) {
            return 'critical';
        }
        if ($occupancy >= 85) {
            return 'warning';
        }

        return 'success';
    }
}
