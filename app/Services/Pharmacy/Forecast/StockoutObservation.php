<?php

namespace App\Services\Pharmacy\Forecast;

use DateTimeImmutable;

/**
 * Available-at-prediction station/medication observation. It intentionally
 * carries no outcome, staff identity, patient detail, or diversion attribute.
 */
final readonly class StockoutObservation
{
    public function __construct(
        public int $stationId,
        public string $stationKey,
        public string $localCode,
        public DateTimeImmutable $predictedAt,
        public ?float $onHand,
        public ?float $parLevel,
        public ?DateTimeImmutable $inventoryCapturedAt,
        public ?float $vendPerHour,
        public ?float $refillUnitsPerHour,
        public ?float $minutesSinceRefill,
        public bool $shortageFlag,
        public bool $stationDown,
    ) {}
}
