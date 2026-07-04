<?php

namespace Tests\Feature\Cockpit;

use App\Services\Analytics\OperationsAnalyticsService;
use App\Services\Cockpit\StatusEngine;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A0/A3 status parity (Zephyrus 2.0 P5, plan §P5.4).
 *
 * OperationsAnalyticsService's private band helpers must resolve EXACTLY as
 * the StatusEngine does — the second copy of the threshold logic is retired
 * and these pins prevent it from ever growing back. A tile that is crit at
 * A0 (cockpit) is crit in its A3 trend (analytics hub) by construction.
 * PHPUnit class syntax only (Pest is excluded).
 */
class StatusParityTest extends TestCase
{
    /** Value sweep crossing both edges, including exact-edge values. */
    private const SWEEP = [0, 49.9, 50, 74.9, 75, 84.9, 85, 89.9, 90, 91.9, 92, 100, 130];

    public function test_band_high_bad_matches_status_engine_exactly(): void
    {
        $analytics = app(OperationsAnalyticsService::class);
        $engine = app(StatusEngine::class);
        $method = new ReflectionMethod($analytics, 'bandHighBad');

        // The edge pairs actually used across the analytics hub today.
        $edgePairs = [[92, 85], [75, 50], [3, 2], [30, 20], [120, 60]];

        foreach ($edgePairs as [$crit, $warn]) {
            foreach (self::SWEEP as $value) {
                $this->assertSame(
                    $engine->highBad($value, $crit, $warn)->canon(),
                    $method->invoke($analytics, $value, $crit, $warn),
                    "bandHighBad({$value}, {$crit}, {$warn}) diverged from StatusEngine"
                );
            }
        }
    }

    public function test_completeness_status_matches_status_engine_low_bad(): void
    {
        $analytics = app(OperationsAnalyticsService::class);
        $engine = app(StatusEngine::class);
        $method = new ReflectionMethod($analytics, 'completenessStatus');

        foreach ([0, 50, 74, 75, 76, 89, 90, 91, 100] as $pct) {
            $this->assertSame(
                $engine->lowBad($pct, 90, 75)->canon(),
                $method->invoke($analytics, $pct),
                "completenessStatus({$pct}) diverged from StatusEngine"
            );
        }
    }

    public function test_legacy_string_vocabulary_is_preserved(): void
    {
        $analytics = app(OperationsAnalyticsService::class);
        $method = new ReflectionMethod($analytics, 'bandHighBad');

        // The delegation must be byte-identical to the retired copy.
        $this->assertSame('critical', $method->invoke($analytics, 95, 92, 85));
        $this->assertSame('critical', $method->invoke($analytics, 92, 92, 85));
        $this->assertSame('warning', $method->invoke($analytics, 88, 92, 85));
        $this->assertSame('warning', $method->invoke($analytics, 85, 92, 85));
        $this->assertSame('success', $method->invoke($analytics, 80, 92, 85));
    }
}
