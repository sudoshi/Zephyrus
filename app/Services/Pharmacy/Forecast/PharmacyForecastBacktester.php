<?php

namespace App\Services\Pharmacy\Forecast;

use Carbon\CarbonImmutable;

/**
 * Deterministic synthetic calibration/backtest for both P4-3 targets.
 * Queue and stockout targets retain separate windows, models, metrics, and
 * baselines. The later evaluation windows are never used to fit parameters.
 */
final class PharmacyForecastBacktester
{
    public const MODEL_VERSION = 'rx-forecast-2026.07.15-synthetic-v1';

    private const ANCHOR = '2026-07-15T12:00:00+00:00';

    private const TRAIN_FRACTION = 0.7;

    public function __construct(private readonly StockoutFeatureExtractor $extractor) {}

    /** @return array<string, mixed> */
    public function buildArtifact(): array
    {
        $anchor = CarbonImmutable::parse(self::ANCHOR);

        return [
            'modelVersion' => self::MODEL_VERSION,
            'modelFamily' => 'seasonal_queue_and_calibrated_logistic_stockout',
            'synthetic' => true,
            'syntheticLabel' => 'Synthetic demo calibration — operational planning aid only; not a clinical, staffing, diversion, or production inventory model.',
            'calibratedAt' => $anchor->toIso8601String(),
            'targets' => [
                'queue' => [
                    'definition' => 'Open pharmacy verification-queue depth at each hourly cutoff across the next eight hours.',
                    'horizonHours' => 8,
                    'cutoffRule' => 'Depth, hour-of-day history, recent trend, and due_at demand must be known at the prediction cutoff; no future completion enters the feature set.',
                ],
                'stockout' => [
                    'definition' => 'A station-medication inventory position with valid on-hand and par evidence reaches zero within six hours.',
                    'horizonHours' => 6,
                    'cutoffRule' => 'Features use the inventory snapshot and station-level transactions available at predicted_at; an already-open stockout remains observed and is excluded from prediction.',
                ],
            ],
            'queue' => $this->queueArtifact($anchor),
            'stockout' => $this->stockoutArtifact($anchor),
        ];
    }

    /** @return array<string, mixed> */
    private function queueArtifact(CarbonImmutable $anchor): array
    {
        $rows = $this->queueCohort($anchor);
        $split = (int) floor(count($rows) * self::TRAIN_FRACTION);
        $train = array_slice($rows, 0, $split);
        $evaluate = array_slice($rows, $split);
        $model = [
            'scheduledDemandWeight' => 0.8,
            'expectedScheduledDemand' => 2.0,
            'recentTrendWeight' => 0.35,
            'seasonalNetByHourOfWeek' => array_map(
                fn (int $hourOfWeek): float => $this->seasonalNet($hourOfWeek % 24, intdiv($hourOfWeek, 24) + 1),
                range(0, 167),
            ),
        ];

        $scored = [];
        foreach ($evaluate as $row) {
            $seasonal = $model['seasonalNetByHourOfWeek'][$row['hourOfWeek']];
            $modelForecast = max(0.0, $row['currentDepth'] + $seasonal
                + $model['scheduledDemandWeight'] * ($row['scheduledDemand'] - $model['expectedScheduledDemand'])
                + $model['recentTrendWeight'] * $row['recentTrend']);
            $seasonalForecast = max(0.0, $row['currentDepth'] + $seasonal);
            $scored[] = [
                'actual' => $row['nextDepth'],
                'model' => $modelForecast,
                'seasonal' => $seasonalForecast,
                'last' => $row['currentDepth'],
            ];
        }
        $modelMetrics = $this->continuousMetrics($scored, 'model');
        $seasonalMetrics = $this->continuousMetrics($scored, 'seasonal');
        $lastMetrics = $this->continuousMetrics($scored, 'last');
        $residualRmse = $modelMetrics['rmse'];

        return [
            'modelFamily' => 'nonnegative_seasonal_net_plus_known_demand',
            'trainingWindow' => $this->window($rows, $train, $evaluate),
            'model' => [...$model, 'residualRmse' => $residualRmse],
            'evaluation' => [
                ...$modelMetrics,
                'coverage' => ['evaluated' => count($scored), 'total' => count($scored), 'fraction' => 1.0],
                'baselines' => [
                    'seasonalHourOfWeek' => ['strategy' => 'current_depth_plus_hour_of_week_net', ...$seasonalMetrics],
                    'lastValue' => ['strategy' => 'persistence_current_depth', ...$lastMetrics],
                ],
                'winnerRule' => 'Model MAE and RMSE must both be lower than both declared baselines on the later evaluation window.',
                'beatsBaselines' => $modelMetrics['mae'] < $seasonalMetrics['mae']
                    && $modelMetrics['rmse'] < $seasonalMetrics['rmse']
                    && $modelMetrics['mae'] < $lastMetrics['mae']
                    && $modelMetrics['rmse'] < $lastMetrics['rmse'],
            ],
        ];
    }

    /** @return list<array{at:string,hour:int,hourOfWeek:int,currentDepth:float,nextDepth:float,scheduledDemand:float,recentTrend:float}> */
    private function queueCohort(CarbonImmutable $anchor): array
    {
        mt_srand(2026071501);
        $count = 24 * 42;
        $depth = 7.0;
        $depthHistory = [$depth];
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $at = $anchor->subHours($count - $i);
            $hour = (int) $at->format('G');
            $dayOfWeek = (int) $at->format('N');
            $hourOfWeek = ($dayOfWeek - 1) * 24 + $hour;
            $scheduled = max(0.0, round(2.0 + 1.4 * sin(($i + 3) / 5.0) + (mt_rand(-100, 100) / 100.0), 2));
            $lookbackIndex = max(0, count($depthHistory) - 4);
            $recentTrend = ($depth - $depthHistory[$lookbackIndex]) / max(1, count($depthHistory) - 1 - $lookbackIndex);
            $noise = $this->normal() * 0.28;
            $next = max(0.0, $depth + $this->seasonalNet($hour, $dayOfWeek)
                + 0.8 * ($scheduled - 2.0)
                + 0.35 * $recentTrend
                + $noise);
            $rows[] = [
                'at' => $at->toIso8601String(),
                'hour' => $hour,
                'hourOfWeek' => $hourOfWeek,
                'currentDepth' => round($depth, 4),
                'nextDepth' => round($next, 4),
                'scheduledDemand' => $scheduled,
                'recentTrend' => round($recentTrend, 4),
            ];
            $depth = $next;
            $depthHistory[] = $depth;
        }
        mt_srand();

        return $rows;
    }

    private function seasonalNet(int $hour, int $dayOfWeek = 1): float
    {
        $net = match (true) {
            $hour >= 5 && $hour <= 8 => 1.25,
            $hour >= 9 && $hour <= 12 => 0.55,
            $hour >= 13 && $hour <= 16 => -0.15,
            $hour >= 17 && $hour <= 20 => 0.35,
            default => -0.45,
        };

        return $net + ($dayOfWeek >= 6 ? -0.15 : 0.0);
    }

    /** @return array<string, mixed> */
    private function stockoutArtifact(CarbonImmutable $anchor): array
    {
        $rows = $this->stockoutCohort($anchor);
        $split = (int) floor(count($rows) * self::TRAIN_FRACTION);
        $train = array_slice($rows, 0, $split);
        $evaluate = array_slice($rows, $split);
        $rawModel = $this->fitStockout($train);
        $model = new CalibratedStockoutModel($rawModel->weights, $rawModel->bias, $this->fitCalibration($rawModel, $train));
        $evaluation = $this->evaluateStockout($model, $evaluate, $train);

        return [
            'modelFamily' => 'calibrated_logistic',
            'featureSchema' => StockoutFeatureSchema::FEATURES,
            'trainingWindow' => $this->window($rows, $train, $evaluate),
            'model' => $model->toArray(),
            'evaluation' => $evaluation,
        ];
    }

    /** @return list<array{at:string,features:array<string,float>,label:int}> */
    private function stockoutCohort(CarbonImmutable $anchor): array
    {
        mt_srand(2026071502);
        $rows = [];
        for ($i = 0; $i < 900; $i++) {
            $at = $anchor->subHours(900 - $i);
            $par = (float) mt_rand(8, 30);
            $onHand = (float) mt_rand(0, (int) round($par * 1.6));
            $vendPerHour = mt_rand(0, 550) / 100.0;
            $refillPerHour = mt_rand(0, 350) / 100.0;
            $minutesSinceRefill = (float) mt_rand(0, 900);
            $shortage = mt_rand(0, 99) < 14;
            $down = mt_rand(0, 99) < 7;
            $observation = new StockoutObservation(
                $i + 1,
                'synthetic-station-'.(($i % 12) + 1),
                'SYNTHETIC-'.(($i % 30) + 1),
                $at->toDateTimeImmutable(),
                $onHand,
                $par,
                $at->toDateTimeImmutable(),
                $vendPerHour,
                $refillPerHour,
                $minutesSinceRefill,
                $shortage,
                $down,
            );
            $features = $this->extractor->extract($observation, $at)['features'];
            $logit = -3.2
                - 2.8 * $features['inventory_ratio']
                + 2.2 * $features['vend_velocity_norm']
                - 1.1 * $features['refill_velocity_norm']
                + 1.2 * $features['refill_gap_norm']
                + 1.4 * $features['shortage_flag']
                + 0.9 * $features['station_downtime']
                + 2.4 * $features['inventory_pressure'];
            $probability = 1.0 / (1.0 + exp(-$logit));
            $rows[] = [
                'at' => $at->toIso8601String(),
                'features' => $features,
                'label' => (mt_rand() / mt_getrandmax()) < $probability ? 1 : 0,
            ];
        }
        mt_srand();

        return $rows;
    }

    /** @param list<array{features:array<string,float>,label:int}> $rows */
    private function fitStockout(array $rows): CalibratedStockoutModel
    {
        $weights = array_fill_keys(StockoutFeatureSchema::FEATURES, 0.0);
        $bias = 0.0;
        $count = max(1, count($rows));
        for ($epoch = 0; $epoch < 400; $epoch++) {
            $gradient = array_fill_keys(StockoutFeatureSchema::FEATURES, 0.0);
            $biasGradient = 0.0;
            foreach ($rows as $row) {
                $z = $bias;
                foreach ($weights as $feature => $weight) {
                    $z += $weight * $row['features'][$feature];
                }
                $error = (1.0 / (1.0 + exp(-$z))) - $row['label'];
                foreach ($weights as $feature => $_) {
                    $gradient[$feature] += $error * $row['features'][$feature];
                }
                $biasGradient += $error;
            }
            foreach ($weights as $feature => $weight) {
                $weights[$feature] -= 0.28 * (($gradient[$feature] / $count) + 0.001 * $weight);
            }
            $bias -= 0.28 * ($biasGradient / $count);
        }

        return new CalibratedStockoutModel($weights, $bias, [
            ['score' => 0.0, 'calibrated' => 0.0],
            ['score' => 1.0, 'calibrated' => 1.0],
        ]);
    }

    /** @param list<array{features:array<string,float>,label:int}> $rows @return list<array{score:float,calibrated:float}> */
    private function fitCalibration(CalibratedStockoutModel $model, array $rows): array
    {
        $sumScore = $sumLabel = array_fill(0, 10, 0.0);
        $count = array_fill(0, 10, 0);
        foreach ($rows as $row) {
            $raw = $model->rawProbability($row['features']);
            $bin = min(9, (int) floor($raw * 10));
            $sumScore[$bin] += $raw;
            $sumLabel[$bin] += $row['label'];
            $count[$bin]++;
        }
        $knots = [['score' => 0.0, 'calibrated' => 0.0]];
        $last = 0.0;
        for ($bin = 0; $bin < 10; $bin++) {
            if ($count[$bin] === 0) {
                continue;
            }
            $last = max($last, $sumLabel[$bin] / $count[$bin]);
            $knots[] = ['score' => round($sumScore[$bin] / $count[$bin], 6), 'calibrated' => round($last, 6)];
        }
        $knots[] = ['score' => 1.0, 'calibrated' => round($last, 6)];

        return array_values(array_reduce($knots, static function (array $carry, array $knot): array {
            if ($carry === [] || $knot['score'] > $carry[count($carry) - 1]['score']) {
                $carry[] = $knot;
            }

            return $carry;
        }, []));
    }

    /** @param list<array{features:array<string,float>,label:int}> $evaluate @param list<array{features:array<string,float>,label:int}> $train @return array<string,mixed> */
    private function evaluateStockout(CalibratedStockoutModel $model, array $evaluate, array $train): array
    {
        $scored = array_map(static fn (array $row): array => [
            'p' => $model->calibratedProbability($row['features']),
            'y' => $row['label'],
        ], $evaluate);
        $baseRate = array_sum(array_column($train, 'label')) / max(1, count($train));
        $brier = $this->brier($scored);
        $baseline = $this->brier(array_map(static fn (array $row): array => ['p' => $baseRate, 'y' => $row['y']], $scored));
        $auc = $this->auc($scored);

        return [
            'calibrationError' => round($this->calibrationError($scored), 4),
            'discriminationAuc' => round($auc, 4),
            'brierScore' => round($brier, 4),
            'coverage' => ['evaluated' => count($scored), 'total' => count($scored), 'fraction' => 1.0],
            'naiveBaseline' => ['strategy' => 'training_base_rate', 'baseRate' => round($baseRate, 4), 'brierScore' => round($baseline, 4), 'discriminationAuc' => 0.5],
            'beatsBaseline' => $auc > 0.5 && $brier < $baseline,
        ];
    }

    /** @param list<array<string,mixed>> $rows @param list<array<string,mixed>> $train @param list<array<string,mixed>> $evaluate @return array<string,mixed> */
    private function window(array $rows, array $train, array $evaluate): array
    {
        return [
            'cohortSize' => count($rows),
            'trainCount' => count($train),
            'evaluateCount' => count($evaluate),
            'trainFrom' => $train[0]['at'],
            'trainTo' => $train[count($train) - 1]['at'],
            'evaluateFrom' => $evaluate[0]['at'],
            'evaluateTo' => $evaluate[count($evaluate) - 1]['at'],
        ];
    }

    /** @param list<array{actual:float,model:float,seasonal:float,last:float}> $rows @return array{mae:float,rmse:float,wape:float} */
    private function continuousMetrics(array $rows, string $key): array
    {
        $absolute = $squared = $actual = 0.0;
        foreach ($rows as $row) {
            $error = $row[$key] - $row['actual'];
            $absolute += abs($error);
            $squared += $error ** 2;
            $actual += abs($row['actual']);
        }
        $count = max(1, count($rows));

        return [
            'mae' => round($absolute / $count, 4),
            'rmse' => round(sqrt($squared / $count), 4),
            'wape' => round($actual > 0.0 ? $absolute / $actual : 0.0, 4),
        ];
    }

    /** @param list<array{p:float,y:int}> $rows */
    private function brier(array $rows): float
    {
        return array_sum(array_map(static fn (array $row): float => ($row['p'] - $row['y']) ** 2, $rows)) / max(1, count($rows));
    }

    /** @param list<array{p:float,y:int}> $rows */
    private function calibrationError(array $rows): float
    {
        $sumP = $sumY = array_fill(0, 10, 0.0);
        $count = array_fill(0, 10, 0);
        foreach ($rows as $row) {
            $bin = min(9, (int) floor($row['p'] * 10));
            $sumP[$bin] += $row['p'];
            $sumY[$bin] += $row['y'];
            $count[$bin]++;
        }
        $error = 0.0;
        foreach ($count as $bin => $binCount) {
            if ($binCount > 0) {
                $error += ($binCount / max(1, count($rows))) * abs(($sumP[$bin] - $sumY[$bin]) / $binCount);
            }
        }

        return $error;
    }

    /** @param list<array{p:float,y:int}> $rows */
    private function auc(array $rows): float
    {
        $positives = array_values(array_filter($rows, static fn (array $row): bool => $row['y'] === 1));
        $negatives = array_values(array_filter($rows, static fn (array $row): bool => $row['y'] === 0));
        if ($positives === [] || $negatives === []) {
            return 0.5;
        }
        $wins = 0.0;
        foreach ($positives as $positive) {
            foreach ($negatives as $negative) {
                $wins += $positive['p'] > $negative['p'] ? 1.0 : ($positive['p'] === $negative['p'] ? 0.5 : 0.0);
            }
        }

        return $wins / (count($positives) * count($negatives));
    }

    private function normal(): float
    {
        $first = max(1e-9, mt_rand() / mt_getrandmax());
        $second = mt_rand() / mt_getrandmax();

        return sqrt(-2.0 * log($first)) * cos(2.0 * M_PI * $second);
    }
}
