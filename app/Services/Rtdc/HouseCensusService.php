<?php

namespace App\Services\Rtdc;

use Illuminate\Support\Facades\DB;

/**
 * The ONE house-census read (Zephyrus 2.0 P5, plan §P5.4).
 *
 * Extracted from CommandCenterDataService::latestCensusPerUnit() so the
 * cockpit (A0) and the analytics hub (A3) sum the SAME rows with the SAME
 * fallback. Before this, OperationsAnalyticsService ran its own copy of the
 * DISTINCT ON query WITHOUT the bed-board fallback — the executive brief
 * could read 0% occupancy while the cockpit said 86% on a fresh dataset.
 *
 * Row shape: unit_id, staffed_beds, occupied, available, blocked,
 * acuity_adjusted_capacity, unit_name, unit_type, abbreviation, captured_at
 * (captured_at is null on the fallback branch — the live bed board has no
 * snapshot timestamp).
 */
class HouseCensusService
{
    /** @return list<object> Latest census row per unit, bed-board fallback. */
    public function latestPerUnit(): array
    {
        $rows = DB::select(
            'SELECT DISTINCT ON (cs.unit_id)
                cs.unit_id, cs.staffed_beds, cs.occupied, cs.available,
                cs.blocked, cs.acuity_adjusted_capacity, cs.captured_at,
                u.name AS unit_name, u.type AS unit_type, u.abbreviation
             FROM prod.census_snapshots cs
             JOIN prod.units u ON u.unit_id = cs.unit_id
             WHERE u.is_deleted = false
             ORDER BY cs.unit_id, cs.captured_at DESC'
        );

        if ($rows !== []) {
            return $rows;
        }

        // No census snapshots (fresh dataset, or the snapshot pipeline isn't running):
        // fall back to the live bed board so every surface reads the same occupancy —
        // the executive brief must never say 0% while the RTDC census says 86%.
        return DB::select(
            'SELECT u.unit_id,
                    u.staffed_bed_count AS staffed_beds,
                    COUNT(b.bed_id) FILTER (WHERE b.status = \'occupied\') AS occupied,
                    COUNT(b.bed_id) FILTER (WHERE b.status = \'available\') AS available,
                    COUNT(b.bed_id) FILTER (WHERE b.status IN (\'blocked\', \'dirty\')) AS blocked,
                    NULL::int AS acuity_adjusted_capacity,
                    NULL::timestamptz AS captured_at,
                    u.name AS unit_name, u.type AS unit_type, u.abbreviation
             FROM prod.units u
             LEFT JOIN prod.beds b ON b.unit_id = u.unit_id AND b.is_deleted = false
             WHERE u.is_deleted = false
             GROUP BY u.unit_id, u.staffed_bed_count, u.name, u.type, u.abbreviation'
        );
    }

    /**
     * House-wide totals from the same rows — the single occupancy formula.
     *
     * The house denominator is INPATIENT ONLY: ED treatment spaces and periop
     * procedure rooms are capacity in their own domains and must never inflate
     * house occupancy (plan §8.1 capacity vocabulary). Per-unit consumers keep
     * calling latestPerUnit() to see every unit; only the house TOTAL is scoped.
     *
     * @return array{staffedBeds:int,occupied:int,available:int,blocked:int,occupancyPct:int}
     */
    public function houseTotals(): array
    {
        $inpatientTypes = (array) config('hospital.inpatient_unit_types', ['icu', 'step_down', 'med_surg']);
        $rows = array_filter(
            $this->latestPerUnit(),
            fn ($row): bool => in_array($row->unit_type ?? null, $inpatientTypes, true)
        );

        $staffed = (int) array_sum(array_map(fn ($row): int => (int) $row->staffed_beds, $rows));
        $occupied = (int) array_sum(array_map(fn ($row): int => (int) $row->occupied, $rows));

        return [
            'staffedBeds' => $staffed,
            'occupied' => $occupied,
            'available' => (int) array_sum(array_map(fn ($row): int => (int) $row->available, $rows)),
            'blocked' => (int) array_sum(array_map(fn ($row): int => (int) $row->blocked, $rows)),
            'occupancyPct' => $staffed > 0 ? (int) round($occupied / $staffed * 100) : 0,
        ];
    }
}
