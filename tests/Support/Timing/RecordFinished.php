<?php

namespace Tests\Support\Timing;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

final class RecordFinished implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        TimingEvidence::finished($event->test()->id(), $event->telemetryInfo()->time());
    }
}
