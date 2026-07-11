<?php

namespace App\Support\Operations;

use Carbon\CarbonInterface;

final class SourceFreshness
{
    /**
     * @return array{
     *   key:string,
     *   label:string,
     *   status:string,
     *   generated_at:string,
     *   last_observed_at:?string,
     *   age_minutes:?int,
     *   expected_cadence_minutes:int,
     *   stale_after_minutes:int,
     *   synthetic:bool,
     *   message:string
     * }
     */
    public static function make(
        string $key,
        string $label,
        ?CarbonInterface $lastObservedAt,
        int $expectedCadenceMinutes,
        int $staleAfterMinutes,
        bool $synthetic = false,
        bool $degraded = false,
    ): array {
        $ageMinutes = $lastObservedAt === null
            ? null
            : max(0, (int) floor((now()->getTimestamp() - $lastObservedAt->getTimestamp()) / 60));

        $status = match (true) {
            $lastObservedAt === null => 'missing',
            $degraded => 'degraded',
            $ageMinutes <= $expectedCadenceMinutes => 'fresh',
            $ageMinutes <= $staleAfterMinutes => 'aging',
            default => 'stale',
        };

        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'generated_at' => now()->toISOString(),
            'last_observed_at' => $lastObservedAt?->toISOString(),
            'age_minutes' => $ageMinutes,
            'expected_cadence_minutes' => $expectedCadenceMinutes,
            'stale_after_minutes' => $staleAfterMinutes,
            'synthetic' => $synthetic,
            'message' => self::message($status, $label, $ageMinutes),
        ];
    }

    private static function message(string $status, string $label, ?int $ageMinutes): string
    {
        return match ($status) {
            'fresh' => "{$label} is current.",
            'aging' => "{$label} is aging; the last observation was ".DurationFormatter::minutes($ageMinutes).' ago.',
            'stale' => "{$label} is stale; the last observation was ".DurationFormatter::minutes($ageMinutes).' ago.',
            'degraded' => "{$label} is degraded; values may be incomplete.",
            default => "{$label} has no observations.",
        };
    }
}
