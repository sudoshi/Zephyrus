<?php

declare(strict_types=1);

namespace Tests\Support\Scenario;

/**
 * Process-wide registry for the class-scoped committed ancillary demo
 * scenario (CI plan S2). Tracks which test class currently owns the
 * committed baseline so consumer classes rebuild it exactly once and the
 * base TestCase can force a fresh migration when a non-scenario class
 * follows a scenario class in the same PHP process.
 */
final class CommittedScenarioState
{
    /** Class name that built the currently committed baseline, if any. */
    public static ?string $activeClass = null;

    public static function reset(): void
    {
        self::$activeClass = null;
    }
}
