<?php

namespace Tests\Support\Timing;

use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

final class RecordPrepared implements PreparedSubscriber
{
    public function notify(Prepared $event): void
    {
        TimingEvidence::prepared($event->telemetryInfo()->time());
    }
}
