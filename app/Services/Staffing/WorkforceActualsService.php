<?php

namespace App\Services\Staffing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Zephyrus 2.0 P7 — reads the prod.workforce_actuals day fact for the
 * staffing cockpit tiles. Today-grain only: the MTD altitude of the same
 * table is served by ops.mv_cost_center_productivity (refreshed hourly),
 * never recomputed here.
 *
 * Productivity = target (earned) hours over worked hours; overtime% =
 * OT hours over worked hours. Both null-safe on an empty day.
 */
class WorkforceActualsService
{
    /**
     * @return array{
     *     overtime_pct: float|null, agency_rns: int|null, callouts: int|null,
     *     sitters: int|null, productivity_pct: float|null,
     *     premium_cost: float|null, agency_cost: float|null
     * }
     */
    public function todaySummary(): array
    {
        $row = DB::selectOne(
            'SELECT
                 SUM(worked_hours)       AS worked,
                 SUM(overtime_hours)     AS overtime,
                 SUM(target_hours)       AS target,
                 SUM(agency_rn_headcount) AS agency_rns,
                 SUM(callouts)           AS callouts,
                 SUM(sitters)            AS sitters,
                 SUM(premium_cost)       AS premium_cost,
                 SUM(agency_cost)        AS agency_cost,
                 COUNT(*)                AS rows
             FROM prod.workforce_actuals
             WHERE is_deleted = false
               AND work_date = ?',
            [Carbon::today()->toDateString()]
        );

        if ((int) ($row->rows ?? 0) === 0) {
            return [
                'overtime_pct' => null, 'agency_rns' => null, 'callouts' => null,
                'sitters' => null, 'productivity_pct' => null,
                'premium_cost' => null, 'agency_cost' => null,
            ];
        }

        $worked = (float) ($row->worked ?? 0);

        return [
            'overtime_pct' => $worked > 0 ? round(100.0 * (float) $row->overtime / $worked, 1) : null,
            'agency_rns' => (int) ($row->agency_rns ?? 0),
            'callouts' => (int) ($row->callouts ?? 0),
            'sitters' => (int) ($row->sitters ?? 0),
            'productivity_pct' => $worked > 0 ? round(100.0 * (float) $row->target / $worked, 1) : null,
            'premium_cost' => (float) ($row->premium_cost ?? 0),
            'agency_cost' => (float) ($row->agency_cost ?? 0),
        ];
    }
}
