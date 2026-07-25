<?php

namespace Tests\Support\Timing;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Registers the §3.2.9 timing subscribers. A no-op (zero overhead) when
 * RELEASE_EVIDENCE_DIR is not set, i.e. every run outside CI evidence lanes.
 */
final class TestTimingEvidenceExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (TimingEvidence::evidencePath() === null) {
            return;
        }

        $facade->registerSubscribers(
            new RecordPreparationStarted,
            new RecordPrepared,
            new RecordFinished,
        );
    }
}
