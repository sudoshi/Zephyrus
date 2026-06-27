<?php

namespace App\Services\Predictions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the OR Utilization Forecast payload from the live `prod` schema for
 * the /predictions/forecast page (resources/js/Pages/Predictions/UtilizationForecast.jsx).
 *
 * Historical signal: monthly OR prime-time utilization — per (room, surgery_date)
 * the prime-time minutes are summed and divided by a 720-min staffed prime-time
 * day, capped at 100% and averaged across room-days, then rolled up by calendar
 * month. This is the same canonical metric used by OrUtilizationService and lands
 * in the realistic 70-85% band.
 *
 * Forward projection: ordinary least-squares linear trend over the monthly
 * series, extrapolated `horizon` months ahead. The confidence band is ±1.96·σ
 * where σ is the residual standard deviation of the fit (≈95% interval).
 *
 * Payload shape (consumed by the page):
 *   metrics: { predictedUtilization, predictedUtilizationTrend, currentUtilization,
 *              confidence, predictionRange, historicalAccuracy, projectedCaseVolume,
 *              projectedCaseTrend, bottleneckRisk, bottleneckRiskLevel,
 *              targetUtilization, horizonMonths, timeframe }
 *   series:  [{ label, actual|null, forecast|null, lower|null, upper|null,
 *              projectedCases|null, type:'historical'|'forecast' }]
 *   factors: [{ name, impact }]   // relative contribution weights (%)
 *   hasData: bool
 *
 * Design notes:
 *  - Deterministic and safe on empty tables (returns a valid zero payload,
 *    never throws).
 *  - All queries respect soft deletes (is_deleted = false).
 */
class UtilizationForecastService
{
    /** Staffed prime-time minutes available per room per OR day (12h). */
    private const STAFFED_PRIME_MIN = 720;

    /** Utilization target line (%) used for the bottleneck/risk model. */
    private const TARGET_UTILIZATION = 80.0;

    /** z-score for the ~95% confidence band. */
    private const CONF_Z = 1.96;

    /** Months projected forward per timeframe selector value. */
    private const HORIZON_MONTHS = [
        'month' => 1,
        'quarter' => 3,
        'year' => 12,
    ];

    /** @return array<string,mixed> */
    public function build(string $timeframe = 'month'): array
    {
        $horizon = self::HORIZON_MONTHS[$timeframe] ?? self::HORIZON_MONTHS['month'];

        $history = $this->monthlyHistory();

        if (count($history) === 0) {
            return $this->emptyPayload($timeframe, $horizon);
        }

        $utilPoints = array_map(static fn (array $h): float => $h['util'], $history);
        $casePoints = array_map(static fn (array $h): float => (float) $h['cases'], $history);

        $utilFit = $this->linearFit($utilPoints);
        $caseFit = $this->linearFit($casePoints);

        $residualStd = $this->residualStd($utilPoints, $utilFit);
        $band = round(self::CONF_Z * $residualStd, 1);

        $series = $this->assembleSeries($history, $utilFit, $caseFit, $horizon, $band);

        $forecastUtil = [];
        $forecastCases = 0;
        foreach ($series as $point) {
            if (($point['forecast'] ?? null) !== null && $point['type'] === 'forecast') {
                $forecastUtil[] = $point['forecast'];
                $forecastCases += (int) ($point['projectedCases'] ?? 0);
            }
        }

        $predictedUtilization = count($forecastUtil) > 0
            ? round(array_sum($forecastUtil) / count($forecastUtil), 1)
            : round(end($utilPoints), 1);

        $currentUtilization = round(end($utilPoints), 1);
        $utilTrend = round($predictedUtilization - $currentUtilization, 1);

        $confidence = $this->confidencePct($utilPoints, $utilFit, $residualStd);
        $historicalAccuracy = $this->historicalAccuracyPct($utilPoints, $utilFit);

        $projectedCases = $forecastCases > 0
            ? $forecastCases
            : (int) round(end($casePoints));
        $currentCases = (int) round(end($casePoints));
        $caseTrend = $currentCases > 0
            ? round(100.0 * ($projectedCases / max(1, $horizon) - $currentCases) / $currentCases, 1)
            : 0.0;

        [$riskLabel, $riskLevel] = $this->bottleneckRisk($predictedUtilization, $utilFit['slope']);

        return [
            'metrics' => [
                'predictedUtilization' => $predictedUtilization,
                'predictedUtilizationTrend' => $utilTrend,
                'currentUtilization' => $currentUtilization,
                'confidence' => $confidence,
                'predictionRange' => $band,
                'historicalAccuracy' => $historicalAccuracy,
                'projectedCaseVolume' => $projectedCases,
                'projectedCaseTrend' => $caseTrend,
                'bottleneckRisk' => $riskLabel,
                'bottleneckRiskLevel' => $riskLevel,
                'targetUtilization' => self::TARGET_UTILIZATION,
                'horizonMonths' => $horizon,
                'timeframe' => $timeframe,
            ],
            'series' => $series,
            'factors' => $this->contributingFactors(),
            'hasData' => true,
        ];
    }

    // -----------------------------------------------------------------------
    // Historical monthly series
    // -----------------------------------------------------------------------

    /** @return list<array{ym:string,label:string,util:float,cases:int}> */
    private function monthlyHistory(): array
    {
        $rows = DB::select(
            'SELECT to_char(d.surgery_date, \'YYYY-MM\') AS ym,
                    AVG(LEAST(100.0, 100.0 * d.pt / ?::numeric)) AS util
             FROM (
                 SELECT oc.room_id, oc.surgery_date,
                        SUM(COALESCE(cm.prime_time_minutes, 0)) AS pt
                 FROM prod.or_cases oc
                 JOIN prod.case_metrics cm ON cm.case_id = oc.case_id
                 WHERE oc.is_deleted = false
                   AND cm.is_deleted = false
                 GROUP BY oc.room_id, oc.surgery_date
             ) d
             GROUP BY to_char(d.surgery_date, \'YYYY-MM\')
             ORDER BY to_char(d.surgery_date, \'YYYY-MM\')',
            [self::STAFFED_PRIME_MIN]
        );

        $caseRows = DB::select(
            'SELECT to_char(oc.surgery_date, \'YYYY-MM\') AS ym, COUNT(*) AS cases
             FROM prod.or_cases oc
             WHERE oc.is_deleted = false
             GROUP BY to_char(oc.surgery_date, \'YYYY-MM\')'
        );
        $caseByMonth = [];
        foreach ($caseRows as $cr) {
            $caseByMonth[(string) $cr->ym] = (int) $cr->cases;
        }

        $history = [];
        foreach ($rows as $r) {
            $ym = (string) $r->ym;
            $history[] = [
                'ym' => $ym,
                'label' => Carbon::createFromFormat('Y-m', $ym)->format('M Y'),
                'util' => round((float) ($r->util ?? 0), 1),
                'cases' => $caseByMonth[$ym] ?? 0,
            ];
        }

        return $history;
    }

    // -----------------------------------------------------------------------
    // Linear trend (ordinary least squares) + diagnostics
    // -----------------------------------------------------------------------

    /**
     * @param  list<float>  $y
     * @return array{slope:float,intercept:float,n:int}
     */
    private function linearFit(array $y): array
    {
        $n = count($y);
        if ($n === 0) {
            return ['slope' => 0.0, 'intercept' => 0.0, 'n' => 0];
        }
        if ($n === 1) {
            return ['slope' => 0.0, 'intercept' => $y[0], 'n' => 1];
        }

        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumXX = 0.0;
        foreach ($y as $i => $val) {
            $sumX += $i;
            $sumY += $val;
            $sumXY += $i * $val;
            $sumXX += $i * $i;
        }
        $denom = ($n * $sumXX) - ($sumX * $sumX);
        $slope = $denom != 0.0 ? (($n * $sumXY) - ($sumX * $sumY)) / $denom : 0.0;
        $intercept = ($sumY - $slope * $sumX) / $n;

        return ['slope' => $slope, 'intercept' => $intercept, 'n' => $n];
    }

    /** @param  array{slope:float,intercept:float,n:int}  $fit */
    private function predict(array $fit, int $x): float
    {
        return $fit['slope'] * $x + $fit['intercept'];
    }

    /**
     * @param  list<float>  $y
     * @param  array{slope:float,intercept:float,n:int}  $fit
     */
    private function residualStd(array $y, array $fit): float
    {
        $n = count($y);
        if ($n < 2) {
            return 2.0;
        }
        $ss = 0.0;
        foreach ($y as $i => $val) {
            $resid = $val - $this->predict($fit, $i);
            $ss += $resid * $resid;
        }
        $df = max(1, $n - 2);

        return sqrt($ss / $df);
    }

    /**
     * @param  list<array{ym:string,label:string,util:float,cases:int}>  $history
     * @param  array{slope:float,intercept:float,n:int}  $utilFit
     * @param  array{slope:float,intercept:float,n:int}  $caseFit
     * @return list<array{label:string,actual:?float,forecast:?float,lower:?float,upper:?float,projectedCases:?int,type:string}>
     */
    private function assembleSeries(array $history, array $utilFit, array $caseFit, int $horizon, float $band): array
    {
        $series = [];
        $n = count($history);

        foreach ($history as $h) {
            $series[] = [
                'label' => $h['label'],
                'actual' => $h['util'],
                'forecast' => null,
                'lower' => null,
                'upper' => null,
                'projectedCases' => null,
                'type' => 'historical',
            ];
        }

        // Bridge the last actual point into the forecast line/band so the dashed
        // forecast and shaded interval visually connect to the solid history.
        if ($n > 0) {
            $lastActual = $series[$n - 1]['actual'];
            $series[$n - 1]['forecast'] = $lastActual;
            $series[$n - 1]['lower'] = $lastActual;
            $series[$n - 1]['upper'] = $lastActual;
        }

        $lastYm = $n > 0 ? $history[$n - 1]['ym'] : Carbon::today()->format('Y-m');
        $cursor = Carbon::createFromFormat('Y-m', $lastYm)->startOfMonth();

        for ($k = 1; $k <= $horizon; $k++) {
            $x = $n - 1 + $k;
            $util = round(max(0.0, min(100.0, $this->predict($utilFit, $x))), 1);
            $cases = max(0, (int) round($this->predict($caseFit, $x)));
            $cursor = $cursor->copy()->addMonthNoOverflow();

            $series[] = [
                'label' => $cursor->format('M Y'),
                'actual' => null,
                'forecast' => $util,
                'lower' => round(max(0.0, $util - $band), 1),
                'upper' => round(min(100.0, $util + $band), 1),
                'projectedCases' => $cases,
                'type' => 'forecast',
            ];
        }

        return $series;
    }

    // -----------------------------------------------------------------------
    // Headline metric derivations
    // -----------------------------------------------------------------------

    /**
     * Confidence (%) blends goodness-of-fit (R²) with series stability
     * (1 − coefficient of variation of residuals), clamped to a sane band.
     *
     * @param  list<float>  $y
     * @param  array{slope:float,intercept:float,n:int}  $fit
     */
    private function confidencePct(array $y, array $fit, float $residualStd): int
    {
        $n = count($y);
        if ($n < 2) {
            return 70;
        }

        $mean = array_sum($y) / $n;
        $ssTot = 0.0;
        $ssRes = 0.0;
        foreach ($y as $i => $val) {
            $ssTot += ($val - $mean) ** 2;
            $ssRes += ($val - $this->predict($fit, $i)) ** 2;
        }
        $r2 = $ssTot > 0 ? max(0.0, 1.0 - $ssRes / $ssTot) : 0.0;
        $stability = $mean > 0 ? max(0.0, 1.0 - ($residualStd / $mean)) : 0.0;

        $confidence = 0.45 * $r2 + 0.55 * $stability;

        return (int) round(max(55, min(96, 55 + $confidence * 41)));
    }

    /**
     * Historical accuracy (%) = 1 − mean absolute percentage error of the fit
     * against the observed monthly utilization, clamped to a sane band.
     *
     * @param  list<float>  $y
     * @param  array{slope:float,intercept:float,n:int}  $fit
     */
    private function historicalAccuracyPct(array $y, array $fit): int
    {
        $n = count($y);
        if ($n < 2) {
            return 80;
        }
        $sumPct = 0.0;
        $count = 0;
        foreach ($y as $i => $val) {
            if ($val <= 0) {
                continue;
            }
            $sumPct += abs($val - $this->predict($fit, $i)) / $val;
            $count++;
        }
        if ($count === 0) {
            return 80;
        }
        $mape = $sumPct / $count;

        return (int) round(max(60, min(98, (1.0 - $mape) * 100)));
    }

    /**
     * @return array{0:string,1:string} [label, statusLevel]
     */
    private function bottleneckRisk(float $predictedUtil, float $slope): array
    {
        if ($predictedUtil >= 90.0 || ($predictedUtil >= 85.0 && $slope > 0)) {
            return ['High', 'critical'];
        }
        if ($predictedUtil >= 82.0 || ($predictedUtil >= 78.0 && $slope > 0.5)) {
            return ['Elevated', 'warning'];
        }
        if ($predictedUtil >= 70.0) {
            return ['Moderate', 'info'];
        }

        return ['Low', 'success'];
    }

    // -----------------------------------------------------------------------
    // Contributing factors (relative impact shares)
    // -----------------------------------------------------------------------

    /** @return list<array{name:string,impact:int}> */
    private function contributingFactors(): array
    {
        $row = DB::selectOne(
            'SELECT
                AVG(cm.turnover_time) AS avg_turnover,
                AVG(cm.late_start_minutes) AS avg_late,
                AVG(cm.early_finish_minutes) AS avg_early,
                AVG(cm.prime_time_minutes) AS avg_prime,
                COUNT(*) AS total,
                SUM(CASE WHEN cs.code = \'CANC\' THEN 1 ELSE 0 END) AS cancellations
             FROM prod.or_cases oc
             JOIN prod.case_metrics cm ON cm.case_id = oc.case_id
             JOIN prod.case_statuses cs ON cs.status_id = oc.status_id
             WHERE oc.is_deleted = false
               AND cm.is_deleted = false'
        );

        if ($row === null || (int) ($row->total ?? 0) === 0) {
            return [
                ['name' => 'Case Volume', 'impact' => 32],
                ['name' => 'Turnover Time', 'impact' => 24],
                ['name' => 'Late Starts', 'impact' => 18],
                ['name' => 'Early Finishes', 'impact' => 14],
                ['name' => 'Cancellations', 'impact' => 12],
            ];
        }

        $turnover = (float) ($row->avg_turnover ?? 0);
        $late = (float) ($row->avg_late ?? 0);
        $early = (float) ($row->avg_early ?? 0);
        $prime = (float) ($row->avg_prime ?? 0);
        $total = max(1, (int) $row->total);
        $cancelRate = 100.0 * (int) ($row->cancellations ?? 0) / $total;

        // Normalize each driver against a reference magnitude so no single
        // raw-minute metric dominates the relative-impact bars. Each weight is
        // the metric expressed as a fraction of a plausible ceiling, so the
        // chart shows comparable contribution shares rather than raw units.
        $raw = [
            'Case Volume' => max(0.05, $prime / 180.0),
            'Turnover Time' => max(0.05, $turnover / 45.0),
            'Late Starts' => max(0.05, $late / 30.0),
            'Early Finishes' => max(0.05, $early / 30.0),
            'Cancellations' => max(0.05, $cancelRate / 10.0),
        ];
        $sum = array_sum($raw);

        $factors = [];
        foreach ($raw as $name => $value) {
            $factors[] = [
                'name' => $name,
                'impact' => (int) round(100.0 * $value / $sum),
            ];
        }

        usort($factors, static fn (array $a, array $b): int => $b['impact'] <=> $a['impact']);

        return $factors;
    }

    // -----------------------------------------------------------------------
    // Empty / no-data fallback
    // -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function emptyPayload(string $timeframe, int $horizon): array
    {
        return [
            'metrics' => [
                'predictedUtilization' => 0.0,
                'predictedUtilizationTrend' => 0.0,
                'currentUtilization' => 0.0,
                'confidence' => 0,
                'predictionRange' => 0.0,
                'historicalAccuracy' => 0,
                'projectedCaseVolume' => 0,
                'projectedCaseTrend' => 0.0,
                'bottleneckRisk' => 'Unknown',
                'bottleneckRiskLevel' => 'info',
                'targetUtilization' => self::TARGET_UTILIZATION,
                'horizonMonths' => $horizon,
                'timeframe' => $timeframe,
            ],
            'series' => [],
            'factors' => [],
            'hasData' => false,
        ];
    }
}
