<?php

namespace App\Support\Operations;

final class DurationFormatter
{
    public static function seconds(int|float|null $value, string $unavailable = 'N/A'): string
    {
        if ($value === null || ! is_finite((float) $value)) {
            return $unavailable;
        }

        $totalSeconds = (int) round(abs($value));
        $negative = $value < 0 && $totalSeconds > 0;
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $seconds = $totalSeconds % 60;
        $parts = [];

        if ($hours > 0) {
            $parts[] = "{$hours} hr";
        }

        if ($hours > 0 || $minutes > 0) {
            $parts[] = "{$minutes} min";
        }

        $parts[] = "{$seconds} sec";

        return ($negative ? '-' : '').implode(' ', $parts);
    }

    public static function minutes(int|float|null $value, string $unavailable = 'N/A'): string
    {
        return $value === null
            ? $unavailable
            : self::seconds($value * 60, $unavailable);
    }

    public static function relativeMinutes(int|float|null $value): string
    {
        if ($value === null || ! is_finite((float) $value)) {
            return 'No target';
        }

        return self::relativeSeconds($value * 60);
    }

    public static function relativeSeconds(int|float|null $value): string
    {
        if ($value === null || ! is_finite((float) $value)) {
            return 'No target';
        }

        return self::seconds(abs($value)).' '.($value < 0 ? 'overdue' : 'remaining');
    }
}
