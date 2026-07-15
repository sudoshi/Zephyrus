<?php

namespace App\Services\Pharmacy\Forecast;

use RuntimeException;

final class PharmacyForecastModelArtifact
{
    private const RELATIVE_PATH = 'pharmacy/forecast_model.json';

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data) {}

    public static function path(): string
    {
        return config_path(self::RELATIVE_PATH);
    }

    public static function loadOrNull(): ?self
    {
        if (! is_file(self::path())) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents(self::path()), true);

        return is_array($decoded) ? new self($decoded) : null;
    }

    public static function load(): self
    {
        return self::loadOrNull() ?? throw new RuntimeException('Pharmacy forecast artifact is missing or unreadable at '.self::path());
    }

    public function version(): string
    {
        return (string) ($this->data['modelVersion'] ?? 'unknown');
    }

    /** @return array<string, mixed> */
    public function queue(): array
    {
        return is_array($this->data['queue'] ?? null) ? $this->data['queue'] : [];
    }

    /** @return array<string, mixed> */
    public function stockout(): array
    {
        return is_array($this->data['stockout'] ?? null) ? $this->data['stockout'] : [];
    }

    public function stockoutModel(): CalibratedStockoutModel
    {
        return CalibratedStockoutModel::fromArtifact($this->stockout());
    }

    /** @return array<string, mixed> */
    public function provenance(): array
    {
        return [
            'modelVersion' => $this->version(),
            'modelFamily' => (string) ($this->data['modelFamily'] ?? 'seasonal_queue_and_calibrated_logistic_stockout'),
            'calibratedAt' => $this->data['calibratedAt'] ?? null,
            'synthetic' => (bool) ($this->data['synthetic'] ?? true),
            'syntheticLabel' => (string) ($this->data['syntheticLabel'] ?? 'Synthetic demo calibration — planning aid only.'),
            'targets' => $this->data['targets'] ?? [],
            'queueEvaluation' => $this->queue()['evaluation'] ?? [],
            'stockoutEvaluation' => $this->stockout()['evaluation'] ?? [],
            'queueTrainingWindow' => $this->queue()['trainingWindow'] ?? [],
            'stockoutTrainingWindow' => $this->stockout()['trainingWindow'] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
