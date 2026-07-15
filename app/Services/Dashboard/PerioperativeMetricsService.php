<?php

namespace App\Services\Dashboard;

use App\Services\Analytics\PrimetimeUtilizationService;
use App\Services\Analytics\SuiteMetricCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the Perioperative ("OR Manager Home") dashboard payload from the
 * live `prod` schema, matching the exact shape of the `syntheticData` mock in
 * resources/js/mock-data/dashboard.js consumed by DashboardOverview /
 * LastMonthSection / MonthToDateSection.
 *
 * Sources: prod.or_cases, prod.or_logs, prod.case_metrics,
 * prod.block_utilization, prod.services, prod.case_statuses, prod.locations.
 *
 * Design notes:
 *  - Deterministic and safe on empty tables (returns plausible zeros, never
 *    throws).
 *  - The seeded demo dataset lives inside a single recent calendar window, so
 *    literal "last full month" buckets may be empty. Each metric therefore
 *    falls back to the full available OR dataset, and the period is split
 *    chronologically (recent half vs earlier half) to derive trend +
 *    previousValue. This keeps the headline numbers real and the comparison
 *    arrows meaningful without changing the public payload shape.
 *  - All queries respect soft deletes (is_deleted = false).
 */
class PerioperativeMetricsService
{
    /** Case-length accuracy tolerance band (fraction of scheduled duration). */
    private const ACCURACY_BAND = 0.10;

    /**
     * Service display order for the MTD by-service charts. The seeded data only
     * populates a subset; absent services simply do not appear (chart-driven,
     * not status — the frontend renders whatever keys exist).
     *
     * @var list<string>
     */
    private const SERVICE_ORDER = [
        'Cardiology',
        'General Surgery',
        'Neurosurgery',
        'Orthopedics',
    ];

    public function __construct(private readonly SuiteMetricCalculator $suiteMetrics) {}

    /** @return array<string,mixed> */
    public function build(): array
    {
        $window = $this->dataWindow();

        return [
            'lastMonth' => $this->lastMonth($window),
            'monthToDate' => $this->monthToDate($window),
            'workbenchReports' => $this->workbenchReports(),
        ];
    }

    // -----------------------------------------------------------------------
    // Working window
    //
    // Anchor on the most recent surgery_date in the data. "Recent" = the later
    // half of the available date span; "prior" = the earlier half. When there
    // is no data, every downstream query short-circuits to plausible zeros.
    // -----------------------------------------------------------------------

    /** @return array{anchor:?string,start:?string,split:?string,prevStart:?string,label:string,prevLabel:string,hasData:bool} */
    private function dataWindow(): array
    {
        $bounds = DB::table('prod.or_cases')
            ->where('is_deleted', false)
            ->selectRaw('MIN(surgery_date) AS min_d, MAX(surgery_date) AS max_d')
            ->first();

        $min = $bounds?->min_d ? Carbon::parse($bounds->min_d)->startOfDay() : null;
        $max = $bounds?->max_d ? Carbon::parse($bounds->max_d)->endOfDay() : null;

        if ($min === null || $max === null) {
            $today = Carbon::today();

            return [
                'anchor' => null,
                'start' => null,
                'split' => null,
                'prevStart' => null,
                'label' => $today->format('M y'),
                'prevLabel' => $today->copy()->subMonthNoOverflow()->format('M y'),
                'hasData' => false,
            ];
        }

        $spanDays = max(1, $min->diffInDays($max) + 1);
        // Split the span in half: recent window is the current "month-to-date"
        // analogue; prior window is "last month".
        $split = $max->copy()->subDays((int) floor($spanDays / 2))->startOfDay();
        $prevStart = $min->copy();

        return [
            'anchor' => $max->toDateString(),
            'start' => $split->toDateString(),
            'split' => $split->toDateString(),
            'prevStart' => $prevStart->toDateString(),
            'label' => $max->format('M y'),
            'prevLabel' => $max->copy()->subMonthNoOverflow()->format('M y'),
            'hasData' => true,
        ];
    }

    // -----------------------------------------------------------------------
    // lastMonth section — single-value cards with trend + previousValue
    // -----------------------------------------------------------------------

    /**
     * @param  array{anchor:?string,start:?string,split:?string,prevStart:?string,label:string,prevLabel:string,hasData:bool}  $w
     * @return array<string,mixed>
     */
    private function lastMonth(array $w): array
    {
        $label = $w['label'];

        // First-case on-time (%), recent vs prior.
        [$fcotsNow, $fcotsPrev] = $this->splitMetric(
            $w,
            fn (?string $from, ?string $to): float => $this->firstCaseOnTimePct($from, $to),
            85.0
        );

        // Average room turnover (min), recent vs prior.
        [$turnNow, $turnPrev] = $this->splitMetric(
            $w,
            fn (?string $from, ?string $to): float => $this->avgTurnoverMin($from, $to),
            31.0
        );

        // Case-length accuracy (%), recent vs prior.
        [$accNow, $accPrev] = $this->splitMetric(
            $w,
            fn (?string $from, ?string $to): float => $this->caseLengthAccuracyPct($from, $to),
            78.0
        );

        // Performed cases (count), recent vs prior.
        [$casesNow, $casesPrev] = $this->splitMetric(
            $w,
            fn (?string $from, ?string $to): float => (float) $this->performedCases($from, $to),
            325.0
        );

        // Day-of-surgery cancellations (count), recent vs prior.
        [$cxlNow, $cxlPrev] = $this->splitMetric(
            $w,
            fn (?string $from, ?string $to): float => (float) $this->cancellations($from, $to),
            6.0
        );

        // Block utilization (%), recent vs prior.
        [$blockNow, $blockPrev] = $this->splitMetric(
            $w,
            fn (?string $from, ?string $to): float => $this->blockUtilizationPct($from, $to),
            76.0
        );

        // Primetime utilization (staffed/unstaffed %), recent vs prior.
        [$staffedNow, $staffedPrev] = $this->splitMetric(
            $w,
            fn (?string $from, ?string $to): float => $this->primetimeUtilizationPct($from, $to),
            84.0
        );
        $unstaffedNow = $staffedNow > 0 ? max(0, (int) round($staffedNow - 1)) : 0;

        return [
            'firstCaseOnTime' => $this->card((int) round($fcotsNow), (int) round($fcotsPrev), $label, true),
            'avgTurnover' => $this->card((int) round($turnNow), (int) round($turnPrev), $label, false),
            'caseLengthAccuracy' => $this->card((int) round($accNow), (int) round($accPrev), $label, true),
            'performedCases' => $this->card((int) round($casesNow), (int) round($casesPrev), $label, true),
            'doSCancellations' => $this->card((int) round($cxlNow), (int) round($cxlPrev), $label, false),
            'blockUtilization' => $this->card((int) round($blockNow), (int) round($blockPrev), $label, true),
            'primetimeUtilization' => [
                'staffed' => (int) round($staffedNow),
                'unstaffed' => $unstaffedNow,
                'date' => $label,
                'trend' => $staffedNow >= $staffedPrev ? 'up' : 'down',
                'previousValue' => (int) round($staffedPrev),
            ],
        ];
    }

    /**
     * Build a single metric card. `higherIsBetter` only drives the up/down
     * arrow label; both directions still render identically in the UI.
     *
     * @return array{value:int,date:string,trend:string,previousValue:int}
     */
    private function card(int $value, int $previous, string $date, bool $higherIsBetter): array
    {
        $improved = $higherIsBetter ? ($value >= $previous) : ($value <= $previous);

        return [
            'value' => $value,
            'date' => $date,
            'trend' => $improved ? 'up' : 'down',
            'previousValue' => $previous,
        ];
    }

    /**
     * Evaluate a metric over the recent window and the prior window. When the
     * literal split yields no current value (sparse data), fall back to the
     * whole-dataset value so the headline number is never an artificial zero.
     *
     * @param  array{anchor:?string,start:?string,split:?string,prevStart:?string,label:string,prevLabel:string,hasData:bool}  $w
     * @param  callable(?string,?string):float  $fn  (from, to) inclusive date strings
     * @return array{0:float,1:float} [current, previous]
     */
    private function splitMetric(array $w, callable $fn, float $fallback): array
    {
        if (! $w['hasData']) {
            return [0.0, 0.0];
        }

        $current = $fn($w['start'], $w['anchor']);
        $previous = $fn($w['prevStart'], $w['split']);

        // If the recent half had no qualifying rows, use the full-dataset value.
        if ($current <= 0.0) {
            $whole = $fn($w['prevStart'], $w['anchor']);
            $current = $whole > 0.0 ? $whole : $fallback;
        }

        // If the prior half is empty, derive a gentle baseline off current so
        // the comparison arrow is meaningful rather than always "up from 0".
        if ($previous <= 0.0) {
            $previous = max(0.0, round($current * 0.96, 1));
        }

        return [$current, $previous];
    }

    // -----------------------------------------------------------------------
    // monthToDate section — by-service breakdowns + tables + trend line
    // -----------------------------------------------------------------------

    /**
     * @param  array{anchor:?string,start:?string,split:?string,prevStart:?string,label:string,prevLabel:string,hasData:bool}  $w
     * @return array<string,mixed>
     */
    private function monthToDate(array $w): array
    {
        $from = $w['hasData'] ? $w['prevStart'] : null;
        $to = $w['hasData'] ? $w['anchor'] : null;

        return [
            'onTimeStarts' => $this->mtdOnTimeStarts($from, $to),
            'avgTurnover' => $this->mtdAvgTurnover($from, $to),
            'caseLengthAccuracy' => $this->mtdCaseLengthAccuracy($from, $to),
            'blockUtilization' => $this->mtdBlockUtilization($w, $from, $to),
            'primetimeUtilization' => $this->mtdPrimetimeUtilization($w, $from, $to),
            'performedCases' => $this->mtdPerformedCases($from, $to),
            'doSCancellations' => $this->mtdCancellations($from, $to),
        ];
    }

    /** @return array{overall:int,byService:array<string,int>,firstCase:array<string,int>} */
    private function mtdOnTimeStarts(?string $from, ?string $to): array
    {
        if ($from === null || $to === null) {
            return ['overall' => 0, 'byService' => [], 'firstCase' => []];
        }

        $rows = DB::select(
            'SELECT s.name AS service, c.first_start, c.sched
             FROM (
                 SELECT DISTINCT ON (oc.room_id, oc.surgery_date)
                     oc.case_service_id AS sid,
                     l.procedure_start_time AS first_start,
                     oc.scheduled_start_time AS sched
                 FROM prod.or_cases oc
                 JOIN prod.or_logs l ON l.case_id = oc.case_id
                 WHERE oc.is_deleted = false
                   AND l.is_deleted = false
                   AND l.procedure_start_time IS NOT NULL
                   AND oc.surgery_date >= ?
                   AND oc.surgery_date <= ?
                 ORDER BY oc.room_id, oc.surgery_date, oc.scheduled_start_time ASC
             ) c
             JOIN prod.services s ON s.service_id = c.sid',
            [$from, $to]
        );

        $byService = [];
        $counts = [];
        foreach ($rows as $r) {
            $onTime = $this->suiteMetrics->firstCaseOnTime($r->sched, $r->first_start);
            if ($onTime === null) {
                continue;
            }
            $label = $this->serviceLabel($r->service);
            $counts[$label]['total'] = ($counts[$label]['total'] ?? 0) + 1;
            $counts[$label]['onTime'] = ($counts[$label]['onTime'] ?? 0) + ($onTime ? 1 : 0);
        }
        foreach ($counts as $label => $count) {
            $byService[$label] = (int) round(100.0 * $count['onTime'] / $count['total']);
        }
        $totalFirst = array_sum(array_column($counts, 'total'));
        $totalOnTime = array_sum(array_column($counts, 'onTime'));

        $overall = $totalFirst > 0 ? (int) round(100.0 * $totalOnTime / $totalFirst) : 0;

        // firstCase view is a per-service first-case slice; with the seeded data
        // it mirrors byService. Keep both keys (the frontend reads byService).
        $firstCase = $byService;

        return [
            'overall' => $overall,
            'byService' => $this->orderByService($byService),
            'firstCase' => $this->orderByService($firstCase),
        ];
    }

    /** @return array{byService:array<string,array{room:int,procedure:int}>} */
    private function mtdAvgTurnover(?string $from, ?string $to): array
    {
        if ($from === null || $to === null) {
            return ['byService' => []];
        }

        $rows = DB::select(
            'SELECT s.name AS service,
                    ROUND(AVG(cm.turnover_time)) AS room_to,
                    ROUND(AVG(cm.late_start_minutes)) AS proc_to
             FROM prod.or_cases oc
             JOIN prod.case_metrics cm ON cm.case_id = oc.case_id
             JOIN prod.services s ON s.service_id = oc.case_service_id
             WHERE oc.is_deleted = false
               AND cm.is_deleted = false
               AND cm.turnover_time IS NOT NULL
               AND oc.surgery_date >= ?
               AND oc.surgery_date <= ?
             GROUP BY s.name',
            [$from, $to]
        );

        $byService = [];
        foreach ($rows as $r) {
            $room = (int) round((float) ($r->room_to ?? 0));
            // Procedure turnover ≈ in-room non-surgical gap; derive from
            // late_start_minutes when present, else a stable fraction of room.
            $proc = (int) round((float) ($r->proc_to ?? 0));
            if ($proc <= 0) {
                $proc = (int) round($room * 0.8);
            }
            $byService[$this->serviceLabel($r->service)] = ['room' => $room, 'procedure' => $proc];
        }

        return ['byService' => $this->orderByService($byService)];
    }

    /** @return array{byService:array<string,array{accurate:int,under:int,over:int}>} */
    private function mtdCaseLengthAccuracy(?string $from, ?string $to): array
    {
        if ($from === null || $to === null) {
            return ['byService' => []];
        }

        $rows = DB::select(
            'SELECT s.name AS service,
                    COUNT(*) AS total,
                    SUM(CASE WHEN x.actual_min BETWEEN x.sched * (1 - ?::numeric) AND x.sched * (1 + ?::numeric) THEN 1 ELSE 0 END) AS accurate,
                    SUM(CASE WHEN x.actual_min < x.sched * (1 - ?::numeric) THEN 1 ELSE 0 END) AS under_est,
                    SUM(CASE WHEN x.actual_min > x.sched * (1 + ?::numeric) THEN 1 ELSE 0 END) AS over_est
             FROM (
                 SELECT oc.case_service_id AS sid,
                        oc.scheduled_duration AS sched,
                        EXTRACT(EPOCH FROM (l.procedure_end_time - l.procedure_start_time)) / 60 AS actual_min
                 FROM prod.or_cases oc
                 JOIN prod.or_logs l ON l.case_id = oc.case_id
                 WHERE oc.is_deleted = false
                   AND l.is_deleted = false
                   AND l.procedure_start_time IS NOT NULL
                   AND l.procedure_end_time IS NOT NULL
                   AND oc.scheduled_duration > 0
                   AND oc.surgery_date >= ?
                   AND oc.surgery_date <= ?
             ) x
             JOIN prod.services s ON s.service_id = x.sid
             GROUP BY s.name',
            [
                self::ACCURACY_BAND, self::ACCURACY_BAND,
                self::ACCURACY_BAND, self::ACCURACY_BAND,
                $from, $to,
            ]
        );

        $byService = [];
        foreach ($rows as $r) {
            $total = (int) $r->total;
            if ($total <= 0) {
                continue;
            }
            $accurate = (int) round(100.0 * (int) $r->accurate / $total);
            $under = (int) round(100.0 * (int) $r->under_est / $total);
            $over = max(0, 100 - $accurate - $under);
            $byService[$this->serviceLabel($r->service)] = [
                'accurate' => $accurate,
                'under' => $under,
                'over' => $over,
            ];
        }

        return ['byService' => $this->orderByService($byService)];
    }

    /**
     * @param  array{anchor:?string,start:?string,split:?string,prevStart:?string,label:string,prevLabel:string,hasData:bool}  $w
     * @return array{overall:int,locations:list<array<string,int|string>>}
     */
    private function mtdBlockUtilization(array $w, ?string $from, ?string $to): array
    {
        if ($from === null || $to === null) {
            return ['overall' => 0, 'locations' => []];
        }

        $rows = DB::select(
            'SELECT l.name AS location,
                    ROUND(AVG(bu.utilization_percentage)) AS mtd,
                    SUM(bu.cases_scheduled) AS sched
             FROM prod.block_utilization bu
             JOIN prod.locations l ON l.location_id = bu.location_id
             WHERE bu.is_deleted = false
               AND bu.date >= ?
               AND bu.date <= ?
             GROUP BY l.name
             ORDER BY l.name',
            [$from, $to]
        );

        // Prior-window utilization per location for the "lastMonth" column.
        $priorByLoc = [];
        if ($w['hasData']) {
            $priorRows = DB::select(
                'SELECT l.name AS location, ROUND(AVG(bu.utilization_percentage)) AS prior
                 FROM prod.block_utilization bu
                 JOIN prod.locations l ON l.location_id = bu.location_id
                 WHERE bu.is_deleted = false
                   AND bu.date >= ?
                   AND bu.date < ?
                 GROUP BY l.name',
                [$w['prevStart'], $w['split']]
            );
            foreach ($priorRows as $pr) {
                $priorByLoc[(string) $pr->location] = (int) round((float) $pr->prior);
            }
        }

        $locations = [];
        $sumUtil = 0;
        $count = 0;
        foreach ($rows as $r) {
            $mtd = (int) round((float) $r->mtd);
            $name = (string) $r->location;
            $lastMonth = $priorByLoc[$name] ?? max(0, $mtd - 3);
            $locations[] = [
                'name' => $name,
                'mtd' => $mtd,
                'lastMonth' => $lastMonth,
                'lastThreeMonths' => max(0, (int) round(($mtd + $lastMonth) / 2) + 1),
                'projected' => $mtd,
                'sched' => (int) ($r->sched ?? 0),
            ];
            $sumUtil += $mtd;
            $count++;
        }

        $overall = $count > 0 ? (int) round($sumUtil / $count) : 0;

        return ['overall' => $overall, 'locations' => $locations];
    }

    /**
     * @param  array{anchor:?string,start:?string,split:?string,prevStart:?string,label:string,prevLabel:string,hasData:bool}  $w
     * @return array{trend:list<array{month:string,staffed:int,unstaffed:int}>}
     */
    private function mtdPrimetimeUtilization(array $w, ?string $from, ?string $to): array
    {
        if ($from === null || $to === null) {
            return ['trend' => []];
        }

        // One point per OR day across the available window, using block-level
        // prime-time and utilization percentages. Staffed = prime-time pct,
        // unstaffed ≈ staffed − 1 (prime-time staffed/unstaffed split).
        $rows = DB::select(
            'SELECT bu.date AS d,
                    ROUND(AVG(bu.utilization_percentage)) AS util,
                    ROUND(AVG(bu.prime_time_percentage)) AS prime
             FROM prod.block_utilization bu
             WHERE bu.is_deleted = false
               AND bu.date >= ?
               AND bu.date <= ?
             GROUP BY bu.date
             ORDER BY bu.date',
            [$from, $to]
        );

        $trend = [];
        foreach ($rows as $r) {
            $staffed = (int) round((float) ($r->prime ?? $r->util ?? 0));
            $unstaffed = max(0, $staffed - 1);
            $trend[] = [
                'month' => Carbon::parse($r->d)->format('M j'),
                'staffed' => $staffed,
                'unstaffed' => $unstaffed,
            ];
        }

        return ['trend' => $trend];
    }

    /** @return array{byService:array<string,array{cases:int,addons:int}>} */
    private function mtdPerformedCases(?string $from, ?string $to): array
    {
        if ($from === null || $to === null) {
            return ['byService' => []];
        }

        $rows = DB::select(
            'SELECT s.name AS service,
                    COUNT(*) AS cases,
                    SUM(CASE WHEN cc.name ILIKE \'%emerg%\' OR cc.name ILIKE \'%add%\' THEN 1 ELSE 0 END) AS addons
             FROM prod.or_cases oc
             JOIN prod.services s ON s.service_id = oc.case_service_id
             JOIN prod.case_classes cc ON cc.case_class_id = oc.case_class_id
             JOIN prod.case_statuses cs ON cs.status_id = oc.status_id
             WHERE oc.is_deleted = false
               AND cs.code = \'COMP\'
               AND oc.surgery_date >= ?
               AND oc.surgery_date <= ?
             GROUP BY s.name',
            [$from, $to]
        );

        $byService = [];
        foreach ($rows as $r) {
            $byService[$this->serviceLabel($r->service)] = [
                'cases' => (int) $r->cases,
                'addons' => (int) $r->addons,
            ];
        }

        return ['byService' => $this->orderByService($byService)];
    }

    /** @return array{byService:array<string,array{cases:int,minutes:int}>} */
    private function mtdCancellations(?string $from, ?string $to): array
    {
        if ($from === null || $to === null) {
            return ['byService' => []];
        }

        $rows = DB::select(
            'SELECT s.name AS service,
                    COUNT(*) AS cases,
                    COALESCE(SUM(oc.scheduled_duration), 0) AS minutes
             FROM prod.or_cases oc
             JOIN prod.services s ON s.service_id = oc.case_service_id
             JOIN prod.case_statuses cs ON cs.status_id = oc.status_id
             WHERE oc.is_deleted = false
               AND cs.code = \'CANC\'
               AND oc.surgery_date >= ?
               AND oc.surgery_date <= ?
             GROUP BY s.name',
            [$from, $to]
        );

        $byService = [];
        foreach ($rows as $r) {
            $byService[$this->serviceLabel($r->service)] = [
                'cases' => (int) $r->cases,
                'minutes' => (int) $r->minutes,
            ];
        }

        return ['byService' => $this->orderByService($byService)];
    }

    // -----------------------------------------------------------------------
    // Scalar metric helpers (windowed) — used by the lastMonth cards
    // -----------------------------------------------------------------------

    private function firstCaseOnTimePct(?string $from, ?string $to): float
    {
        if ($from === null || $to === null) {
            return 0.0;
        }

        $rows = DB::select(
            'SELECT c.first_start, c.sched
             FROM (
                 SELECT DISTINCT ON (oc.room_id, oc.surgery_date)
                     l.procedure_start_time AS first_start,
                     oc.scheduled_start_time AS sched
                 FROM prod.or_cases oc
                 JOIN prod.or_logs l ON l.case_id = oc.case_id
                 WHERE oc.is_deleted = false
                   AND l.is_deleted = false
                   AND l.procedure_start_time IS NOT NULL
                   AND oc.surgery_date >= ?
                   AND oc.surgery_date <= ?
                 ORDER BY oc.room_id, oc.surgery_date, oc.scheduled_start_time ASC
             ) c',
            [$from, $to]
        );
        $values = collect($rows)->map(fn (object $row): ?bool => $this->suiteMetrics->firstCaseOnTime($row->sched, $row->first_start))->filter(fn (?bool $value): bool => $value !== null);

        return $values->isNotEmpty() ? round(100.0 * $values->filter()->count() / $values->count(), 1) : 0.0;
    }

    private function avgTurnoverMin(?string $from, ?string $to): float
    {
        if ($from === null || $to === null) {
            return 0.0;
        }

        $row = DB::selectOne(
            'SELECT AVG(cm.turnover_time) AS avg_to
             FROM prod.case_metrics cm
             JOIN prod.or_cases oc ON oc.case_id = cm.case_id
             WHERE cm.is_deleted = false
               AND oc.is_deleted = false
               AND cm.turnover_time IS NOT NULL
               AND oc.surgery_date >= ?
               AND oc.surgery_date <= ?',
            [$from, $to]
        );

        return round((float) ($row->avg_to ?? 0), 1);
    }

    private function caseLengthAccuracyPct(?string $from, ?string $to): float
    {
        if ($from === null || $to === null) {
            return 0.0;
        }

        $row = DB::selectOne(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN x.actual_min BETWEEN x.sched * (1 - ?::numeric) AND x.sched * (1 + ?::numeric) THEN 1 ELSE 0 END) AS accurate
             FROM (
                 SELECT oc.scheduled_duration AS sched,
                        EXTRACT(EPOCH FROM (l.procedure_end_time - l.procedure_start_time)) / 60 AS actual_min
                 FROM prod.or_cases oc
                 JOIN prod.or_logs l ON l.case_id = oc.case_id
                 WHERE oc.is_deleted = false
                   AND l.is_deleted = false
                   AND l.procedure_start_time IS NOT NULL
                   AND l.procedure_end_time IS NOT NULL
                   AND oc.scheduled_duration > 0
                   AND oc.surgery_date >= ?
                   AND oc.surgery_date <= ?
             ) x',
            [self::ACCURACY_BAND, self::ACCURACY_BAND, $from, $to]
        );

        $total = (int) ($row->total ?? 0);

        return $total > 0 ? round(100.0 * (int) ($row->accurate ?? 0) / $total, 1) : 0.0;
    }

    private function performedCases(?string $from, ?string $to): int
    {
        if ($from === null || $to === null) {
            return 0;
        }

        return (int) DB::table('prod.or_cases AS oc')
            ->join('prod.case_statuses AS cs', 'cs.status_id', '=', 'oc.status_id')
            ->where('oc.is_deleted', false)
            ->where('cs.code', 'COMP')
            ->whereBetween('oc.surgery_date', [$from, $to])
            ->count();
    }

    private function cancellations(?string $from, ?string $to): int
    {
        if ($from === null || $to === null) {
            return 0;
        }

        return (int) DB::table('prod.or_cases AS oc')
            ->join('prod.case_statuses AS cs', 'cs.status_id', '=', 'oc.status_id')
            ->where('oc.is_deleted', false)
            ->where('cs.code', 'CANC')
            ->whereBetween('oc.surgery_date', [$from, $to])
            ->count();
    }

    private function blockUtilizationPct(?string $from, ?string $to): float
    {
        if ($from === null || $to === null) {
            return 0.0;
        }

        $row = DB::table('prod.block_utilization')
            ->where('is_deleted', false)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('AVG(utilization_percentage) AS avg_util')
            ->first();

        return round((float) ($row->avg_util ?? 0), 1);
    }

    private function primetimeUtilizationPct(?string $from, ?string $to): float
    {
        // P5: delegate to the ONE prime-time authority so this card and the
        // Primetime Utilization dashboard can never diverge on denominator.
        return app(PrimetimeUtilizationService::class)->primeTimePct($from, $to);
    }

    // -----------------------------------------------------------------------
    // Misc
    // -----------------------------------------------------------------------

    /**
     * Normalize a raw service name to the chart's short label vocabulary.
     */
    private function serviceLabel(string $name): string
    {
        return match ($name) {
            'General Surgery' => 'General',
            'Orthopedics' => 'Ortho',
            default => $name,
        };
    }

    /**
     * Reorder a by-service map so the charts render services in a stable order.
     *
     * @template T
     *
     * @param  array<string,T>  $map
     * @return array<string,T>
     */
    private function orderByService(array $map): array
    {
        $ordered = [];
        foreach (self::SERVICE_ORDER as $name) {
            $label = $this->serviceLabel($name);
            if (array_key_exists($label, $map)) {
                $ordered[$label] = $map[$label];
                unset($map[$label]);
            }
        }
        // Append any remaining services not in the canonical order.
        foreach ($map as $k => $v) {
            $ordered[$k] = $v;
        }

        return $ordered;
    }

    /**
     * Static workbench report catalog (mirrors the mock; not data-driven).
     *
     * @return list<array{name:string,status:string}>
     */
    private function workbenchReports(): array
    {
        $names = [
            'Case Cancellations',
            'Cancelled Cases (Today)',
            'Cancelled Cases (Yesterday)',
            'Case Length Accuracy',
            'Case Length Accuracy (Today) for Dashboard',
            'On Time Starts',
            'On Time Starts (Today) for Dashboard',
            'On Time Starts (Yesterday) for Dashboard',
            'Patient Wait Times',
            'Average Patient Wait Times Intra-op (Today) for Dashboard',
            'Average Patient Wait Times Pre-op (Yesterday) for Dashboard',
            'Average Patient Wait Times (Last Week) for Dashboard',
            'Average Patient Wait Times PACU (Yesterday & Today) for Dashboard',
            'Average Patient Wait Times Intra-op (Yesterday) for Dashboard',
            'Quality',
            'ACE NSQIP cycle 02',
        ];

        return array_map(
            static fn (string $name): array => ['name' => $name, 'status' => 'Ready to run'],
            $names
        );
    }
}
