<?php

namespace App\Services\Lab\AmReadiness;

use RuntimeException;

/**
 * Reads the committed, versioned Laboratory AM-readiness model artifact.
 *
 * The artifact (config/lab/am_readiness_model.json) carries the model version,
 * the frozen feature schema, the prediction target (including the morning-rounds
 * cutoff policy the forecast answers against), the training/evaluation windows,
 * the fitted calibrated-logistic parameters, the calibration curve, and the four
 * required evaluation metrics (calibration error, discrimination/AUC, coverage,
 * and a naive-baseline comparison). It is always labelled SYNTHETIC because the
 * backtest is trained on demo history only, as a pipeline proof.
 */
final class AmReadinessModelArtifact
{
    private const RELATIVE_PATH = 'lab/am_readiness_model.json';

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
            throw new RuntimeException('Laboratory AM-readiness model artifact is missing or unreadable at '.self::path());
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
    public function target(): array
    {
        $target = $this->data['target'] ?? [];

        return is_array($target) ? $target : [];
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
     * The provenance block surfaced with every forecast so the planning aid can
     * never appear as an unattributed alarm. Carries the rounds-cutoff policy
     * the forecast answers against alongside the model metrics.
     *
     * @return array<string, mixed>
     */
    public function provenance(): array
    {
        $evaluation = $this->evaluation();
        $target = $this->target();

        return [
            'modelVersion' => $this->version(),
            'modelFamily' => (string) ($this->data['modelFamily'] ?? 'calibrated_logistic'),
            'calibratedAt' => $this->calibratedAt(),
            'synthetic' => $this->isSynthetic(),
            'syntheticLabel' => (string) ($this->data['syntheticLabel'] ?? 'Synthetic demo calibration — planning aid only, not a clinical model.'),
            'roundsCutoffLabel' => (string) ($target['roundsCutoffLabel'] ?? 'Morning rounds cutoff'),
            'horizonDefinition' => (string) ($target['definition'] ?? ''),
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
