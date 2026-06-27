<?php

namespace App\Services\Predictions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Derives a forward resource-planning forecast from the seeded ~6 months of OR
 * history (prod.or_cases + prod.or_logs + prod.case_metrics + prod.rooms +
 * prod.providers + prod.block_utilization).
 *
 * The model:
 *  1. Build a monthly historical series of performed case volume and realized
 *     OR-hours (actual procedure minutes), plus the average case duration.
 *  2. Drop a leading/trailing partial month when there are enough complete
 *     months, so the trend is not skewed by ramp-up / current-month edges.
 *  3. Fit an ordinary-least-squares line to monthly case volume and project the
 *     next 3 months. A confidence band of +/- 1.5 * residual sigma (floored)
 *     widens the further out we project.
 *  4. Translate projected case volume into required resources via realized
 *     averages: required OR-hours = projected cases * avg case-hours;
 *     required rooms = ceil(OR-hours / staffed room-hours per month);
 *     surgeon sessions = ceil(OR-hours / session length);
 *     required staff = rooms * staff-per-room (anesthesia + circulating + scrub
 *     + tech crew) rounded to the suite.
 *
 * Output shape (consumed by Pages/Predictions/ResourcePlanning.jsx):
 *   {
 *     metrics: { requiredStaff, requiredRooms, projectedUtilization,
 *                requiredOrHours, surgeonSessions, staffingGap, ...trend keys },
 *     series:  [ { label, actual?, forecast?, lower?, upper? } ],   // OR-hours
 *     requirements: [ { date, demand, capacity } ],                 // rooms req vs avail
 *     byMonth: [ { month, staff, rooms, sessions, orHours } ],
 *     hasData, projectionMethod, forecastMonths
 *   }
 *
 * Deterministic and safe on empty tables (returns plausible zeros, never throws).
 * All queries respect soft deletes (is_deleted = false).
 */
class ResourcePlanningService
{
    /** Staffed prime-time hours available per OR room per working day (10h). */
    private const ROOM_HOURS_PER_DAY = 10.0;

    /** Working OR days per month (approx 21 weekdays). */
    private const OR_DAYS_PER_MONTH = 21;

    /** Length of a single surgeon block/session, in hours. */
    private const SESSION_HOURS = 4.0;

    /**
     * Clinical staff required to run one OR room concurrently: anesthesia (1) +
     * circulating nurse (1) + scrub tech (1) + shared turnover/float (0.6).
     */
    private const STAFF_PER_ROOM = 3.6;

    /** Number of months to project forward. */
    private const FORECAST_MONTHS = 3;

    /** Minimum half-width of the confidence band, in cases. */
    private const MIN_BAND_CASES = 8.0;

    /** Target room utilization for the staffing/gap model (%). */
    private const TARGET_UTILIZATION = 80.0;

    /** @return array<string,mixed> */
    public function build(): array
    {
        $history = $this->monthlyHistory();

        if (count($history) === 0) {
            return $this->emptyPayload();
        }

        // Available staffed capacity is anchored on the rooms that actually
        // bear cases in the data (suite size), not the raw room catalog.
        $roomsAvailable = $this->activeRoomCount();
        $surgeonsAvailable = $this->surgeonCount();
        $avgCaseHours = $this->avgCaseHours($history);

        // Complete months only for trend fitting (drop partial endpoints when
        // we still have >= 3 complete months to fit).
        $complete = $this->completeMonths($history);
        $fitSet = count($complete) >= 3 ? $complete : $history;

        $cases = array_map(static fn (array $m): float => (float) $m['cases'], $fitSet);
        [$slope, $intercept] = $this->linearFit($cases);
        $sigma = $this->residualSigma($cases, $slope, $intercept);
        $band = max(self::MIN_BAND_CASES, round(1.5 * $sigma, 1));

        // Historical OR-hours series (actuals).
        $series = [];
        foreach ($history as $m) {
            $series[] = [
                'label' => $m['label'],
                'actual' => (int) round($m['orHours']),
            ];
        }

        // Bridge point: last actual also carries a forecast anchor so the lines
        // join cleanly on the chart.
        $lastIndex = count($fitSet) - 1;
        $lastActualOrHours = (int) round(end($history)['orHours']);
        if (! empty($series)) {
            $series[count($series) - 1]['forecast'] = $lastActualOrHours;
            $series[count($series) - 1]['lower'] = $lastActualOrHours;
            $series[count($series) - 1]['upper'] = $lastActualOrHours;
        }

        // Forward projection.
        $anchorMonth = Carbon::createFromFormat('Y-m', end($history)['ym'])->startOfMonth();
        $forecast = [];
        $requirements = [];
        $byMonth = [];

        for ($h = 1; $h <= self::FORECAST_MONTHS; $h++) {
            $x = $lastIndex + $h;
            $predCases = max(0.0, $slope * $x + $intercept);
            // The band widens with horizon (sqrt growth keeps it composed).
            $hBand = $band * sqrt($h);
            $lowCases = max(0.0, $predCases - $hBand);
            $highCases = $predCases + $hBand;

            $predOrHours = $predCases * $avgCaseHours;
            $lowOrHours = $lowCases * $avgCaseHours;
            $highOrHours = $highCases * $avgCaseHours;

            $reqRooms = $this->roomsForHours($predOrHours);
            $reqSessions = (int) ceil($predOrHours / self::SESSION_HOURS);
            $reqStaff = (int) ceil($reqRooms * self::STAFF_PER_ROOM);

            $month = $anchorMonth->copy()->addMonthsNoOverflow($h);
            $label = $month->format('M Y');

            $series[] = [
                'label' => $label,
                'forecast' => (int) round($predOrHours),
                'lower' => (int) round($lowOrHours),
                'upper' => (int) round($highOrHours),
            ];

            $requirements[] = [
                'date' => $label,
                'demand' => $reqRooms,
                'capacity' => $roomsAvailable,
            ];

            $byMonth[] = [
                'month' => $label,
                'staff' => $reqStaff,
                'rooms' => $reqRooms,
                'sessions' => $reqSessions,
                'orHours' => (int) round($predOrHours),
            ];
        }

        $metrics = $this->headlineMetrics(
            $history,
            $byMonth,
            $roomsAvailable,
            $surgeonsAvailable,
            $avgCaseHours
        );

        return [
            'metrics' => $metrics,
            'series' => $series,
            'requirements' => $requirements,
            'byMonth' => $byMonth,
            'available' => [
                'rooms' => $roomsAvailable,
                'surgeons' => $surgeonsAvailable,
                'staff' => (int) ceil($roomsAvailable * self::STAFF_PER_ROOM),
            ],
            'hasData' => true,
            'forecastMonths' => self::FORECAST_MONTHS,
            'projectionMethod' => 'OLS linear trend on monthly case volume, +/-1.5σ confidence band (sqrt-horizon widening)',
        ];
    }

    // -----------------------------------------------------------------------
    // Headline metric cards
    // -----------------------------------------------------------------------

    /**
     * @param  list<array<string,mixed>>  $history
     * @param  list<array{month:string,staff:int,rooms:int,sessions:int,orHours:int}>  $byMonth
     * @return array<string,mixed>
     */
    private function headlineMetrics(
        array $history,
        array $byMonth,
        int $roomsAvailable,
        int $surgeonsAvailable,
        float $avgCaseHours
    ): array {
        // Next-month projection drives the headline cards.
        $next = $byMonth[0] ?? ['staff' => 0, 'rooms' => 0, 'sessions' => 0, 'orHours' => 0];

        // Current realized resource footprint (most recent complete-ish month):
        // realized OR-hours of the last month -> implied rooms/staff.
        $lastOrHours = (float) end($history)['orHours'];
        $currentRooms = max(1, $this->roomsForHours($lastOrHours));
        $currentStaff = (int) ceil($currentRooms * self::STAFF_PER_ROOM);

        // Projected utilization: projected demanded room-hours over staffed
        // capacity of the available suite.
        $monthlyRoomCapacityHours = $roomsAvailable * self::ROOM_HOURS_PER_DAY * self::OR_DAYS_PER_MONTH;
        $projectedUtilization = $monthlyRoomCapacityHours > 0
            ? min(100.0, round(100.0 * $next['orHours'] / $monthlyRoomCapacityHours, 1))
            : 0.0;

        // Current utilization for the trend arrow.
        $currentUtilization = $monthlyRoomCapacityHours > 0
            ? min(100.0, round(100.0 * $lastOrHours / $monthlyRoomCapacityHours, 1))
            : 0.0;

        // Staffing gap = projected staff need minus what the available suite
        // can field. Positive => short-staffed.
        $availableStaff = (int) ceil($roomsAvailable * self::STAFF_PER_ROOM);
        $staffingGap = $next['staff'] - $availableStaff;

        return [
            'requiredStaff' => [
                'value' => $next['staff'],
                'previousValue' => $currentStaff,
                'trend' => $next['staff'] >= $currentStaff ? 'up' : 'down',
            ],
            'requiredRooms' => [
                'value' => $next['rooms'],
                'available' => $roomsAvailable,
                'previousValue' => $currentRooms,
                'trend' => $next['rooms'] >= $currentRooms ? 'up' : 'down',
            ],
            'projectedUtilization' => [
                'value' => $projectedUtilization,
                'previousValue' => $currentUtilization,
                'trend' => $projectedUtilization >= $currentUtilization ? 'up' : 'down',
            ],
            'requiredOrHours' => [
                'value' => $next['orHours'],
                'previousValue' => (int) round($lastOrHours),
                'trend' => $next['orHours'] >= (int) round($lastOrHours) ? 'up' : 'down',
            ],
            'surgeonSessions' => [
                'value' => $next['sessions'],
                'available' => $surgeonsAvailable,
            ],
            'staffingGap' => [
                'value' => $staffingGap,
                // A shrinking/negative gap is the good direction.
                'trend' => $staffingGap <= 0 ? 'up' : 'down',
            ],
            'avgCaseHours' => round($avgCaseHours, 1),
        ];
    }

    // -----------------------------------------------------------------------
    // Data sourcing
    // -----------------------------------------------------------------------

    /**
     * Monthly performed-case volume + realized OR-hours + avg case minutes.
     *
     * @return list<array{ym:string,label:string,cases:int,orHours:float,avgMin:float,partial:bool}>
     */
    private function monthlyHistory(): array
    {
        $rows = DB::select(
            "SELECT to_char(DATE_TRUNC('month', oc.surgery_date), 'YYYY-MM') AS ym,
                    MIN(oc.surgery_date) AS first_d,
                    MAX(oc.surgery_date) AS last_d,
                    COUNT(*) AS cases,
                    COALESCE(SUM(EXTRACT(EPOCH FROM (l.procedure_end_time - l.procedure_start_time)) / 3600.0), 0) AS or_hours,
                    COALESCE(AVG(EXTRACT(EPOCH FROM (l.procedure_end_time - l.procedure_start_time)) / 60.0), 0) AS avg_min
             FROM prod.or_cases oc
             JOIN prod.or_logs l ON l.case_id = oc.case_id
             WHERE oc.is_deleted = false
               AND l.is_deleted = false
               AND l.procedure_start_time IS NOT NULL
               AND l.procedure_end_time IS NOT NULL
             GROUP BY 1
             ORDER BY 1"
        );

        $out = [];
        foreach ($rows as $r) {
            $first = Carbon::parse($r->first_d);
            $last = Carbon::parse($r->last_d);
            $monthEnd = $first->copy()->endOfMonth();
            // Partial = activity does not span at least the first 5 and last 5
            // days of the month (ramp-up or current month-to-date).
            $partial = $first->day > 5 || $last->day < ($monthEnd->day - 5);

            $out[] = [
                'ym' => (string) $r->ym,
                'label' => Carbon::createFromFormat('Y-m', (string) $r->ym)->format('M Y'),
                'cases' => (int) $r->cases,
                'orHours' => (float) $r->or_hours,
                'avgMin' => (float) $r->avg_min,
                'partial' => $partial,
            ];
        }

        return $out;
    }

    /**
     * Complete months = drop a leading partial and a trailing partial month
     * (only the endpoints; interior months are kept regardless).
     *
     * @param  list<array<string,mixed>>  $history
     * @return list<array<string,mixed>>
     */
    private function completeMonths(array $history): array
    {
        $h = $history;
        if (count($h) > 1 && ($h[0]['partial'] ?? false)) {
            array_shift($h);
        }
        if (count($h) > 1 && ($h[count($h) - 1]['partial'] ?? false)) {
            array_pop($h);
        }

        return array_values($h);
    }

    /** Suite size: distinct rooms that actually bear cases in the data. */
    private function activeRoomCount(): int
    {
        $n = (int) DB::table('prod.or_cases')
            ->where('is_deleted', false)
            ->distinct()
            ->count('room_id');

        return $n > 0 ? $n : 1;
    }

    /** Active surgeon count (provider type = surgeon). */
    private function surgeonCount(): int
    {
        return (int) DB::table('prod.providers')
            ->where('is_deleted', false)
            ->where('type', 'surgeon')
            ->count();
    }

    /**
     * Average realized case duration in hours, weighted by month volume.
     *
     * @param  list<array<string,mixed>>  $history
     */
    private function avgCaseHours(array $history): float
    {
        $totMin = 0.0;
        $totCases = 0;
        foreach ($history as $m) {
            $totMin += $m['avgMin'] * $m['cases'];
            $totCases += $m['cases'];
        }

        $avgMin = $totCases > 0 ? $totMin / $totCases : 0.0;

        return $avgMin > 0 ? round($avgMin / 60.0, 3) : 2.5;
    }

    // -----------------------------------------------------------------------
    // Resource math
    // -----------------------------------------------------------------------

    /** Rooms needed to absorb a monthly OR-hour demand at the staffed day rate. */
    private function roomsForHours(float $orHours): int
    {
        $perRoomMonthly = self::ROOM_HOURS_PER_DAY * self::OR_DAYS_PER_MONTH
            * (self::TARGET_UTILIZATION / 100.0);

        if ($perRoomMonthly <= 0) {
            return 0;
        }

        return max(1, (int) ceil($orHours / $perRoomMonthly));
    }

    // -----------------------------------------------------------------------
    // Trend fitting
    // -----------------------------------------------------------------------

    /**
     * Ordinary least-squares fit y = slope*x + intercept over x = 0..n-1.
     *
     * @param  list<float>  $y
     * @return array{0:float,1:float} [slope, intercept]
     */
    private function linearFit(array $y): array
    {
        $n = count($y);
        if ($n === 0) {
            return [0.0, 0.0];
        }
        if ($n === 1) {
            return [0.0, $y[0]];
        }

        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumXX = 0.0;
        foreach ($y as $i => $v) {
            $sumX += $i;
            $sumY += $v;
            $sumXY += $i * $v;
            $sumXX += $i * $i;
        }

        $denom = ($n * $sumXX) - ($sumX * $sumX);
        if ($denom == 0.0) {
            return [0.0, $sumY / $n];
        }

        $slope = (($n * $sumXY) - ($sumX * $sumY)) / $denom;
        $intercept = ($sumY - ($slope * $sumX)) / $n;

        return [$slope, $intercept];
    }

    /**
     * Residual standard deviation of the fit (population sigma).
     *
     * @param  list<float>  $y
     */
    private function residualSigma(array $y, float $slope, float $intercept): float
    {
        $n = count($y);
        if ($n < 2) {
            return 0.0;
        }

        $ss = 0.0;
        foreach ($y as $i => $v) {
            $pred = $slope * $i + $intercept;
            $ss += ($v - $pred) ** 2;
        }

        return sqrt($ss / $n);
    }

    // -----------------------------------------------------------------------
    // Empty fallback
    // -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function emptyPayload(): array
    {
        $zeroCard = ['value' => 0, 'previousValue' => 0, 'trend' => 'up'];

        return [
            'metrics' => [
                'requiredStaff' => $zeroCard,
                'requiredRooms' => $zeroCard + ['available' => 0],
                'projectedUtilization' => ['value' => 0.0, 'previousValue' => 0.0, 'trend' => 'up'],
                'requiredOrHours' => $zeroCard,
                'surgeonSessions' => ['value' => 0, 'available' => 0],
                'staffingGap' => ['value' => 0, 'trend' => 'up'],
                'avgCaseHours' => 0.0,
            ],
            'series' => [],
            'requirements' => [],
            'byMonth' => [],
            'available' => ['rooms' => 0, 'surgeons' => 0, 'staff' => 0],
            'hasData' => false,
            'forecastMonths' => self::FORECAST_MONTHS,
            'projectionMethod' => 'OLS linear trend on monthly case volume, +/-1.5σ confidence band (sqrt-horizon widening)',
        ];
    }
}
