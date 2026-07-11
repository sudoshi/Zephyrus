<?php

namespace Tests\Unit\Support\Operations;

use App\Support\Operations\DurationFormatter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DurationFormatterTest extends TestCase
{
    #[Test]
    public function it_rounds_once_and_decomposes_fractional_minutes(): void
    {
        $this->assertSame('1 hr 1 min 31 sec', DurationFormatter::minutes(61.50833333333333));
        $this->assertSame('30 sec', DurationFormatter::minutes(0.5));
        $this->assertSame('0 sec', DurationFormatter::minutes(0));
        $this->assertSame('0 sec', DurationFormatter::seconds(-0.4));
    }

    #[Test]
    public function it_formats_relative_sla_durations(): void
    {
        $this->assertSame('1 hr 30 min 15 sec overdue', DurationFormatter::relativeMinutes(-90.25));
        $this->assertSame('12 min 30 sec remaining', DurationFormatter::relativeMinutes(12.5));
        $this->assertSame('1 min 31 sec overdue', DurationFormatter::relativeSeconds(-91));
        $this->assertSame('No target', DurationFormatter::relativeMinutes(null));
    }
}
