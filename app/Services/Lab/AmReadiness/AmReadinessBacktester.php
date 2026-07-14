<?php

namespace App\Services\Lab\AmReadiness;

use Carbon\CarbonImmutable;

/**
 * Trains and backtests the Laboratory AM-readiness model on SYNTHETIC demo
 * history, as a pipeline proof (P4-2).
 *
 * The cohort is a deterministic, seeded synthetic stream of decision-class
 * Laboratory orders whose "verified before the morning-rounds cutoff" label is
 * drawn from a known operational data-generating process: an order further along
 * the collect→receive→result path, with more time remaining before the cutoff,
 * on a running analyzer and a shallow same-family queue is more likely to verify
 * in time; being past the cutoff, deep queues, analyzer downtime, and off-hours
 * processing lower the probability. It is split by time: the earlier window
 * trains the calibrated logistic model, the later window evaluates it. Feature
 * extraction happens strictly at each order's own prediction instant, so the
 * training set can never see a verification that had not yet occurred — the
 * time-split leakage acceptance is enforced structurally.
 *
 * The produced artifact carries the model, feature schema, the rounds-cutoff
 * target, both windows, the calibration curve, and the four required metrics
 * (calibration error, discrimination/AUC, coverage, naive-baseline comparison).
 * It is always labelled synthetic.
 */
final class AmReadinessBacktester
{
    public const MODEL_VERSION = 'lab-am-readiness-2026.07.13-synthetic-v1';

    private const COHORT_SIZE = 900;

    private const TRAIN_FRACTION = 0.7;

    /** Deterministic anchor so every regeneration/backtest is byte-identical. */
    private const ANCHOR = '2026-07-13T12:00:00+00:00';

    private const SEED = 20260713;

    /** Synthetic rounds-cutoff policy label mirrored from config for provenance. */
    private const CUTOFF_LABEL = 'Morning rounds (08:00 local)';

    private const STAGES = ['ordered', 'collected', 'received', 'resulted'];

    private const FAMILIES = ['chemistry', 'hematology', 'coagulation', 'other'];

    public function __construct(private readonly AmReadinessFeatureExtractor $extractor) {}

    /**
     * Run the full train → backtest → artifact pipeline and return the artifact
     * array (the same structure persisted to config/lab/am_readiness_model.json).
     *
     * @return array<string, mixed>
     */
    public function buildArtifact(): array
    {
        $anchor = CarbonImmutable::parse(self::ANCHOR);
        $cohort = $this->syntheticCohort($anchor);

        $splitIndex = (int) floor(count($cohort) * self::TRAIN_FRACTION);
        $splitAt = $cohort[$splitIndex]['predictedAt'];
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
                'definition' => 'An open decision-class Laboratory order (discharge_gate / ed_disposition) reaches LAB_VERIFIED before the configured morning-rounds cutoff.',
                'roundsCutoffLabel' => self::CUTOFF_LABEL,
                'cutoffRule' => 'The rounds cutoff is a policy value separate from any SLA/measurement. Features are anchored at the prediction instant; no verification timestamp, result value, or post-prediction evidence enters the feature vector.',
            ],
            'calibratedAt' => $anchor->toIso8601String(),
            'featureSchema' => AmReadinessFeatureSchema::FEATURES,
            'trainingWindow' => [
                'anchor' => $anchor->toIso8601String(),
                'cohortSize' => count($cohort),
                'trainCount' => count($train),
                'evaluateCount' => count($evaluate),
                'splitAt' => $splitAt,
                'trainFrom' => $cohort[0]['predictedAt'],
                'trainTo' => $train[count($train) - 1]['predictedAt'],
                'evaluateFrom' => $evaluate[0]['predictedAt'],
                'evaluateTo' => $evaluate[count($evaluate) - 1]['predictedAt'],
            ],
            'model' => $calibrated->toArray(),
            'evaluation' => $evaluation,
        ];
    }

    /**
     * Deterministic synthetic decision-class Laboratory-order stream with a
     * known verify-before-cutoff process.
     *
     * @return list<array{predictedAt: string, features: array<string, float>, label: int}>
     */
    private function syntheticCohort(CarbonImmutable $anchor): array
    {
        mt_srand(self::SEED);
        $priorities = ['stat', 'urgent', 'routine', 'timed'];
        $classes = ['emergency', 'inpatient', 'observation'];
        $rows = [];

        for ($i = 0; $i < self::COHORT_SIZE; $i++) {
            // Predictions are spread backwards over ~30 days at deterministic intervals.
            $predictedAt = $anchor->subMinutes((self::COHORT_SIZE - $i) * 48 + ($i % 7) * 3);
            $stage = self::STAGES[mt_rand(0, count(self::STAGES) - 1)];
            $family = self::FAMILIES[mt_rand(0, count(self::FAMILIES) - 1)];
            $priority = $priorities[mt_rand(0, count($priorities) - 1)];
            $class = $classes[mt_rand(0, count($classes) - 1)];
            $isAmDraw = mt_rand(0, 99) < 55;
            $hour = (int) $predictedAt->format('G');
            $dow = (int) $predictedAt->format('N');
            $offHours = ($hour < 7 || $hour >= 19 || $dow >= 6);
            $queueDepth = max(0, (int) round($this->normal() * 3 + ($offHours ? 7 : 4)));
            $analyzerDowntime = mt_rand(0, 99) < ($offHours ? 20 : 7);

            // Minutes remaining until the next rounds cutoff, from a plausible spread.
            $minutesToCutoff = (int) round($this->normal() * 90 + 60);
            $roundsCutoffAt = $predictedAt->addMinutes($minutesToCutoff);

            $observation = new AmReadinessObservation(
                orderId: $i + 1,
                orderUuid: sprintf('00000000-0000-4000-8000-%012d', $i + 1),
                stage: $stage,
                testFamily: $family,
                priority: $priority,
                patientClass: $class,
                isAmDraw: $isAmDraw,
                queueDepth: $queueDepth,
                analyzerDowntime: $analyzerDowntime,
                roundsCutoffAt: $roundsCutoffAt->toDateTimeImmutable(),
                signalCutoffAt: $predictedAt->toDateTimeImmutable(),
            );
            $extracted = $this->extractor->extract($observation, $predictedAt);
            $features = $extracted['features'];

            // Known data-generating logit: readiness rises with processing
            // progress and remaining time, falls with queue depth, downtime,
            // being past the cutoff, and off-hours pressure. The intercept keeps
            // the synthetic verify-before-cutoff rate near two-in-three so the
            // naive base-rate baseline is a meaningful, non-degenerate comparator.
            $logit = -0.2
                + 0.9 * $features['stage_collected']
                + 1.9 * $features['stage_received']
                + 3.0 * $features['stage_resulted']
                + 2.4 * $features['minutes_to_cutoff_norm']
                - 2.6 * $features['past_cutoff']
                - 2.0 * $features['queue_depth_norm']
                - 1.3 * $features['analyzer_downtime']
                - 0.7 * $features['is_off_hours']
                - 1.1 * $features['processing_pressure'];
            $probability = 1.0 / (1.0 + exp(-$logit));
            $label = (mt_rand() / mt_getrandmax()) < $probability ? 1 : 0;

            $rows[] = [
                'predictedAt' => $predictedAt->toIso8601String(),
                'features' => $features,
                'label' => $label,
            ];
        }
        mt_srand();

        // Chronological order is the time axis the split relies on.
        usort($rows, static fn (array $a, array $b): int => strcmp($a['predictedAt'], $b['predictedAt']));

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
        $features = AmReadinessFeatureSchema::FEATURES;
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
