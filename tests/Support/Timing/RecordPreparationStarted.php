<?php

namespace Tests\Support\Timing;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

final class RecordPreparationStarted implements PreparationStartedSubscriber
{
    public function notify(PreparationStarted $event): void
    {
        TimingEvidence::preparationStarted($event->test()->id(), $event->telemetryInfo()->time());
    }
}
