<?php

namespace Tests\Support\Timing;

use PHPUnit\Event\Telemetry\HRTime;

/**
 * §3.2.9 instrumentation: per-test setUp-vs-body wall-time split plus the
 * database query counts from QueryEvidence, appended as NDJSON into the
 * release-evidence directory. Inactive unless RELEASE_EVIDENCE_DIR is set.
 */
final class TimingEvidence
{
    private static ?string $currentTestId = null;

    private static ?HRTime $preparationStartedAt = null;

    private static ?HRTime $preparedAt = null;

    public static function evidencePath(): ?string
    {
        $dir = getenv('RELEASE_EVIDENCE_DIR');
        if ($dir === false || $dir === '') {
            return null;
        }

        return rtrim($dir, '/').'/phpunit-setup-split.ndjson';
    }

    public static function preparationStarted(string $testId, HRTime $time): void
    {
        self::$currentTestId = $testId;
        self::$preparationStartedAt = $time;
        self::$preparedAt = null;
        QueryEvidence::reset();
    }

    public static function prepared(HRTime $time): void
    {
        self::$preparedAt = $time;
        QueryEvidence::markSetupComplete();
    }

    public static function finished(string $testId, HRTime $time): void
    {
        $path = self::evidencePath();

        if ($path === null || self::$preparationStartedAt === null || self::$currentTestId !== $testId) {
            return;
        }

        $totalSeconds = $time->duration(self::$preparationStartedAt)->asFloat();
        $setupSeconds = self::$preparedAt?->duration(self::$preparationStartedAt)->asFloat();

        // setup = PreparationStarted -> Prepared (the full setUp() chain,
        // seeders included); body = Prepared -> Finished (test method plus
        // tearDown). A test that failed during setUp never emits Prepared,
        // so its split fields stay null while total_s remains accurate.
        $record = [
            'test' => $testId,
            'setup_s' => $setupSeconds !== null ? round($setupSeconds, 6) : null,
            'body_s' => $setupSeconds !== null ? round($totalSeconds - $setupSeconds, 6) : null,
            'total_s' => round($totalSeconds, 6),
            ...QueryEvidence::split(),
        ];

        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode($record).PHP_EOL, FILE_APPEND | LOCK_EX);

        self::$currentTestId = null;
        self::$preparationStartedAt = null;
        self::$preparedAt = null;
    }
}
