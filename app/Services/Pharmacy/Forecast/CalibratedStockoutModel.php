<?php

namespace App\Services\Pharmacy\Forecast;

use InvalidArgumentException;

/** Transparent calibrated logistic scorer for the synthetic stockout model. */
final readonly class CalibratedStockoutModel
{
    /**
     * @param  array<string, float>  $weights
     * @param  list<array{score: float, calibrated: float}>  $calibrationCurve
     */
    public function __construct(
        public array $weights,
        public float $bias,
        public array $calibrationCurve,
    ) {
        foreach (StockoutFeatureSchema::FEATURES as $feature) {
            if (! array_key_exists($feature, $weights)) {
                throw new InvalidArgumentException("Missing stockout-model weight for {$feature}.");
            }
        }
        if (count($calibrationCurve) < 2) {
            throw new InvalidArgumentException('The stockout calibration curve requires at least two knots.');
        }
    }

    /** @param array<string, mixed> $artifact */
    public static function fromArtifact(array $artifact): self
    {
        $model = (array) ($artifact['model'] ?? []);

        return new self(
            array_map(static fn (mixed $value): float => (float) $value, (array) ($model['weights'] ?? [])),
            (float) ($model['bias'] ?? 0.0),
            array_map(
                static fn (array $knot): array => ['score' => (float) $knot['score'], 'calibrated' => (float) $knot['calibrated']],
                (array) ($model['calibrationCurve'] ?? []),
            ),
        );
    }

    /** @param array<string, float> $features */
    public function rawProbability(array $features): float
    {
        $z = $this->bias;
        foreach ($this->weights as $feature => $weight) {
            $z += $weight * (float) ($features[$feature] ?? 0.0);
        }

        return 1.0 / (1.0 + exp(-$z));
    }

    /** @param array<string, float> $features */
    public function calibratedProbability(array $features): float
    {
        return $this->applyCalibration($this->rawProbability($features));
    }

    public function applyCalibration(float $raw): float
    {
        $raw = max(0.0, min(1.0, $raw));
        $first = $this->calibrationCurve[0];
        if ($raw <= $first['score']) {
            return $first['calibrated'];
        }
        $last = $this->calibrationCurve[count($this->calibrationCurve) - 1];
        if ($raw >= $last['score']) {
            return $last['calibrated'];
        }
        for ($i = 1, $n = count($this->calibrationCurve); $i < $n; $i++) {
            $low = $this->calibrationCurve[$i - 1];
            $high = $this->calibrationCurve[$i];
            if ($raw <= $high['score']) {
                $span = $high['score'] - $low['score'];
                if ($span <= 0.0) {
                    return $high['calibrated'];
                }
                $fraction = ($raw - $low['score']) / $span;

                return $low['calibrated'] + $fraction * ($high['calibrated'] - $low['calibrated']);
            }
        }

        return $last['calibrated'];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['weights' => $this->weights, 'bias' => $this->bias, 'calibrationCurve' => $this->calibrationCurve];
    }
}
