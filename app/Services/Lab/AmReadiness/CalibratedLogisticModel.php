<?php

namespace App\Services\Lab\AmReadiness;

use InvalidArgumentException;

/**
 * A transparent, self-contained calibrated logistic-regression scorer for the
 * Laboratory AM-readiness forecast.
 *
 * The model is a plain logistic regression (weights + bias over the frozen
 * {@see AmReadinessFeatureSchema} vector) whose raw probability is corrected by a
 * monotone piecewise-linear calibration map fitted at training time. There is no
 * hidden state, no external library, and no training happening at score time —
 * this is a planning aid, not a production ML system. Every coefficient and
 * calibration knot is persisted in the committed artifact so a reviewer can read
 * exactly how any forecast was produced.
 */
final readonly class CalibratedLogisticModel
{
    /**
     * @param  array<string, float>  $weights  keyed by feature name
     * @param  list<array{score: float, calibrated: float}>  $calibrationCurve  monotone knots
     */
    public function __construct(
        public array $weights,
        public float $bias,
        public array $calibrationCurve,
    ) {
        foreach (AmReadinessFeatureSchema::FEATURES as $feature) {
            if (! array_key_exists($feature, $weights)) {
                throw new InvalidArgumentException("Missing weight for feature: {$feature}");
            }
        }
        if (count($calibrationCurve) < 2) {
            throw new InvalidArgumentException('A calibration curve needs at least two knots.');
        }
    }

    /** @param array<string, mixed> $artifact */
    public static function fromArtifact(array $artifact): self
    {
        $model = $artifact['model'] ?? [];

        return new self(
            weights: array_map(static fn (mixed $value): float => (float) $value, (array) ($model['weights'] ?? [])),
            bias: (float) ($model['bias'] ?? 0.0),
            calibrationCurve: array_map(
                static fn (array $knot): array => ['score' => (float) $knot['score'], 'calibrated' => (float) $knot['calibrated']],
                (array) ($model['calibrationCurve'] ?? []),
            ),
        );
    }

    /**
     * Raw (uncalibrated) logistic probability for a feature vector.
     *
     * @param  array<string, float>  $features
     */
    public function rawProbability(array $features): float
    {
        $z = $this->bias;
        foreach ($this->weights as $feature => $weight) {
            $z += $weight * (float) ($features[$feature] ?? 0.0);
        }

        return 1.0 / (1.0 + exp(-$z));
    }

    /**
     * Calibrated probability: the raw logistic score mapped through the fitted
     * monotone reliability curve.
     *
     * @param  array<string, float>  $features
     */
    public function calibratedProbability(array $features): float
    {
        return $this->applyCalibration($this->rawProbability($features));
    }

    public function applyCalibration(float $raw): float
    {
        $raw = max(0.0, min(1.0, $raw));
        $curve = $this->calibrationCurve;
        $first = $curve[0];
        if ($raw <= $first['score']) {
            return $first['calibrated'];
        }
        $last = $curve[count($curve) - 1];
        if ($raw >= $last['score']) {
            return $last['calibrated'];
        }
        for ($i = 1, $n = count($curve); $i < $n; $i++) {
            $lo = $curve[$i - 1];
            $hi = $curve[$i];
            if ($raw <= $hi['score']) {
                $span = $hi['score'] - $lo['score'];
                if ($span <= 0.0) {
                    return $hi['calibrated'];
                }
                $t = ($raw - $lo['score']) / $span;

                return $lo['calibrated'] + $t * ($hi['calibrated'] - $lo['calibrated']);
            }
        }

        return $last['calibrated'];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'weights' => $this->weights,
            'bias' => $this->bias,
            'calibrationCurve' => $this->calibrationCurve,
        ];
    }
}
