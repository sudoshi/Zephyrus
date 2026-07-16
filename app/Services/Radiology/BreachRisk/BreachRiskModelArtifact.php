<?php

namespace App\Services\Radiology\BreachRisk;

use RuntimeException;

/**
 * Reads the committed, versioned Radiology breach-risk model artifact.
 *
 * The artifact (config/radiology/breach_risk_model.json) carries the model
 * version, the frozen feature schema, the training/evaluation windows, the
 * fitted calibrated-logistic parameters, the calibration curve, and the four
 * required evaluation metrics (calibration error, discrimination/AUC, coverage,
 * and a naive-baseline comparison). It is always labelled SYNTHETIC because the
 * backtest is trained on demo history only, as a pipeline proof.
 */
final class BreachRiskModelArtifact
{
    private const RELATIVE_PATH = 'radiology/breach_risk_model.json';

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data) {}

    public static function path(): string
    {
        return config_path(self::RELATIVE_PATH);
    }

    public static function loadOrNull(): ?self
    {
        $path = self::path();
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return null;
        }

        return new self($decoded);
    }

    public static function load(): self
    {
        $artifact = self::loadOrNull();
        if ($artifact === null) {
            throw new RuntimeException('Radiology breach-risk model artifact is missing or unreadable at '.self::path());
        }

        return $artifact;
    }

    public function model(): CalibratedLogisticModel
    {
        return CalibratedLogisticModel::fromArtifact($this->data);
    }

    public function version(): string
    {
        return (string) ($this->data['modelVersion'] ?? 'unknown');
    }

    public function calibratedAt(): ?string
    {
        $value = $this->data['calibratedAt'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function isSynthetic(): bool
    {
        return (bool) ($this->data['synthetic'] ?? true);
    }

    /** @return list<string> */
    public function featureSchema(): array
    {
        $features = $this->data['featureSchema'] ?? [];

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => (string) $value,
            is_array($features) ? $features : [],
        )));
    }

    /** @return array<string, mixed> */
    public function trainingWindow(): array
    {
        $window = $this->data['trainingWindow'] ?? [];

        return is_array($window) ? $window : [];
    }

    /** @return array<string, mixed> */
    public function evaluation(): array
    {
        $evaluation = $this->data['evaluation'] ?? [];

        return is_array($evaluation) ? $evaluation : [];
    }

    /** @return list<array{score: float, calibrated: float}> */
    public function calibrationCurve(): array
    {
        return $this->model()->calibrationCurve;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * The provenance block surfaced with every score so the planning aid can
     * never appear as an unattributed alarm.
     *
     * @return array<string, mixed>
     */
    public function provenance(): array
    {
        $evaluation = $this->evaluation();

        return [
            'modelVersion' => $this->version(),
            'modelFamily' => (string) ($this->data['modelFamily'] ?? 'calibrated_logistic'),
            'calibratedAt' => $this->calibratedAt(),
            'synthetic' => $this->isSynthetic(),
            'syntheticLabel' => (string) ($this->data['syntheticLabel'] ?? 'Synthetic demo calibration — planning aid only, not a clinical model.'),
            'trainingWindow' => $this->trainingWindow(),
            'featureSchema' => $this->featureSchema(),
            'evaluation' => [
                'calibrationError' => $evaluation['calibrationError'] ?? null,
                'discriminationAuc' => $evaluation['discriminationAuc'] ?? null,
                'brierScore' => $evaluation['brierScore'] ?? null,
                'coverage' => $evaluation['coverage'] ?? null,
                'naiveBaseline' => $evaluation['naiveBaseline'] ?? null,
                'beatsBaseline' => $evaluation['beatsBaseline'] ?? null,
            ],
        ];
    }
}
