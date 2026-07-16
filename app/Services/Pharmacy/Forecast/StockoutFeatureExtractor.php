<?php

namespace App\Services\Pharmacy\Forecast;

use Carbon\CarbonImmutable;

final class StockoutFeatureExtractor
{
    public const STALE_AFTER_MINUTES = 120;

    public const UNUSABLE_AFTER_MINUTES = 720;

    /**
     * @return array{availability: 'available'|'low_confidence'|'unavailable', features: array<string, float>, missing: list<string>, explanation: string}
     */
    public function extract(StockoutObservation $observation, CarbonImmutable $now): array
    {
        $missing = [];
        if ($observation->onHand === null) {
            $missing[] = 'on_hand';
        }
        if ($observation->parLevel === null || $observation->parLevel <= 0.0) {
            $missing[] = 'par_level';
        }
        if ($observation->inventoryCapturedAt === null) {
            $missing[] = 'inventory_captured_at';
        }
        if ($observation->vendPerHour === null) {
            $missing[] = 'vend_velocity';
        }
        if ($observation->refillUnitsPerHour === null) {
            $missing[] = 'refill_velocity';
        }
        if ($observation->minutesSinceRefill === null) {
            $missing[] = 'refill_cadence';
        }

        $capturedAt = $observation->inventoryCapturedAt === null
            ? null
            : CarbonImmutable::instance($observation->inventoryCapturedAt)->utc();
        if ($capturedAt !== null && $capturedAt->isAfter($now)) {
            $missing[] = 'inventory_cutoff_in_future';
        }
        $ageMinutes = $capturedAt?->diffInMinutes($now, false);
        if ($ageMinutes !== null && $ageMinutes > self::UNUSABLE_AFTER_MINUTES) {
            $missing[] = 'inventory_snapshot_expired';
        } elseif ($ageMinutes !== null && $ageMinutes > self::STALE_AFTER_MINUTES) {
            $missing[] = 'inventory_snapshot_stale';
        }
        if ($observation->onHand !== null && $observation->onHand < 0.0) {
            $missing[] = 'on_hand_invalid';
        }

        $hardMissing = array_values(array_intersect($missing, [
            'on_hand', 'par_level', 'inventory_captured_at', 'inventory_cutoff_in_future',
            'inventory_snapshot_expired', 'on_hand_invalid', 'vend_velocity', 'refill_velocity', 'refill_cadence',
        ]));
        if ($hardMissing !== []) {
            return [
                'availability' => 'unavailable',
                'features' => array_fill_keys(StockoutFeatureSchema::FEATURES, 0.0),
                'missing' => array_values(array_unique($missing)),
                'explanation' => 'A calibrated stockout probability requires valid on-hand, par, inventory-cutoff, vend-velocity, and refill-cadence evidence.',
            ];
        }

        $hour = (int) $now->format('G');
        $angle = 2.0 * M_PI * ($hour / 24.0);
        $inventoryRatio = min(2.0, max(0.0, (float) $observation->onHand / (float) $observation->parLevel));
        $vendVelocity = min(2.0, max(0.0, (float) $observation->vendPerHour / 4.0));
        $refillVelocity = min(2.0, max(0.0, (float) $observation->refillUnitsPerHour / 4.0));
        $refillGap = min(2.0, max(0.0, (float) $observation->minutesSinceRefill / 480.0));
        $features = [
            'inventory_ratio' => $inventoryRatio,
            'vend_velocity_norm' => $vendVelocity,
            'refill_velocity_norm' => $refillVelocity,
            'refill_gap_norm' => $refillGap,
            'shortage_flag' => $observation->shortageFlag ? 1.0 : 0.0,
            'station_downtime' => $observation->stationDown ? 1.0 : 0.0,
            'hour_sin' => sin($angle),
            'hour_cos' => cos($angle),
            'is_weekend' => (int) $now->format('N') >= 6 ? 1.0 : 0.0,
            'inventory_pressure' => max(0.0, 1.0 - min(1.0, $inventoryRatio)) * $vendVelocity,
        ];

        $availability = ($ageMinutes ?? PHP_INT_MAX) > self::STALE_AFTER_MINUTES ? 'low_confidence' : 'available';

        return [
            'availability' => $availability,
            'features' => $features,
            'missing' => $availability === 'low_confidence' ? ['inventory_snapshot_stale'] : [],
            'explanation' => $availability === 'low_confidence'
                ? 'The inventory snapshot is older than the two-hour planning tolerance; probability is shown with low confidence and its cutoff remains visible.'
                : 'Valid station-level inventory, vend velocity, and refill evidence are available for the six-hour planning horizon.',
        ];
    }
}
