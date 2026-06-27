<?php

namespace App\Services\Predictions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Derives a surgical case-volume DEMAND forecast from the seeded ~6-month
 * history in the live `prod` schema, for the Predictions › Demand Analysis page
 * (resources/js/Pages/Predictions/DemandAnalysis.jsx).
 *
 * What it produces:
 *   - A historical monthly case-volume series (DATE_TRUNC month over
 *     prod.or_cases) PLUS a forward projection (ordinary-least-squares linear
 *     trend) for the next {@see self::HORIZON_MONTHS} months, each with a
 *     confidence band (forecast ± 1.96·residual-stderr).
 *   - Headline metrics: projected next-month demand, growth %, a seasonality
 *     label/score (coefficient of variation of monthly volume), and an
 *     in-sample model-accuracy estimate (100 − MAPE).
 *   - A by-service demand mix (last full months) with each service's own
 *     short-horizon projection.
 *
 * Projection method — linear trend (OLS):
 *   Fit y = a + b·x over the FULL-month-equivalent series. The first calendar
 *   month in the window is usually a 1-2 day stub (seed boundary) and the last
 *   month is partial-to-date; the stub is dropped and the trailing partial
 *   month is scaled up to a full-month equivalent by its day-fraction so the
 *   slope reflects real demand, not a calendar artifact. Forecasts are clamped
 *   to a sane band around the historical mean to prevent runaway extrapolation
 *   off a short series.
 *
 * Design notes:
 *   - Deterministic and safe on empty tables (returns plausible zeros + an
 *     empty series, never throws). The frontend renders an explicit empty
 *     state when `series` is empty.
 *   - All queries respect soft deletes (is_deleted = false).
 *   - Pure SQL aggregation + PHP arithmetic; no randomness.
 *
 * Sources: prod.or_cases, prod.services.
 */
class DemandAnalysisService
{
    /** Forward projection horizon (months). */
    private const HORIZON_MONTHS = 3;

    /** Minimum full months required to fit a trend; below this we hold flat. */
    private const MIN_FIT_POINTS = 3;

    /** Forecast clamp band as a fraction of historical mean (±). */
    private const CLAMP_BAND = 0.6;

    /** Confidence-interval z-score (~95%). */
    private const CI_Z = 1.96;

    /** Floor for the half-band as a fraction of the forecast value. */
    private const BAND_FLOOR_FRAC = 0.08;

    /**
     * Canonical service display order (matches the rest of the OR analytics
     * surfaces). Absent services simply do not appear.
     *
     * @var list<string>
     */
    private const SERVICE_ORDER = [
        'Neurosurgery',
        'Orthopedics',
        'Cardiology',
        'General Surgery',
    ];

    /**
     * Build the full Demand Analysis payload for the page.
     *
     * @return array{
     *     metrics: array<string,mixed>,
     *     series: list<array<string,mixed>>,
     *     byService: list<array<string,mixed>>,
     *     hasData: bool,
     *     projectionMethod: string
     * }
     */
    public function build(): array
    {
        $months = $this->monthlyVolume();

        if (count($months) === 0) {
            return $this->emptyPayload();
        }

        // Full-month-equivalent points used for the FIT (drop the leading stub,
        // annualize the trailing partial month). Keep the raw months for the
        // displayed historical series.
        $fitPoints = $this->fitPoints($months);

        $trend = $this->fitTrend($fitPoints);

        $series = $this->buildSeries($months, $fitPoints, $trend);
        $byService = $this->byServiceDemand();
        $metrics = $this->buildMetrics($months, $fitPoints, $trend, $byService);

        return [
            'metrics' => $metrics,
            'series' => $series,
            'byService' => $byService,
            'hasData' => true,
            'projectionMethod' => 'Linear least-squares trend with 95% confidence band (full-month-equivalent fit)',
        ];
    }

    // -----------------------------------------------------------------------
    // Raw monthly volume
    // -----------------------------------------------------------------------

    /**
     * Monthly case volume across the available window, oldest → newest.
     *
     * @return list<array{key:string,date:Carbon,label:string,total:int,partial:bool,dayFraction:float}>
     */
    private function monthlyVolume(): array
    {
        $rows = DB::select(
            "SELECT date_trunc('month', surgery_date)::date AS m,
                    COUNT(*) AS total,
                    MIN(surgery_date) AS first_d,
                    MAX(surgery_date) AS last_d
             FROM prod.or_cases
             WHERE is_deleted = false
             GROUP BY date_trunc('month', surgery_date)
             ORDER BY date_trunc('month', surgery_date)"
        );

        if (count($rows) === 0) {
            return [];
        }

        // The newest month is "to-date": its day-fraction is (max observed day /
        // days in that month). All earlier months are treated as complete.
        $lastKey = (string) end($rows)->m;

        $out = [];
        foreach ($rows as $r) {
            $date = Carbon::parse($r->m)->startOfMonth();
            $daysInMonth = (int) $date->daysInMonth;
            $isLast = ((string) $r->m) === $lastKey;

            if ($isLast) {
                $observedDay = (int) Carbon::parse($r->last_d)->day;
                $dayFraction = $daysInMonth > 0 ? min(1.0, max(0.05, $observedDay / $daysInMonth)) : 1.0;
            } else {
                $dayFraction = 1.0;
            }

            // A leading stub (<= 3 observed days in its month) is flagged partial
            // too so the fit can drop it.
            $firstDay = (int) Carbon::parse($r->first_d)->day;
            $isLeadStub = ($r === $rows[0]) && ($daysInMonth - $firstDay + 1) <= 3;

            $out[] = [
                'key' => $date->format('Y-m'),
                'date' => $date,
                'label' => $date->format('M Y'),
                'total' => (int) $r->total,
                'partial' => $isLast || $isLeadStub,
                'dayFraction' => $isLeadStub
                    ? max(0.05, ($daysInMonth - $firstDay + 1) / max(1, $daysInMonth))
                    : $dayFraction,
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Fit points (full-month-equivalent)
    // -----------------------------------------------------------------------

    /**
     * Produce the (x, y) points used to fit the trend. x is a 1-based month
     * index; y is the full-month-equivalent volume.
     *
     * - Drop a leading stub month (a 1-2 day boundary fragment): it carries no
     *   real signal and its day-fraction scaling would explode.
     * - Scale the trailing partial month up to a full-month equivalent.
     *
     * @param  list<array{key:string,date:Carbon,label:string,total:int,partial:bool,dayFraction:float}>  $months
     * @return list<array{x:int,y:float,raw:int,key:string}>
     */
    private function fitPoints(array $months): array
    {
        if (count($months) === 0) {
            return [];
        }

        // Identify a leading stub: first month flagged partial AND not the only
        // month AND covers <= ~10% of its calendar month.
        $work = $months;
        $first = $work[0];
        if (count($work) > 1 && $first['partial'] && $first['dayFraction'] <= 0.15) {
            array_shift($work);
        }

        $points = [];
        $x = 1;
        foreach ($work as $m) {
            // Annualize the trailing partial month; everything else is as-is.
            $equiv = $m['partial'] && $m['dayFraction'] < 0.95
                ? round($m['total'] / max(0.05, $m['dayFraction']))
                : (float) $m['total'];

            $points[] = [
                'x' => $x,
                'y' => $equiv,
                'raw' => $m['total'],
                'key' => $m['key'],
            ];
            $x++;
        }

        return $points;
    }

    // -----------------------------------------------------------------------
    // OLS trend fit
    // -----------------------------------------------------------------------

    /**
     * Ordinary-least-squares fit y = intercept + slope·x over the fit points.
     * Returns slope/intercept, the residual standard error, and the in-sample
     * MAPE used for the model-accuracy headline.
     *
     * @param  list<array{x:int,y:float,raw:int,key:string}>  $points
     * @return array{slope:float,intercept:float,mean:float,residualStdErr:float,mape:float,n:int}
     */
    private function fitTrend(array $points): array
    {
        $n = count($points);

        if ($n === 0) {
            return ['slope' => 0.0, 'intercept' => 0.0, 'mean' => 0.0, 'residualStdErr' => 0.0, 'mape' => 0.0, 'n' => 0];
        }

        $sumY = array_sum(array_column($points, 'y'));
        $mean = $sumY / $n;

        if ($n < self::MIN_FIT_POINTS) {
            // Too few points to trust a slope; hold flat at the mean.
            return [
                'slope' => 0.0,
                'intercept' => $mean,
                'mean' => $mean,
                'residualStdErr' => $this->spread($points, $mean),
                'mape' => 0.0,
                'n' => $n,
            ];
        }

        $sumX = 0.0;
        $sumXY = 0.0;
        $sumXX = 0.0;
        foreach ($points as $p) {
            $sumX += $p['x'];
            $sumXY += $p['x'] * $p['y'];
            $sumXX += $p['x'] * $p['x'];
        }

        $denom = ($n * $sumXX) - ($sumX * $sumX);
        $slope = $denom != 0.0 ? (($n * $sumXY) - ($sumX * $sumY)) / $denom : 0.0;
        $intercept = ($sumY - ($slope * $sumX)) / $n;

        // Residuals → standard error of regression + MAPE.
        $sse = 0.0;
        $ape = 0.0;
        $apeCount = 0;
        foreach ($points as $p) {
            $pred = $intercept + ($slope * $p['x']);
            $sse += ($p['y'] - $pred) ** 2;
            if ($p['y'] > 0.0) {
                $ape += abs(($p['y'] - $pred) / $p['y']);
                $apeCount++;
            }
        }

        $dof = max(1, $n - 2);
        $residualStdErr = sqrt($sse / $dof);
        $mape = $apeCount > 0 ? ($ape / $apeCount) * 100.0 : 0.0;

        return [
            'slope' => $slope,
            'intercept' => $intercept,
            'mean' => $mean,
            'residualStdErr' => $residualStdErr,
            'mape' => $mape,
            'n' => $n,
        ];
    }

    /**
     * Population standard deviation around a mean (used as a band proxy when
     * there are too few points to fit a slope).
     *
     * @param  list<array{x:int,y:float,raw:int,key:string}>  $points
     */
    private function spread(array $points, float $mean): float
    {
        $n = count($points);
        if ($n === 0) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($points as $p) {
            $sum += ($p['y'] - $mean) ** 2;
        }

        return sqrt($sum / $n);
    }

    // -----------------------------------------------------------------------
    // Series (historical actuals + forecast band)
    // -----------------------------------------------------------------------

    /**
     * Combined chart series: historical actuals first, then the forecast band
     * for the next HORIZON_MONTHS. The last actual point also carries the
     * forecast/lower/upper of itself so the two lines join visually.
     *
     * @param  list<array{key:string,date:Carbon,label:string,total:int,partial:bool,dayFraction:float}>  $months
     * @param  list<array{x:int,y:float,raw:int,key:string}>  $fitPoints
     * @param  array{slope:float,intercept:float,mean:float,residualStdErr:float,mape:float,n:int}  $trend
     * @return list<array{label:string,actual:?int,forecast:?int,lower:?int,upper:?int,partial:bool}>
     */
    private function buildSeries(array $months, array $fitPoints, array $trend): array
    {
        $series = [];

        foreach ($months as $m) {
            $series[] = [
                'label' => $m['label'],
                'actual' => $m['total'],
                'forecast' => null,
                'lower' => null,
                'upper' => null,
                'partial' => $m['partial'],
            ];
        }

        if (count($fitPoints) === 0) {
            return $series;
        }

        $lastX = (int) end($fitPoints)['x'];
        $lastMonth = end($months)['date'];

        $halfFloorMean = max(1.0, $trend['mean']);

        // Seed the join: replace the trailing actual point's forecast with its
        // own fitted value so the forecast line starts where actuals end.
        $joinX = $lastX;
        $joinPred = $this->predict($trend, $joinX);
        $lastIdx = count($series) - 1;
        $series[$lastIdx]['forecast'] = (int) round($joinPred);
        $series[$lastIdx]['lower'] = (int) round($joinPred);
        $series[$lastIdx]['upper'] = (int) round($joinPred);

        for ($h = 1; $h <= self::HORIZON_MONTHS; $h++) {
            $x = $lastX + $h;
            $pred = $this->predict($trend, $x);

            // Widen the band with horizon: ±z·stderr·sqrt(h).
            $half = self::CI_Z * max($trend['residualStdErr'], self::BAND_FLOOR_FRAC * max(1.0, $pred)) * sqrt($h);
            $half = max($half, self::BAND_FLOOR_FRAC * $halfFloorMean);

            $month = $lastMonth->copy()->addMonthsNoOverflow($h);

            $series[] = [
                'label' => $month->format('M Y'),
                'actual' => null,
                'forecast' => (int) round($pred),
                'lower' => (int) round(max(0.0, $pred - $half)),
                'upper' => (int) round($pred + $half),
                'partial' => false,
            ];
        }

        return $series;
    }

    /**
     * Predict full-month-equivalent volume at month index x, clamped to a sane
     * band around the historical mean.
     *
     * @param  array{slope:float,intercept:float,mean:float,residualStdErr:float,mape:float,n:int}  $trend
     */
    private function predict(array $trend, int $x): float
    {
        $raw = $trend['intercept'] + ($trend['slope'] * $x);

        $mean = $trend['mean'];
        if ($mean > 0.0) {
            $lo = $mean * (1 - self::CLAMP_BAND);
            $hi = $mean * (1 + self::CLAMP_BAND);
            $raw = min($hi, max($lo, $raw));
        }

        return max(0.0, $raw);
    }

    // -----------------------------------------------------------------------
    // By-service demand mix + per-service projection
    // -----------------------------------------------------------------------

    /**
     * Per-service current vs projected monthly demand. "Current" = the most
     * recent FULL month's volume per service; "projected" extrapolates each
     * service's own short trend (last up-to-4 full months).
     *
     * @return list<array{service:string,current:int,projected:int,growthPct:float,sharePct:float}>
     */
    private function byServiceDemand(): array
    {
        $rows = DB::select(
            "SELECT s.name AS service,
                    date_trunc('month', oc.surgery_date)::date AS m,
                    COUNT(*) AS total
             FROM prod.or_cases oc
             JOIN prod.services s ON s.service_id = oc.case_service_id
             WHERE oc.is_deleted = false
             GROUP BY s.name, date_trunc('month', oc.surgery_date)
             ORDER BY s.name, date_trunc('month', oc.surgery_date)"
        );

        if (count($rows) === 0) {
            return [];
        }

        // Group monthly totals by service, oldest → newest, and identify the
        // global trailing partial month so we can drop it from per-service fits.
        $allMonths = array_values(array_unique(array_map(static fn ($r): string => (string) $r->m, $rows)));
        sort($allMonths);
        $lastMonthKey = end($allMonths);

        $byService = [];
        foreach ($rows as $r) {
            $byService[(string) $r->service][(string) $r->m] = (int) $r->total;
        }

        $result = [];
        $totalProjected = 0;

        foreach ($byService as $service => $monthMap) {
            ksort($monthMap);

            // Full-month series (drop the trailing partial calendar month).
            $fullSeries = [];
            foreach ($monthMap as $monthKey => $total) {
                if ($monthKey === $lastMonthKey) {
                    continue;
                }
                $fullSeries[] = (float) $total;
            }

            if (count($fullSeries) === 0) {
                // Only a partial month exists; fall back to its raw value.
                $current = (int) (end($monthMap) ?: 0);
                $result[] = [
                    'service' => $this->serviceLabel((string) $service),
                    'current' => $current,
                    'projected' => $current,
                    'growthPct' => 0.0,
                    'sharePct' => 0.0,
                ];
                $totalProjected += $current;

                continue;
            }

            $current = (int) end($fullSeries);

            // Use the last up-to-4 full months for a stable short slope.
            $tail = array_slice($fullSeries, -4);
            $projected = (int) round($this->shortProjection($tail));

            $growth = $current > 0 ? round((($projected - $current) / $current) * 100.0, 1) : 0.0;

            $result[] = [
                'service' => $this->serviceLabel((string) $service),
                'current' => $current,
                'projected' => max(0, $projected),
                'growthPct' => $growth,
                'sharePct' => 0.0,
            ];
            $totalProjected += max(0, $projected);
        }

        // Compute share of projected demand.
        foreach ($result as $i => $row) {
            $result[$i]['sharePct'] = $totalProjected > 0
                ? round(($row['projected'] / $totalProjected) * 100.0, 1)
                : 0.0;
        }

        return $this->orderByService($result);
    }

    /**
     * One-step-ahead projection for a short numeric series via OLS slope; holds
     * flat when fewer than 3 points.
     *
     * @param  list<float>  $values
     */
    private function shortProjection(array $values): float
    {
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $mean = array_sum($values) / $n;
        if ($n < self::MIN_FIT_POINTS) {
            return $mean;
        }

        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumXX = 0.0;
        foreach ($values as $i => $v) {
            $x = $i + 1;
            $sumX += $x;
            $sumY += $v;
            $sumXY += $x * $v;
            $sumXX += $x * $x;
        }
        $denom = ($n * $sumXX) - ($sumX * $sumX);
        $slope = $denom != 0.0 ? (($n * $sumXY) - ($sumX * $sumY)) / $denom : 0.0;
        $intercept = ($sumY - ($slope * $sumX)) / $n;

        $next = $intercept + ($slope * ($n + 1));

        // Clamp to ±60% of the series mean to avoid runaway extrapolation.
        $lo = $mean * 0.4;
        $hi = $mean * 1.6;

        return max(0.0, min($hi, max($lo, $next)));
    }

    // -----------------------------------------------------------------------
    // Headline metrics
    // -----------------------------------------------------------------------

    /**
     * @param  list<array{key:string,date:Carbon,label:string,total:int,partial:bool,dayFraction:float}>  $months
     * @param  list<array{x:int,y:float,raw:int,key:string}>  $fitPoints
     * @param  array{slope:float,intercept:float,mean:float,residualStdErr:float,mape:float,n:int}  $trend
     * @param  list<array{service:string,current:int,projected:int,growthPct:float,sharePct:float}>  $byService
     * @return array<string,mixed>
     */
    private function buildMetrics(array $months, array $fitPoints, array $trend, array $byService): array
    {
        $n = count($fitPoints);

        // Next-month projected demand (full-month-equivalent).
        $lastX = $n > 0 ? (int) end($fitPoints)['x'] : 0;
        $projectedVolume = $n > 0 ? (int) round($this->predict($trend, $lastX + 1)) : 0;

        // Trailing 3 full-month average as the "current" baseline.
        $tail = array_slice(array_column($fitPoints, 'y'), -3);
        $trailingAvg = count($tail) > 0 ? array_sum($tail) / count($tail) : 0.0;
        $currentVolume = (int) round($trailingAvg);

        // Growth: next-month forecast vs trailing-3 average.
        $growthRate = $trailingAvg > 0
            ? round((($projectedVolume - $trailingAvg) / $trailingAvg) * 100.0, 1)
            : 0.0;
        $growthRate = max(-50.0, min(50.0, $growthRate));

        // Seasonality from the coefficient of variation of the fit series.
        [$seasonalityLabel, $seasonalityScore] = $this->seasonality($fitPoints, $trend['mean']);

        // Model accuracy = 100 − MAPE, floored.
        $modelAccuracy = $n >= self::MIN_FIT_POINTS
            ? (int) round(max(50.0, min(99.0, 100.0 - $trend['mape'])))
            : 0;

        $peak = $this->peakMonth($months);

        return [
            'projectedVolume' => $projectedVolume,
            'currentVolume' => $currentVolume,
            'projectedTrend' => $projectedVolume >= $currentVolume ? 'up' : 'down',
            'projectedDeltaPct' => $currentVolume > 0
                ? round((($projectedVolume - $currentVolume) / $currentVolume) * 100.0, 1)
                : 0.0,

            'growthRatePct' => $growthRate,
            'growthTrend' => $growthRate >= 0 ? 'up' : 'down',

            'seasonalityLabel' => $seasonalityLabel,
            'seasonalityScore' => $seasonalityScore,
            'peakMonth' => $peak,

            'modelAccuracyPct' => $modelAccuracy,
            'modelAccuracyTrend' => $modelAccuracy >= 85 ? 'up' : 'down',

            'horizonMonths' => self::HORIZON_MONTHS,
            'historyMonths' => count($months),
            'serviceCount' => count($byService),
        ];
    }

    /**
     * Seasonality strength from the coefficient of variation (std/mean) of the
     * full-month-equivalent series. Maps CV → Low/Moderate/High + a 0-10 score.
     *
     * @param  list<array{x:int,y:float,raw:int,key:string}>  $points
     * @return array{0:string,1:float}
     */
    private function seasonality(array $points, float $mean): array
    {
        $n = count($points);
        if ($n < self::MIN_FIT_POINTS || $mean <= 0.0) {
            return ['Low', 0.0];
        }

        $sum = 0.0;
        foreach ($points as $p) {
            $sum += ($p['y'] - $mean) ** 2;
        }
        $std = sqrt($sum / $n);
        $cv = $std / $mean; // coefficient of variation

        $score = round(min(10.0, $cv * 40.0), 1); // 25% CV ≈ score 10

        $label = match (true) {
            $cv >= 0.20 => 'High',
            $cv >= 0.10 => 'Moderate',
            default => 'Low',
        };

        return [$label, $score];
    }

    /**
     * Busiest calendar month label across the (complete) history.
     *
     * @param  list<array{key:string,date:Carbon,label:string,total:int,partial:bool,dayFraction:float}>  $months
     */
    private function peakMonth(array $months): string
    {
        $best = null;
        $bestVal = -1;
        foreach ($months as $m) {
            if ($m['partial']) {
                continue;
            }
            if ($m['total'] > $bestVal) {
                $bestVal = $m['total'];
                $best = $m['date'];
            }
        }

        return $best instanceof Carbon ? $best->format('M') : '—';
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @return array{metrics:array<string,mixed>,series:list<array<string,mixed>>,byService:list<array<string,mixed>>,hasData:bool,projectionMethod:string} */
    private function emptyPayload(): array
    {
        return [
            'metrics' => [
                'projectedVolume' => 0,
                'currentVolume' => 0,
                'projectedTrend' => 'up',
                'projectedDeltaPct' => 0.0,
                'growthRatePct' => 0.0,
                'growthTrend' => 'up',
                'seasonalityLabel' => 'Low',
                'seasonalityScore' => 0.0,
                'peakMonth' => '—',
                'modelAccuracyPct' => 0,
                'modelAccuracyTrend' => 'up',
                'horizonMonths' => self::HORIZON_MONTHS,
                'historyMonths' => 0,
                'serviceCount' => 0,
            ],
            'series' => [],
            'byService' => [],
            'hasData' => false,
            'projectionMethod' => 'Linear least-squares trend with 95% confidence band (full-month-equivalent fit)',
        ];
    }

    /** Normalize a raw service name to the chart's short label vocabulary. */
    private function serviceLabel(string $name): string
    {
        return match ($name) {
            'General Surgery' => 'General',
            'Orthopedics' => 'Ortho',
            default => $name,
        };
    }

    /**
     * Order the by-service rows by the canonical service order, appending any
     * extras at the end.
     *
     * @param  list<array{service:string,current:int,projected:int,growthPct:float,sharePct:float}>  $rows
     * @return list<array{service:string,current:int,projected:int,growthPct:float,sharePct:float}>
     */
    private function orderByService(array $rows): array
    {
        $rank = [];
        foreach (self::SERVICE_ORDER as $i => $name) {
            $rank[$this->serviceLabel($name)] = $i;
        }

        usort($rows, function (array $a, array $b) use ($rank): int {
            $ra = $rank[$a['service']] ?? PHP_INT_MAX;
            $rb = $rank[$b['service']] ?? PHP_INT_MAX;
            if ($ra === $rb) {
                return strcmp($a['service'], $b['service']);
            }

            return $ra <=> $rb;
        });

        return $rows;
    }
}
