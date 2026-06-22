<?php

// app/Services/CommandCenterDataService.php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the Hospital Operations Command Center payload from the live DB.
 *
 * All queries target the `prod` schema (DB_SCHEMA=prod).
 * The returned array shape is byte-for-byte identical to the frozen Zod
 * contract in resources/js/types/commandCenter.ts — do NOT alter keys,
 * types, or nullability.
 */
class CommandCenterDataService
{
    // -----------------------------------------------------------------------
    // OKR baseline / target constants (configured, not live-derived).
    // -----------------------------------------------------------------------
    private const OBJ_ED_BOARDING_BASELINE = 192;  // minutes

    private const OBJ_ED_BOARDING_TARGET = 120;  // minutes

    private const OBJ_DBN_BASELINE = 10;   // percent

    private const OBJ_DBN_TARGET = 25;   // percent

    private const OBJ_FCOTS_BASELINE = 76;   // percent

    private const OBJ_FCOTS_TARGET = 85;   // percent

    private const OBJ_BLOCK_UTIL_BASELINE = 71;   // percent

    private const OBJ_BLOCK_UTIL_TARGET = 80;   // percent

    private const OBJ_LOS_GMLOS_BASELINE = 118;  // ratio×100 (1.18)

    private const OBJ_LOS_GMLOS_TARGET = 100;  // ratio×100 (1.00)

    /** @return array<string,mixed> */
    public function build(): array
    {
        // --- Latest census per unit (DISTINCT ON) -------------------------
        $latestCensus = $this->latestCensusPerUnit();

        // --- House-level totals -------------------------------------------
        $totalStaffed = (int) array_sum(array_column($latestCensus, 'staffed_beds'));
        $totalOccupied = (int) array_sum(array_column($latestCensus, 'occupied'));
        $totalAvailable = (int) array_sum(array_column($latestCensus, 'available'));
        $totalBlocked = (int) array_sum(array_column($latestCensus, 'blocked'));

        $occupancyPct = $totalStaffed > 0
            ? (int) round($totalOccupied / $totalStaffed * 100)
            : 0;

        // --- Pending admits & ED boarding --------------------------------
        $pendingAdmits = (int) DB::table('prod.bed_requests')
            ->where('status', 'pending')
            ->where('is_deleted', false)
            ->count();

        $edBoarding = (int) DB::table('prod.ed_visits')
            ->where('disposition', 'admitted')
            ->whereNull('bed_assigned_at')
            ->where('is_deleted', false)
            ->count();

        // --- Net beds & discharges ready ---------------------------------
        $netBeds = $totalAvailable - $pendingAdmits;

        $dischargesReady = (int) DB::table('prod.encounters')
            ->where('status', 'active')
            ->where('is_deleted', false)
            ->whereDate('expected_discharge_date', Carbon::today())
            ->count();

        // --- Strain -------------------------------------------------------
        $strain = $this->strain($occupancyPct, $edBoarding, $pendingAdmits);

        // --- Unit census array (for capacity band & forecast detail) ------
        $units = $this->buildUnitCensusArray($latestCensus);

        // --- Flow sub-metrics (computed once, reused in objectives) -------
        $flowMetrics = $this->computeFlowMetrics();

        // --- Outcomes (computed once, reused in objectives) ---------------
        $outcomesMetrics = $this->computeOutcomesMetrics();

        // --- Forecast metrics (computed once, shared by forecastBand + forecastDetail) ---
        $forecastMetrics = $this->computeForecastMetrics($netBeds, $occupancyPct, $totalAvailable);

        return [
            'generatedAtIso' => now()->toIso8601String(),
            'strain' => $strain,
            'heroMetrics' => $this->heroMetrics(
                $occupancyPct, $netBeds, $edBoarding, $dischargesReady
            ),
            'capacity' => $this->capacityBand($units, $totalAvailable, $totalBlocked, $occupancyPct),
            'flow' => $this->flowBand($flowMetrics, $edBoarding),
            'outcomes' => $this->outcomesBand($outcomesMetrics),
            'forecast' => $this->forecastBand($forecastMetrics, $netBeds, $occupancyPct, $pendingAdmits),
            'forecastDetail' => $this->forecastDetail($forecastMetrics, $netBeds, $units, $occupancyPct),
            'unitCensus' => array_values(array_map(
                fn (array $u): array => array_diff_key($u, ['_netBedUnit' => true]),
                $units
            )),
            'objectives' => $this->objectives($flowMetrics, $outcomesMetrics),
        ];
    }

    // -----------------------------------------------------------------------
    // metric() helper — unchanged shape from the original service
    // -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function metric(
        string $key, string $label, float|int $value, string $unit, string $display,
        float|int|null $target, ?string $targetDisplay, string $status,
        ?array $points, string $direction, bool $goodWhenDown, ?string $drillHref, string $definition,
    ): array {
        return [
            'key' => $key, 'label' => $label, 'value' => $value, 'unit' => $unit, 'display' => $display,
            'target' => $target, 'targetDisplay' => $targetDisplay, 'status' => $status,
            'trajectory' => $points === null ? null
                : ['points' => $points, 'direction' => $direction, 'goodWhenDown' => $goodWhenDown],
            'drillHref' => $drillHref, 'definition' => $definition,
        ];
    }

    // -----------------------------------------------------------------------
    // Centralised status-banding helper — single source of truth for thresholds
    // -----------------------------------------------------------------------

    /**
     * Occupancy-style banding: high values are bad.
     * ≥ critThreshold → critical, ≥ warnThreshold → warning, else success.
     */
    private function bandHighBad(float|int $value, float|int $critThreshold, float|int $warnThreshold): string
    {
        if ($value >= $critThreshold) {
            return 'critical';
        }
        if ($value >= $warnThreshold) {
            return 'warning';
        }

        return 'success';
    }

    /**
     * Target-style banding: low values are bad (e.g. DBN%, FCOTS%).
     * ≥ goodThreshold → success, ≥ warnThreshold → warning, else critical.
     */
    private function bandLowBad(float|int $value, float|int $goodThreshold, float|int $warnThreshold): string
    {
        if ($value >= $goodThreshold) {
            return 'success';
        }
        if ($value >= $warnThreshold) {
            return 'warning';
        }

        return 'critical';
    }

    /**
     * OKR progress-style banding.
     */
    private function bandProgress(int $pct): string
    {
        if ($pct >= 80) {
            return 'success';
        }
        if ($pct >= 40) {
            return 'warning';
        }

        return 'critical';
    }

    // -----------------------------------------------------------------------
    // Latest census per unit (DISTINCT ON)
    // -----------------------------------------------------------------------

    /** @return array<int,object> */
    private function latestCensusPerUnit(): array
    {
        $rows = DB::select(
            'SELECT DISTINCT ON (cs.unit_id)
                cs.unit_id, cs.staffed_beds, cs.occupied, cs.available,
                cs.blocked, cs.acuity_adjusted_capacity,
                u.name AS unit_name, u.type AS unit_type, u.abbreviation
             FROM prod.census_snapshots cs
             JOIN prod.units u ON u.unit_id = cs.unit_id
             WHERE u.is_deleted = false
             ORDER BY cs.unit_id, cs.captured_at DESC'
        );

        return $rows;
    }

    // -----------------------------------------------------------------------
    // Unit census array (for capacity band + forecastDetail)
    // -----------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function buildUnitCensusArray(array $census): array
    {
        // Pull today's bed_need per unit from rtdc_predictions (by_2pm horizon).
        $bedNeeds = DB::table('prod.rtdc_predictions')
            ->whereDate('service_date', Carbon::today())
            ->where('horizon', 'by_2pm')
            ->where('is_deleted', false)
            ->pluck('bed_need', 'unit_id')
            ->toArray();

        return array_values(array_map(function (object $r) use ($bedNeeds): array {
            $occPct = $r->staffed_beds > 0
                ? (int) round($r->occupied / $r->staffed_beds * 100)
                : 0;
            $acuityPct = $r->acuity_adjusted_capacity > 0
                ? min(100, (int) round($r->occupied / $r->acuity_adjusted_capacity * 100))
                : min(100, $occPct + 3);
            $status = $this->bandHighBad($occPct, 92, 85);

            $bedNeed = $bedNeeds[$r->unit_id] ?? 0;
            $netBedUnit = $r->available - (int) $bedNeed;

            return [
                'unitId' => (int) $r->unit_id,
                'name' => $r->unit_name,
                'type' => ucfirst(str_replace('_', '-', $r->unit_type)),
                'staffed' => (int) $r->staffed_beds,
                'occupied' => (int) $r->occupied,
                'blocked' => (int) $r->blocked,
                'available' => (int) $r->available,
                'occupancyPct' => $occPct,
                'acuityAdjustedPct' => $acuityPct,
                'status' => $status,
                '_netBedUnit' => $netBedUnit, // internal — stripped before return
            ];
        }, $census));
    }

    // -----------------------------------------------------------------------
    // Strain
    // -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function strain(int $occupancyPct, int $boarding, int $pendingAdmits): array
    {
        $score = 0;
        $score += $occupancyPct >= 92 ? 2 : ($occupancyPct >= 85 ? 1 : 0);
        $score += $boarding >= 6 ? 1 : 0;
        $score += $pendingAdmits >= 10 ? 1 : 0;
        $level = max(0, min(4, $score));
        $status = $level >= 3 ? 'critical' : ($level >= 2 ? 'warning' : 'success');

        return [
            'level' => $level,
            'label' => "Surge Level {$level}",
            'status' => $status,
            'previousLevel' => max(0, $level - 1),
            'drivers' => [
                ['label' => 'Occupancy', 'value' => "{$occupancyPct}%",
                    'status' => $this->bandHighBad($occupancyPct, 92, 85)],
                ['label' => 'ED boarding', 'value' => (string) $boarding,
                    'status' => $boarding >= 6 ? 'critical' : ($boarding > 0 ? 'warning' : 'success')],
                ['label' => 'Pending admits', 'value' => (string) $pendingAdmits,
                    'status' => $pendingAdmits >= 10 ? 'warning' : 'success'],
            ],
            'updatedAtIso' => now()->toIso8601String(),
        ];
    }

    // -----------------------------------------------------------------------
    // Hero metrics
    // -----------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function heroMetrics(int $occupancyPct, int $netBeds, int $boarding, int $dcReady): array
    {
        return [
            $this->metric(
                'occupancy', 'Occupancy', $occupancyPct, '%', "{$occupancyPct}%",
                85, '≤85%', $this->bandHighBad($occupancyPct, 92, 85),
                null, 'up', true, '/rtdc/bed-tracking',
                'Staffed beds occupied as a percent of staffed capacity. Safe zone ≤85%.'
            ),
            $this->metric(
                'net_beds', 'Net Bed Position', $netBeds, 'beds', (string) $netBeds,
                0, '≥0', $netBeds < 0 ? 'critical' : ($netBeds === 0 ? 'warning' : 'success'),
                null, 'down', false, '/rtdc/predictions/demand',
                'Projected available minus projected demand over the next 4–8h.'
            ),
            $this->metric(
                'ed_boarding', 'ED Boarding', $boarding, 'pts', (string) $boarding,
                0, '0', $boarding >= 6 ? 'critical' : ($boarding > 0 ? 'warning' : 'success'),
                null, 'down', true, '/dashboard/emergency',
                'Count of admitted ED patients awaiting an inpatient bed; goal is zero boarding, with each placed within 4h of the admit decision (Joint Commission).'
            ),
            $this->metric(
                'dc_ready', 'Discharges Ready', $dcReady, 'pts', (string) $dcReady,
                null, 'DBN 25%', 'success',
                null, 'up', false, '/rtdc/bed-placement',
                'Patients with completed discharge orders awaiting departure.'
            ),
        ];
    }

    // -----------------------------------------------------------------------
    // Capacity band
    // -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function capacityBand(array $units, int $available, int $blocked, int $occupancyPct): array
    {
        // House-level acuity-adjusted pct: average of unit-level values.
        $acuityVals = array_column($units, 'acuityAdjustedPct');
        $avgAcuity = count($acuityVals) > 0
            ? (int) round(array_sum($acuityVals) / count($acuityVals))
            : $occupancyPct;

        return [
            'key' => 'capacity',
            'title' => 'Capacity',
            'summary' => "{$occupancyPct}% occupied house-wide",
            'drillHref' => '/rtdc/bed-tracking',
            'drillLabel' => 'open RTDC',
            'metrics' => [
                $this->metric(
                    'available_beds', 'Available', $available, 'beds', (string) $available,
                    null, null, 'success',
                    null, 'up', false, '/rtdc/bed-tracking',
                    'Staffed, unoccupied, unblocked beds available now.'
                ),
                $this->metric(
                    'blocked_beds', 'Blocked', $blocked, 'beds', (string) $blocked,
                    0, '0', $blocked > 4 ? 'warning' : 'success',
                    null, 'flat', true, '/rtdc/bed-tracking',
                    'Beds offline due to staffing, environmental, or isolation barriers.'
                ),
                $this->metric(
                    'acuity_adjusted', 'Acuity-Adjusted', $avgAcuity, '%', "{$avgAcuity}%",
                    null, null, $this->bandHighBad($avgAcuity, 92, 85),
                    null, 'up', true, '/rtdc/bed-tracking',
                    'Capacity adjusted for current patient acuity mix.'
                ),
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Flow metrics — computed once and passed around
    // -----------------------------------------------------------------------

    /** @return array<string,mixed> Raw computed values for flow sub-groups */
    private function computeFlowMetrics(): array
    {
        $window = Carbon::now()->subHours(24);

        // --- ED flow -------------------------------------------------------
        $d2p = DB::selectOne(
            'SELECT CAST(
                 percentile_cont(0.5) WITHIN GROUP (
                     ORDER BY EXTRACT(EPOCH FROM provider_seen_at - arrived_at) / 60
                 ) AS integer
             ) AS med_min
             FROM prod.ed_visits
             WHERE provider_seen_at IS NOT NULL
               AND arrived_at >= ?
               AND is_deleted = false',
            [$window->toDateTimeString()]
        );
        $doorToProvider = (int) ($d2p->med_min ?? 0);

        $edCountRow = DB::table('prod.ed_visits')
            ->where('arrived_at', '>=', $window)
            ->where('is_deleted', false)
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN disposition = ? THEN 1 ELSE 0 END) AS lwbs_cnt', ['lwbs'])
            ->first();
        $edTotal = (int) ($edCountRow->total ?? 0);
        $lwbsCnt = (int) ($edCountRow->lwbs_cnt ?? 0);
        $lwbs = $edTotal > 0
            ? round(100.0 * $lwbsCnt / $edTotal, 1)
            : 0.0;

        $edLosRow = DB::selectOne(
            "SELECT CAST(
                 percentile_cont(0.5) WITHIN GROUP (
                     ORDER BY EXTRACT(EPOCH FROM departed_at - arrived_at) / 60
                 ) AS integer
             ) AS med_min
             FROM prod.ed_visits
             WHERE disposition = 'discharged'
               AND departed_at IS NOT NULL
               AND arrived_at >= ?
               AND is_deleted = false",
            [$window->toDateTimeString()]
        );
        $edLos = (int) ($edLosRow->med_min ?? 0);

        // --- Inpatient flow -----------------------------------------------
        // 7-day window — reflects recent performance, not all-history cumulative.
        $admToBedRow = DB::selectOne(
            "SELECT CAST(
                 percentile_cont(0.5) WITHIN GROUP (
                     ORDER BY EXTRACT(EPOCH FROM bpd.created_at - br.created_at) / 60
                 ) AS integer
             ) AS med_min
             FROM prod.bed_placement_decisions bpd
             JOIN prod.bed_requests br ON br.bed_request_id = bpd.bed_request_id
             WHERE br.is_deleted = false
               AND bpd.created_at >= now() - INTERVAL '7 days'"
        );
        $admToBed = (int) ($admToBedRow->med_min ?? 0);

        $dbnRow = DB::table('prod.encounters')
            ->where('is_deleted', false)
            ->whereNotNull('discharged_at')
            ->whereDate('discharged_at', Carbon::today())
            ->selectRaw(
                "COUNT(*) AS total,
                 SUM(CASE WHEN CAST(discharged_at AS TIME) < '12:00:00' THEN 1 ELSE 0 END) AS before_noon"
            )
            ->first();
        $dbnTotal = (int) ($dbnRow->total ?? 0);
        $dbnBeforeNoon = (int) ($dbnRow->before_noon ?? 0);
        $dbn = $dbnTotal > 0 ? (int) round(100.0 * $dbnBeforeNoon / $dbnTotal) : 0;

        // --- OR flow (most recent OR day) ----------------------------------
        $latestOrDay = DB::table('prod.or_cases')
            ->where('is_deleted', false)
            ->max('surgery_date');

        $fcots = 0;
        $blockUtil = 0;
        $turnover = 0;
        $cancellations = 0;

        if ($latestOrDay) {
            // FCOTS: first case per room where procedure_start ≤ scheduled_start + 15 min.
            $fcotsRow = DB::selectOne(
                "SELECT
                     COUNT(*) AS total_first,
                     SUM(CASE WHEN l.procedure_start_time <= c.scheduled_start_time + INTERVAL '15 minutes'
                              THEN 1 ELSE 0 END) AS on_time
                 FROM (
                     SELECT DISTINCT ON (room_id)
                         case_id, room_id, scheduled_start_time
                     FROM prod.or_cases
                     WHERE surgery_date = ?
                       AND is_deleted = false
                     ORDER BY room_id, scheduled_start_time ASC
                 ) c
                 JOIN prod.or_logs l ON l.case_id = c.case_id
                 WHERE l.is_deleted = false
                   AND l.procedure_start_time IS NOT NULL",
                [$latestOrDay]
            );
            $firstTotal = (int) ($fcotsRow->total_first ?? 0);
            $onTime = (int) ($fcotsRow->on_time ?? 0);
            $fcots = $firstTotal > 0 ? (int) round(100.0 * $onTime / $firstTotal) : 0;

            // Block utilization.
            $buRow = DB::table('prod.block_utilization')
                ->whereDate('date', $latestOrDay)
                ->where('is_deleted', false)
                ->selectRaw('AVG(utilization_percentage) AS avg_util')
                ->first();
            $blockUtil = (int) round((float) ($buRow->avg_util ?? 0));

            // Turnover: avg turnover_time from case_metrics for the day.
            $turnoverRow = DB::table('prod.case_metrics AS cm')
                ->join('prod.or_cases AS oc', 'oc.case_id', '=', 'cm.case_id')
                ->whereDate('oc.surgery_date', $latestOrDay)
                ->where('oc.is_deleted', false)
                ->where('cm.is_deleted', false)
                ->whereNotNull('cm.turnover_time')
                ->selectRaw('ROUND(AVG(cm.turnover_time)) AS avg_turnover')
                ->first();
            $turnover = (int) round((float) ($turnoverRow->avg_turnover ?? 0));

            // Cancellations today.
            $cancelStatusId = DB::table('prod.case_statuses')
                ->where('code', 'CANC')
                ->value('status_id');

            if ($cancelStatusId !== null) {
                $cancellations = (int) DB::table('prod.or_cases')
                    ->where('status_id', $cancelStatusId)
                    ->whereDate('surgery_date', Carbon::today())
                    ->where('is_deleted', false)
                    ->count();
            }
        }

        return [
            'door_to_provider' => $doorToProvider,
            'lwbs' => $lwbs,
            'ed_los' => $edLos,
            'adm_to_bed' => $admToBed,
            'dbn' => $dbn,
            'fcots' => $fcots,
            'block_util' => $blockUtil,
            'turnover' => $turnover,
            'cancellations' => $cancellations,
        ];
    }

    // -----------------------------------------------------------------------
    // Flow band
    // -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function flowBand(array $fm, int $edBoarding): array
    {
        $d2p = $fm['door_to_provider'];
        $lwbs = $fm['lwbs'];
        $edLos = $fm['ed_los'];
        $admToBed = $fm['adm_to_bed'];
        $dbn = $fm['dbn'];
        $fcots = $fm['fcots'];
        $blockUtil = $fm['block_util'];
        $turnover = $fm['turnover'];
        $cancellations = $fm['cancellations'];

        return [
            'key' => 'flow',
            'title' => 'Flow',
            'summary' => 'ED · Inpatient · OR throughput',
            'drillHref' => '/dashboard/emergency',
            'drillLabel' => 'open ED',
            'metrics' => [],
            'subgroups' => [
                ['key' => 'ed', 'label' => 'Emergency', 'metrics' => [
                    $this->metric(
                        'ed_d2p', 'Door-to-Provider', $d2p, 'min', "{$d2p}m",
                        20, '<20m', $this->bandHighBad($d2p, 30, 20),
                        null, 'down', true, '/dashboard/emergency',
                        'Median arrival to provider evaluation.'
                    ),
                    $this->metric(
                        'ed_lwbs', 'LWBS', $lwbs, '%', "{$lwbs}%",
                        2, '<2%', $this->bandHighBad($lwbs, 3, 2),
                        null, 'down', true, '/dashboard/emergency',
                        'Left without being seen.'
                    ),
                    $this->metric(
                        'ed_los', 'ED LOS (disch)', $edLos, 'min', "{$edLos}m",
                        150, '<150m', $this->bandHighBad($edLos, 200, 150),
                        null, 'down', true, '/dashboard/emergency',
                        'Median ED length of stay, discharged patients.'
                    ),
                ]],
                ['key' => 'ip', 'label' => 'Inpatient', 'metrics' => [
                    $this->metric(
                        'adm_to_bed', 'Admit→Bed', $admToBed, 'min', "{$admToBed}m",
                        60, '<60m', $this->bandHighBad($admToBed, 90, 60),
                        null, 'down', true, '/rtdc/bed-placement',
                        'Admit decision to bed assigned.'
                    ),
                    $this->metric(
                        'dbn', 'Discharge by Noon', $dbn, '%', "{$dbn}%",
                        25, '25%', $this->bandLowBad($dbn, 25, 15),
                        null, 'up', false, '/rtdc/bed-placement',
                        'Percent of discharges completed before noon.'
                    ),
                ]],
                ['key' => 'or', 'label' => 'Operating Room', 'metrics' => [
                    $this->metric(
                        'fcots', 'First-Case On-Time', $fcots, '%', "{$fcots}%",
                        85, '≥85%', $this->bandLowBad($fcots, 85, 70),
                        null, 'up', false, '/dashboard/perioperative',
                        'First cases starting on time (15-min grace).'
                    ),
                    $this->metric(
                        'block_util', 'Block Utilization', $blockUtil, '%', "{$blockUtil}%",
                        80, '80%', $this->bandLowBad($blockUtil, 80, 70),
                        null, 'up', false, '/dashboard/perioperative',
                        'Used block minutes / allocated block minutes.'
                    ),
                    $this->metric(
                        'turnover', 'Turnover', $turnover, 'min', "{$turnover}m",
                        25, '<25m', $this->bandHighBad($turnover, 35, 25),
                        null, 'down', true, '/dashboard/perioperative',
                        'Median room turnover time.'
                    ),
                    $this->metric(
                        'cancellations', 'Same-Day Cxl', $cancellations, 'cases', (string) $cancellations,
                        null, null, $cancellations >= 5 ? 'warning' : 'success',
                        null, 'down', true, '/dashboard/perioperative',
                        'Day-of-surgery cancellations.'
                    ),
                ]],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Outcomes metrics — computed once, shared with objectives
    // -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function computeOutcomesMetrics(): array
    {
        $window = Carbon::now()->subHours(24);
        $today = Carbon::today();

        // Readmission: 30-day all-cause forward-looking definition.
        // Cohort = discharges in the last 30 days (metric window must match horizon).
        // Readmission = a LATER admission for the same patient_ref within 30 days AFTER discharged_at.
        $readm30 = Carbon::now()->subDays(30);
        $readmRow = DB::selectOne(
            "SELECT
                 COUNT(*) AS total_discharged,
                 SUM(CASE WHEN readmit.encounter_id IS NOT NULL THEN 1 ELSE 0 END) AS readmitted
             FROM prod.encounters e
             LEFT JOIN prod.encounters readmit
                 ON readmit.patient_ref = e.patient_ref
                AND readmit.admitted_at > e.discharged_at
                AND readmit.admitted_at <= e.discharged_at + INTERVAL '30 days'
                AND readmit.encounter_id <> e.encounter_id
                AND readmit.is_deleted = false
             WHERE e.status = 'discharged'
               AND e.discharged_at IS NOT NULL
               AND e.discharged_at >= ?
               AND e.is_deleted = false",
            [$readm30->toDateTimeString()]
        );
        $readmTotal = (int) ($readmRow->total_discharged ?? 0);
        $readmCount = (int) ($readmRow->readmitted ?? 0);
        $readmission = $readmTotal > 0
            ? round(100.0 * $readmCount / $readmTotal, 1)
            : 0.0;

        // LOS vs GMLOS — unbounded by design: cumulative quality metric, not a rolling window.
        $losRow = DB::selectOne(
            'SELECT
                 SUM(EXTRACT(EPOCH FROM e.discharged_at - e.admitted_at) / 86400) AS sum_los,
                 SUM(g.gmlos_days)                                                AS sum_gmlos,
                 SUM(GREATEST(0, EXTRACT(EPOCH FROM e.discharged_at - e.admitted_at) / 86400 - g.gmlos_days))
                     AS excess_days
             FROM prod.encounters e
             JOIN prod.units u ON u.unit_id = e.unit_id
             JOIN prod.gmlos_references g ON g.unit_type = u.type
             WHERE e.discharged_at IS NOT NULL
               AND e.is_deleted = false'
        );
        $sumLos = (float) ($losRow->sum_los ?? 0);
        $sumGmlos = (float) ($losRow->sum_gmlos ?? 0);
        $losGmlos = $sumGmlos > 0
            ? round($sumLos / $sumGmlos, 2)
            : 0.0;
        $excessDays = (int) round((float) ($losRow->excess_days ?? 0));

        // Diversion hours in the last 24h.
        $diversionRows = DB::table('prod.diversion_events')
            ->where('is_deleted', false)
            ->where('started_at', '<', Carbon::now())
            ->where(function ($q) use ($window) {
                $q->whereNull('ended_at')
                    ->orWhere('ended_at', '>', $window);
            })
            ->get(['started_at', 'ended_at']);

        $diversionHours = 0.0;
        foreach ($diversionRows as $div) {
            $start = max(Carbon::parse($div->started_at), $window);
            $end = $div->ended_at ? Carbon::parse($div->ended_at) : Carbon::now();
            $diversionHours += max(0, $end->diffInMinutes($start)) / 60;
        }
        $diversion = (int) round($diversionHours);

        // Active PDSA cycles.
        $pdsaActive = (int) DB::table('prod.pdsa_cycles')
            ->whereIn('status', ['active', 'planned'])
            ->where('is_deleted', false)
            ->count();

        return [
            'readmission' => $readmission,
            'los_gmlos' => $losGmlos,
            'excess_days' => $excessDays,
            'diversion' => $diversion,
            'pdsa_active' => $pdsaActive,
        ];
    }

    // -----------------------------------------------------------------------
    // Outcomes band
    // -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function outcomesBand(array $om): array
    {
        return [
            'key' => 'outcomes',
            'title' => 'Outcomes',
            'summary' => 'Safety & efficiency results',
            'drillHref' => '/dashboard/improvement',
            'drillLabel' => 'open Improvement',
            'metrics' => [
                $this->metric(
                    'readmission', '30-Day Readmission', $om['readmission'], '%', "{$om['readmission']}%",
                    11, '<11%', $this->bandHighBad($om['readmission'], 13, 11),
                    null, 'down', true, '/dashboard/improvement',
                    '30-day all-cause readmission rate.'
                ),
                $this->metric(
                    'los_gmlos', 'LOS / GMLOS', $om['los_gmlos'], 'x', number_format($om['los_gmlos'], 2),
                    1.0, '1.00', $this->bandHighBad($om['los_gmlos'], 1.2, 1.0),
                    null, 'down', true, '/dashboard/improvement',
                    'Observed LOS vs geometric-mean LOS.'
                ),
                $this->metric(
                    'excess_days', 'Excess Bed-Days', $om['excess_days'], 'days', (string) $om['excess_days'],
                    null, null, $om['excess_days'] > 100 ? 'warning' : 'success',
                    null, 'down', true, '/dashboard/improvement',
                    'Avoidable bed-days vs GMLOS this period.'
                ),
                $this->metric(
                    'diversion', 'Diversion Hours', $om['diversion'], 'h', "{$om['diversion']}h",
                    0, '0h', $om['diversion'] > 0 ? 'warning' : 'success',
                    null, 'down', true, '/dashboard/improvement',
                    'Capacity-related ED diversion hours.'
                ),
                $this->metric(
                    'pdsa_active', 'Active PDSA', $om['pdsa_active'], 'cycles', (string) $om['pdsa_active'],
                    null, null, 'info',
                    null, 'up', false, '/dashboard/improvement',
                    'Improvement cycles in progress.'
                ),
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Forecast metrics — computed once, shared by forecastBand + forecastDetail
    //
    // Horizon convention:
    //   24h metrics  → by_2pm horizon only  (one row per unit)
    //   48h metric   → both horizons summed (by_2pm + by_midnight = two rows per unit)
    // -----------------------------------------------------------------------

    /** @return array<string,mixed> Raw computed forecast values */
    private function computeForecastMetrics(int $netBeds, int $occupancyPct, int $available): array
    {
        $today = Carbon::today()->toDateString();

        // 24h: by_2pm horizon only — avoids double-counting the two daily rows.
        $row24 = DB::table('prod.rtdc_predictions')
            ->where('service_date', $today)
            ->where('horizon', 'by_2pm')
            ->where('is_deleted', false)
            ->selectRaw(
                'SUM(discharges_definite + discharges_probable) AS pred_discharges,
                 SUM(demand_ed)           AS pred_arrivals,
                 SUM(demand_expected)     AS pred_admissions,
                 SUM(discharges_weighted) AS sum_wt_dc'
            )
            ->first();

        $predDischarges24h = (int) round((float) ($row24->pred_discharges ?? 0));
        $predArrivals = (int) round((float) ($row24->pred_arrivals ?? 0));
        $predAdmissions = (int) round((float) ($row24->pred_admissions ?? 0));
        $sumWtDc = (float) ($row24->sum_wt_dc ?? 0);

        // 48h: both horizons (by_2pm + by_midnight) legitimately summed for a longer window.
        $row48 = DB::table('prod.rtdc_predictions')
            ->where('service_date', $today)
            ->where('is_deleted', false)
            ->selectRaw('SUM(discharges_definite + discharges_probable) AS pred_discharges')
            ->first();

        $predDischarges48h = (int) round((float) ($row48->pred_discharges ?? 0));

        // Net beds (projected) = available_now + weighted_discharges − expected_demand (24h).
        $netBedsFc = (int) round($available + $sumWtDc - $predAdmissions);

        // Average reliability for surge heuristic.
        $avgReliability = (float) (DB::table('prod.rtdc_reconciliations')
            ->selectRaw('AVG(reliability_score) AS avg_rel')
            ->first()?->avg_rel ?? 0.8);

        // Surge probability heuristic (documented, not a trained model).
        $surgePct = (int) max(0, min(95,
            round(($occupancyPct - 80) * 4 + max(0, -$netBeds) * 5 + (1 - $avgReliability) * 20)
        ));

        return [
            'pred_discharges_24h' => $predDischarges24h,
            'pred_discharges_48h' => $predDischarges48h,
            'pred_arrivals' => $predArrivals,
            'pred_admissions' => $predAdmissions,
            'sum_wt_dc' => $sumWtDc,
            'net_beds_fc' => $netBedsFc,
            'surge_pct' => $surgePct,
        ];
    }

    // -----------------------------------------------------------------------
    // Forecast band
    // -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function forecastBand(array $fm, int $netBeds, int $occupancyPct, int $pendingAdmits): array
    {
        $predDischarges = $fm['pred_discharges_24h'];
        $predArrivals = $fm['pred_arrivals'];
        $netBedsFc = $fm['net_beds_fc'];
        $surgePct = $fm['surge_pct'];

        return [
            'key' => 'forecast',
            'title' => 'Forecast',
            'summary' => 'Next 24–48h projection',
            'drillHref' => '/rtdc/predictions/demand',
            'drillLabel' => 'open Predictions',
            'metrics' => [
                $this->metric(
                    'pred_discharges', 'Discharges 24h', $predDischarges, 'pts', (string) $predDischarges,
                    null, null, 'info',
                    null, 'up', false, '/rtdc/predictions/discharge',
                    'Predicted discharges in the next 24h.'
                ),
                $this->metric(
                    'pred_arrivals', 'ED Arrivals 24h', $predArrivals, 'pts', (string) $predArrivals,
                    null, null, 'info',
                    null, 'up', false, '/rtdc/predictions/demand',
                    'Predicted ED arrivals in the next 24h.'
                ),
                $this->metric(
                    'net_beds_fc', 'Net Beds (proj)', $netBedsFc, 'beds', (string) $netBedsFc,
                    0, '≥0', $netBedsFc < 0 ? 'critical' : ($netBedsFc === 0 ? 'warning' : 'success'),
                    null, 'down', false, '/rtdc/predictions/demand',
                    'Projected supply minus demand at the next demand peak.'
                ),
                $this->metric(
                    'surge_prob', 'Surge Probability', $surgePct, '%', "{$surgePct}%",
                    null, null, $this->bandHighBad($surgePct, 60, 40),
                    null, 'up', true, '/rtdc/predictions/demand',
                    'Modeled probability of a surge event in 24h.'
                ),
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Forecast detail
    // -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function forecastDetail(array $fm, int $netBeds, array $units, int $occupancyPct): array
    {
        $predDc24 = $fm['pred_discharges_24h'];
        $predDc48 = $fm['pred_discharges_48h'];
        $predArr = $fm['pred_arrivals'];
        $predAdm = $fm['pred_admissions'];
        $sumWtDc = $fm['sum_wt_dc'];
        $surgePct = $fm['surge_pct'];

        // Occupancy curve: 24h deterministic heuristic.
        // Distribute predicted admissions to afternoon/evening and discharges to late morning.
        $curve = [];
        $base = $occupancyPct;
        $totalHours = 24;
        for ($h = 0; $h <= $totalHours; $h += 2) {
            // Discharge peak at hours 10-14 (morning), admission peak at 14-20.
            $dcEffect = $sumWtDc > 0
                ? -(int) round($sumWtDc * $this->curveWeight($h, 10, 14) * 2)
                : 0;
            $admEffect = $predAdm > 0
                ? (int) round($predAdm * $this->curveWeight($h, 14, 20) * 1.5)
                : 0;
            $occ = max(0, min(100, $base + $dcEffect + $admEffect));
            $curve[] = [
                'hourOffset' => $h,
                'occupancyPct' => $occ,
                'lowerPct' => max(0, $occ - 3),
                'upperPct' => min(100, $occ + 3),
            ];
        }

        // Net bed by unit: strip internal _netBedUnit key, expose as 'net'.
        $netBedByUnit = array_values(array_map(
            fn (array $u): array => [
                'unitId' => $u['unitId'],
                'name' => $u['name'],
                'net' => $u['_netBedUnit'],
            ],
            $units
        ));

        return [
            'predictedDischarges24h' => $predDc24,
            'predictedDischarges48h' => $predDc48,
            'predictedEdArrivals' => $predArr,
            'predictedAdmissions' => $predAdm,
            'netBedPosition' => $netBeds,
            'surgeProbabilityPct' => $surgePct,
            'occupancyCurve' => $curve,
            'netBedByUnit' => $netBedByUnit,
        ];
    }

    /**
     * Bell-curve weight for hour $h centered on [$peakStart, $peakEnd].
     * Returns a fraction 0–1.
     */
    private function curveWeight(int $h, int $peakStart, int $peakEnd): float
    {
        $center = ($peakStart + $peakEnd) / 2;
        $spread = ($peakEnd - $peakStart) / 2 + 2;
        $dist = abs($h - $center);

        return max(0.0, 1.0 - $dist / $spread);
    }

    // -----------------------------------------------------------------------
    // Objectives (OKR scoreboard)
    // -----------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function objectives(array $fm, array $om): array
    {
        // ED boarding current: proxy = median admit-decision-to-bed-assigned minutes
        // (the "boarding time" for admitted patients), scaled from minutes.
        // Spec says: "ED boarding minutes proxy from ed_visits".
        $boardingMinRow = DB::selectOne(
            "SELECT CAST(
                 percentile_cont(0.5) WITHIN GROUP (
                     ORDER BY EXTRACT(EPOCH FROM bed_assigned_at - admit_decision_at) / 60
                 ) AS integer
             ) AS med_min
             FROM prod.ed_visits
             WHERE disposition = 'admitted'
               AND admit_decision_at IS NOT NULL
               AND bed_assigned_at IS NOT NULL
               AND is_deleted = false"
        );
        $edBoardingMinutes = (int) ($boardingMinRow->med_min ?? self::OBJ_ED_BOARDING_BASELINE);
        // Guard: if no data, use baseline so progress = 0.
        if ($edBoardingMinutes <= 0) {
            $edBoardingMinutes = self::OBJ_ED_BOARDING_BASELINE;
        }

        $dbn = $fm['dbn'];
        $fcots = $fm['fcots'];
        $blockUtil = $fm['block_util'];
        // LOS/GMLOS as ×100 integer for OKR comparison.
        $losGmlosInt = (int) round($om['los_gmlos'] * 100);
        if ($losGmlosInt <= 0) {
            $losGmlosInt = self::OBJ_LOS_GMLOS_BASELINE;
        }

        // Direction-aware progress, clamped 0–100.
        // For "lower is better" (boarding, LOS): progress = (baseline-current)/(baseline-target)*100.
        // For "higher is better" (DBN, FCOTS, block util): progress = (current-baseline)/(target-baseline)*100.
        $progressDown = function (int|float $current, int|float $baseline, int|float $target): int {
            if ($baseline === $target) {
                return 0;
            }

            return max(0, min(100, (int) round(100 * ($baseline - $current) / ($baseline - $target))));
        };
        $progressUp = function (int|float $current, int|float $baseline, int|float $target): int {
            if ($baseline === $target) {
                return 0;
            }

            return max(0, min(100, (int) round(100 * ($current - $baseline) / ($target - $baseline))));
        };

        // OKR 1: flow
        $boardingPct = $progressDown($edBoardingMinutes, self::OBJ_ED_BOARDING_BASELINE, self::OBJ_ED_BOARDING_TARGET);
        $dbnPct = $progressUp($dbn, self::OBJ_DBN_BASELINE, self::OBJ_DBN_TARGET);

        // OKR 2: OR throughput
        $fcotsPct = $progressUp($fcots, self::OBJ_FCOTS_BASELINE, self::OBJ_FCOTS_TARGET);
        $blockPct = $progressUp($blockUtil, self::OBJ_BLOCK_UTIL_BASELINE, self::OBJ_BLOCK_UTIL_TARGET);

        // OKR 3: bed-days
        $losPct = $progressDown($losGmlosInt, self::OBJ_LOS_GMLOS_BASELINE, self::OBJ_LOS_GMLOS_TARGET);

        return [
            ['key' => 'flow', 'title' => 'Improve access & flow', 'keyResults' => [
                [
                    'label' => 'ED boarding',
                    'current' => $edBoardingMinutes,
                    'target' => self::OBJ_ED_BOARDING_TARGET,
                    'baseline' => self::OBJ_ED_BOARDING_BASELINE,
                    'progressPct' => $boardingPct,
                    'status' => $this->bandProgress($boardingPct),
                    'display' => "{$edBoardingMinutes}→<".self::OBJ_ED_BOARDING_TARGET.' min',
                ],
                [
                    'label' => 'Discharge by noon',
                    'current' => $dbn,
                    'target' => self::OBJ_DBN_TARGET,
                    'baseline' => self::OBJ_DBN_BASELINE,
                    'progressPct' => $dbnPct,
                    'status' => $this->bandProgress($dbnPct),
                    'display' => "{$dbn}%→".self::OBJ_DBN_TARGET.'%',
                ],
            ]],
            ['key' => 'or', 'title' => 'Maximize surgical throughput', 'keyResults' => [
                [
                    'label' => 'First-case on-time',
                    'current' => $fcots,
                    'target' => self::OBJ_FCOTS_TARGET,
                    'baseline' => self::OBJ_FCOTS_BASELINE,
                    'progressPct' => $fcotsPct,
                    'status' => $this->bandProgress($fcotsPct),
                    'display' => "{$fcots}%→".self::OBJ_FCOTS_TARGET.'%',
                ],
                [
                    'label' => 'Block utilization',
                    'current' => $blockUtil,
                    'target' => self::OBJ_BLOCK_UTIL_TARGET,
                    'baseline' => self::OBJ_BLOCK_UTIL_BASELINE,
                    'progressPct' => $blockPct,
                    'status' => $this->bandProgress($blockPct),
                    'display' => "{$blockUtil}%→".self::OBJ_BLOCK_UTIL_TARGET.'%',
                ],
            ]],
            ['key' => 'beddays', 'title' => 'Eliminate avoidable bed-days', 'keyResults' => [
                [
                    'label' => 'LOS / GMLOS',
                    'current' => $losGmlosInt,
                    'target' => self::OBJ_LOS_GMLOS_TARGET,
                    'baseline' => self::OBJ_LOS_GMLOS_BASELINE,
                    'progressPct' => $losPct,
                    'status' => $this->bandProgress($losPct),
                    'display' => number_format($losGmlosInt / 100, 2).'→'.number_format(self::OBJ_LOS_GMLOS_TARGET / 100, 2),
                ],
            ]],
        ];
    }
}
