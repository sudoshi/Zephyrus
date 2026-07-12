<?php

namespace App\Services\Ancillary;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

final class AncillaryStatistics
{
    /**
     * PostgreSQL percentile_cont-compatible linear percentile.
     *
     * @param  array<array-key, mixed>  $values
     */
    public function percentile(array $values, float $percentile): ?float
    {
        if ($percentile < 0 || $percentile > 1) {
            return null;
        }

        $numbers = array_values(array_filter(
            array_map(
                static fn (mixed $value): ?float => is_numeric($value) && is_finite((float) $value)
                    ? (float) $value
                    : null,
                $values,
            ),
            static fn (?float $value): bool => $value !== null && $value >= 0,
        ));

        if ($numbers === []) {
            return null;
        }

        sort($numbers, SORT_NUMERIC);
        $rank = (count($numbers) - 1) * $percentile;
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);

        if ($lower === $upper) {
            return $numbers[$lower];
        }

        return $numbers[$lower] + (($numbers[$upper] - $numbers[$lower]) * ($rank - $lower));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array{count:int,median:?float,p90:?float}
     */
    public function distribution(array $values): array
    {
        $valid = array_values(array_filter(
            $values,
            static fn (mixed $value): bool => is_numeric($value) && is_finite((float) $value) && (float) $value >= 0,
        ));

        return [
            'count' => count($valid),
            'median' => $this->percentile($valid, 0.5),
            'p90' => $this->percentile($valid, 0.9),
        ];
    }

    public function intervalMinutes(
        DateTimeInterface|string|null $start,
        DateTimeInterface|string|null $stop,
        string $timezone = 'UTC',
    ): ?float {
        try {
            $startedAt = $this->timestamp($start, $timezone);
            $stoppedAt = $this->timestamp($stop, $timezone);
        } catch (Throwable) {
            return null;
        }

        if ($startedAt === null || $stoppedAt === null) {
            return null;
        }

        $seconds = $startedAt->diffInSeconds($stoppedAt, false);

        return $seconds < 0 ? null : round($seconds / 60, 2);
    }

    private function timestamp(DateTimeInterface|string|null $value, string $timezone): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value, $timezone);
    }
}
