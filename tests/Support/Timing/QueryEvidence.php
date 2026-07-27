<?php

namespace Tests\Support\Timing;

/**
 * Per-test database query counters bridged from the Laravel application
 * (Tests\TestCase registers a DB::listen hook on application boot) to the
 * PHPUnit event subscribers, which run outside the application container.
 */
final class QueryEvidence
{
    private static int $count = 0;

    private static float $timeMs = 0.0;

    private static int $setupCount = 0;

    private static float $setupTimeMs = 0.0;

    public static function reset(): void
    {
        self::$count = 0;
        self::$timeMs = 0.0;
        self::$setupCount = 0;
        self::$setupTimeMs = 0.0;
    }

    public static function record(float $timeMs): void
    {
        self::$count++;
        self::$timeMs += $timeMs;
    }

    public static function markSetupComplete(): void
    {
        self::$setupCount = self::$count;
        self::$setupTimeMs = self::$timeMs;
    }

    /** @return array{setup_queries: int, setup_query_ms: float, body_queries: int, body_query_ms: float} */
    public static function split(): array
    {
        return [
            'setup_queries' => self::$setupCount,
            'setup_query_ms' => round(self::$setupTimeMs, 3),
            'body_queries' => self::$count - self::$setupCount,
            'body_query_ms' => round(self::$timeMs - self::$setupTimeMs, 3),
        ];
    }
}
