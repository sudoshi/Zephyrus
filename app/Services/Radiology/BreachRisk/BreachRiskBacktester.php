<?php

namespace App\Services\Radiology\BreachRisk;

use Carbon\CarbonImmutable;

/**
 * Trains and backtests the Radiology breach-risk model on SYNTHETIC demo
 * history, as a pipeline proof (P4-1).
 *
 * The cohort is a deterministic, seeded synthetic stream of imaging orders whose
 * breach label is drawn from a known operational data-generating process
 * (off-hours, deep same-modality queues, scanner downtime and stat/emergency
 * urgency raise breach probability). It is split by time: the earlier window
 * trains the calibrated logistic model, the later window evaluates it. Feature
 * extraction happens strictly at each order's own ordered-at instant, so the
 * training set can never see an outcome that had not yet occurred — the
 * time-split leakage acceptance is enforced structurally.
 *
 * The produced artifact carries the model, feature schema, both windows, the
 * calibration curve, and the four required metrics (calibration error,
 * discrimination/AUC, coverage, naive-baseline comparison). It is always labelled
 * synthetic.
 */
final class BreachRiskBacktester
{
    public const MODEL_VERSION = 'rad-breach-risk-2026.07.13-synthetic-v1';

    private const COHORT_SIZE = 900;

    private const TRAIN_FRACTION = 0.7;

    /** Deterministic anchor so every regeneration/backtest is byte-identical. */
    private const ANCHOR = '2026-07-13T12:00:00+00:00';

    private const SEED = 20260713;

    public function __construct(private readonly BreachRiskFeatureExtractor $extractor) {}

    /**
     * Run the full train → backtest → artifact pipeline and return the artifact
     * array (the same structure persisted to config/radiology/breach_risk_model.json).
     *
     * @return array<string, mixed>
     */
    public function buildArtifact(): array
    {
        $anchor = CarbonImmutable::parse(self::ANCHOR);
        $cohort = $this->syntheticCohort($anchor);

        $splitIndex = (int) floor(count($cohort) * self::TRAIN_FRACTION);
        $splitAt = $cohort[$splitIndex]['orderedAt'];
        $train = array_slice($cohort, 0, $splitIndex);
        $evaluate = array_slice($cohort, $splitIndex);

        $model = $this->fit($train);
        $calibration = $this->fitCalibration($model, $train);
        $calibrated = new CalibratedLogisticModel($model->weights, $model->bias, $calibration);

        $evaluation = $this->evaluate($calibrated, $evaluate, $train);

        return [
            'modelVersion' => self::MODEL_VERSION,
            'modelFamily' => 'calibrated_logistic',
            'synthetic' => true,
            'syntheticLabel' => 'Synthetic demo calibration — planning aid only, trained on generated demo history, not a clinical model.',
            'target' => [
                'definition' => 'An open imaging order breaches its applicable policy SLA (RAD_ORDERED→RAD_FINAL for STAT, RAD_IMAGES_AVAILABLE→RAD_FINAL for emergency) within the declared prediction horizon.',
                'horizonMinutes' => 60,
                'cutoffRule' => 'Label and features are anchored at ordered_at; no post-order evidence enters the feature vector.',
            ],
            'calibratedAt' => $anchor->toIso8601String(),
            'featureSchema' => BreachRiskFeatureSchema::FEATURES,
            'trainingWindow' => [
                'anchor' => $anchor->toIso8601String(),
                'cohortSize' => count($cohort),
                'trainCount' => count($train),
                'evaluateCount' => count($evaluate),
                'splitAt' => $splitAt,
                'trainFrom' => $cohort[0]['orderedAt'],
                'trainTo' => $train[count($train) - 1]['orderedAt'],
                'evaluateFrom' => $evaluate[0]['orderedAt'],
                'evaluateTo' => $evaluate[count($evaluate) - 1]['orderedAt'],
            ],
            'model' => $calibrated->toArray(),
            'evaluation' => $evaluation,
        ];
    }

    /**
     * Deterministic synthetic imaging-order stream with a known breach process.
     *
     * @return list<array{orderedAt: string, features: array<string, float>, label: int}>
     */
    private function syntheticCohort(CarbonImmutable $anchor): array
    {
        mt_srand(self::SEED);
        $modalities = ['CT', 'MRI', 'US', 'XR', 'NM'];
        $priorities = ['stat', 'urgent', 'routine'];
        $classes = ['emergency', 'inpatient', 'outpatient'];
        $rows = [];

        for ($i = 0; $i < self::COHORT_SIZE; $i++) {
            // Orders are spread backwards over ~30 days at deterministic intervals.
            $orderedAt = $anchor->subMinutes((self::COHORT_SIZE - $i) * 48 + ($i % 7) * 3);
            $modality = $modalities[mt_rand(0, count($modalities) - 1)];
            $priority = $priorities[mt_rand(0, count($priorities) - 1)];
            $class = $classes[mt_rand(0, count($classes) - 1)];
            $hour = (int) $orderedAt->format('G');
            $dow = (int) $orderedAt->format('N');
            $offHours = ($hour < 7 || $hour >= 19 || $dow >= 6);
            $queueDepth = max(0, (int) round($this->normal() * 3 + ($offHours ? 7 : 4)));
            $scannerDown = mt_rand(0, 99) < ($offHours ? 22 : 8);
            $stageAge = max(0, (int) round($this->normal() * 20 + 25));

            $observation = new BreachRiskObservation(
                orderId: $i + 1,
                orderUuid: sprintf('00000000-0000-4000-8000-%012d', $i + 1),
                orderedAt: $orderedAt->toDateTimeImmutable(),
                stageStartedAt: $orderedAt->subMinutes($stageAge)->toDateTimeImmutable(),
                modality: $modality,
                priority: $priority,
                patientClass: $class,
                queueDepth: $queueDepth,
                scannerDown: $scannerDown,
                signalCutoffAt: $orderedAt->toDateTimeImmutable(),
            );
            $extracted = $this->extractor->extract($observation, $orderedAt);
            $features = $extracted['features'];

            // Known data-generating logit: load and urgency drive breach risk.
            // The intercept keeps the synthetic base rate near a realistic
            // one-in-four so the naive base-rate baseline is a meaningful,
            // non-degenerate comparator.
            $logit = -3.1
                + 1.4 * $features['is_off_hours']
                + 1.7 * $features['scanner_down']
                + 2.1 * $features['queue_depth_norm']
                + 1.0 * $features['priority_stat']
                + 0.6 * $features['priority_urgent']
                + 0.8 * $features['patient_emergency']
                + 0.9 * $features['stage_age_norm']
                + 1.2 * $features['staffing_pressure'];
            $probability = 1.0 / (1.0 + exp(-$logit));
            $label = (mt_rand() / mt_getrandmax()) < $probability ? 1 : 0;

            $rows[] = [
                'orderedAt' => $orderedAt->toIso8601String(),
                'features' => $features,
                'label' => $label,
            ];
        }
        mt_srand();

        // Chronological order is the time axis the split relies on.
        usort($rows, static fn (array $a, array $b): int => strcmp($a['orderedAt'], $b['orderedAt']));

        return $rows;
    }

    /**
     * Batch-gradient-descent logistic regression with light L2. Transparent and
     * dependency-free; convergence is not critical for a planning aid.
     *
     * @param  list<array{features: array<string, float>, label: int}>  $rows
     */
    private function fit(array $rows): CalibratedLogisticModel
    {
        $features = BreachRiskFeatureSchema::FEATURES;
        $weights = array_fill_keys($features, 0.0);
        $bias = 0.0;
        $lr = 0.3;
        $l2 = 0.001;
        $n = max(1, count($rows));

        for ($epoch = 0; $epoch < 400; $epoch++) {
            $gradW = array_fill_keys($features, 0.0);
            $gradB = 0.0;
            foreach ($rows as $row) {
                $z = $bias;
                foreach ($features as $feature) {
                    $z += $weights[$feature] * (float) ($row['features'][$feature] ?? 0.0);
                }
                $prediction = 1.0 / (1.0 + exp(-$z));
                $error = $prediction - $row['label'];
                foreach ($features as $feature) {
                    $gradW[$feature] += $error * (float) ($row['features'][$feature] ?? 0.0);
                }
                $gradB += $error;
            }
            foreach ($features as $feature) {
                $weights[$feature] -= $lr * (($gradW[$feature] / $n) + $l2 * $weights[$feature]);
            }
            $bias -= $lr * ($gradB / $n);
        }

        // Placeholder identity curve; real curve fitted by fitCalibration().
        return new CalibratedLogisticModel($weights, $bias, [
            ['score' => 0.0, 'calibrated' => 0.0],
            ['score' => 1.0, 'calibrated' => 1.0],
        ]);
    }

    /**
     * Fit a monotone piecewise-linear calibration curve from reliability bins on
     * the training set (a transparent isotonic-style correction).
     *
     * @param  list<array{features: array<string, float>, label: int}>  $rows
     * @return list<array{score: float, calibrated: float}>
     */
    private function fitCalibration(CalibratedLogisticModel $model, array $rows): array
    {
        $bins = 10;
        $sumScore = array_fill(0, $bins, 0.0);
        $sumLabel = array_fill(0, $bins, 0.0);
        $count = array_fill(0, $bins, 0);
        foreach ($rows as $row) {
            $raw = $model->rawProbability($row['features']);
            $bin = min($bins - 1, (int) floor($raw * $bins));
            $sumScore[$bin] += $raw;
            $sumLabel[$bin] += $row['label'];
            $count[$bin]++;
        }

        $knots = [['score' => 0.0, 'calibrated' => 0.0]];
        $lastCalibrated = 0.0;
        for ($bin = 0; $bin < $bins; $bin++) {
            if ($count[$bin] === 0) {
                continue;
            }
            $meanScore = $sumScore[$bin] / $count[$bin];
            $meanLabel = $sumLabel[$bin] / $count[$bin];
            // Enforce monotonicity for a defensible reliability map.
            $calibrated = max($lastCalibrated, $meanLabel);
            $lastCalibrated = $calibrated;
            $knots[] = ['score' => round($meanScore, 6), 'calibrated' => round($calibrated, 6)];
        }
        $knots[] = ['score' => 1.0, 'calibrated' => round(max($lastCalibrated, $lastCalibrated === 1.0 ? 1.0 : $lastCalibrated), 6)];

        // Guarantee strictly increasing score knots for a well-defined map.
        $deduped = [];
        $prevScore = -1.0;
        foreach ($knots as $knot) {
            if ($knot['score'] > $prevScore) {
                $deduped[] = $knot;
                $prevScore = $knot['score'];
            }
        }

        return count($deduped) >= 2 ? $deduped : [['score' => 0.0, 'calibrated' => 0.0], ['score' => 1.0, 'calibrated' => 1.0]];
    }

    /**
     * Evaluate on the held-out later window and report calibration,
     * discrimination, coverage, and the naive-baseline comparison.
     *
     * @param  list<array{features: array<string, float>, label: int}>  $evaluate
     * @param  list<array{features: array<string, float>, label: int}>  $train
     * @return array<string, mixed>
     */
    private function evaluate(CalibratedLogisticModel $model, array $evaluate, array $train): array
    {
        $scored = [];
        foreach ($evaluate as $row) {
            $scored[] = ['p' => $model->calibratedProbability($row['features']), 'y' => (int) $row['label']];
        }

        // Naive baseline: predict the training-set base rate for everyone.
        $baseRate = count($train) > 0
            ? array_sum(array_column($train, 'label')) / count($train)
            : 0.0;

        $calibrationError = $this->expectedCalibrationError($scored);
        $auc = $this->auc($scored);
        $baselineAuc = 0.5; // A constant predictor has no discrimination.

        // Brier score is a proper scoring rule that rewards BOTH calibration and
        // discrimination, so it is the defensible gate for "beats the naive
        // base-rate predictor" — a constant predictor cannot be sharp.
        $brier = $this->brier($scored);
        $baselineBrier = $this->brier(array_map(
            static fn (array $row): array => ['p' => $baseRate, 'y' => $row['y']],
            $scored,
        ));
        $skillScore = $baselineBrier > 0.0 ? 1.0 - ($brier / $baselineBrier) : 0.0;

        return [
            'calibrationError' => round($calibrationError, 4),
            'discriminationAuc' => round($auc, 4),
            'brierScore' => round($brier, 4),
            'coverage' => [
                'evaluated' => count($scored),
                'total' => count($scored),
                'fraction' => 1.0,
                'note' => 'On the synthetic backtest every held-out order carries complete operational features; live coverage is reported per request as stale/missing signals degrade to low-confidence/unavailable.',
            ],
            'naiveBaseline' => [
                'strategy' => 'training_base_rate',
                'baseRate' => round($baseRate, 4),
                'brierScore' => round($baselineBrier, 4),
                'discriminationAuc' => $baselineAuc,
                'brierSkillScore' => round($skillScore, 4),
            ],
            'beatsBaseline' => $auc > $baselineAuc && $brier < $baselineBrier,
        ];
    }

    /**
     * Expected calibration error over 10 equal-width probability bins.
     *
     * @param  list<array{p: float, y: int}>  $scored
     */
    private function expectedCalibrationError(array $scored): float
    {
        if ($scored === []) {
            return 0.0;
        }
        $bins = 10;
        $sumP = array_fill(0, $bins, 0.0);
        $sumY = array_fill(0, $bins, 0.0);
        $count = array_fill(0, $bins, 0);
        foreach ($scored as $row) {
            $bin = min($bins - 1, (int) floor($row['p'] * $bins));
            $sumP[$bin] += $row['p'];
            $sumY[$bin] += $row['y'];
            $count[$bin]++;
        }
        $total = count($scored);
        $error = 0.0;
        for ($bin = 0; $bin < $bins; $bin++) {
            if ($count[$bin] === 0) {
                continue;
            }
            $meanP = $sumP[$bin] / $count[$bin];
            $meanY = $sumY[$bin] / $count[$bin];
            $error += ($count[$bin] / $total) * abs($meanP - $meanY);
        }

        return $error;
    }

    /**
     * Mean squared error of the probabilistic forecast (Brier score).
     *
     * @param  list<array{p: float, y: int}>  $scored
     */
    private function brier(array $scored): float
    {
        if ($scored === []) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($scored as $row) {
            $sum += ($row['p'] - $row['y']) ** 2;
        }

        return $sum / count($scored);
    }

    /**
     * Area under the ROC curve via the Mann–Whitney rank statistic.
     *
     * @param  list<array{p: float, y: int}>  $scored
     */
    private function auc(array $scored): float
    {
        $positives = array_values(array_filter($scored, static fn (array $row): bool => $row['y'] === 1));
        $negatives = array_values(array_filter($scored, static fn (array $row): bool => $row['y'] === 0));
        $np = count($positives);
        $nn = count($negatives);
        if ($np === 0 || $nn === 0) {
            return 0.5;
        }
        $ranked = $scored;
        usort($ranked, static fn (array $a, array $b): int => $a['p'] <=> $b['p']);
        $rankSumPositives = 0.0;
        $i = 0;
        $n = count($ranked);
        while ($i < $n) {
            $j = $i;
            while ($j < $n && $ranked[$j]['p'] === $ranked[$i]['p']) {
                $j++;
            }
            $averageRank = ($i + 1 + $j) / 2.0; // 1-based average rank across ties
            for ($k = $i; $k < $j; $k++) {
                if ($ranked[$k]['y'] === 1) {
                    $rankSumPositives += $averageRank;
                }
            }
            $i = $j;
        }

        return ($rankSumPositives - $np * ($np + 1) / 2.0) / ($np * $nn);
    }

    /** Deterministic standard-normal draw (Box–Muller) using mt_rand. */
    private function normal(): float
    {
        $u1 = max(1e-9, mt_rand() / mt_getrandmax());
        $u2 = mt_rand() / mt_getrandmax();

        return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }
}
