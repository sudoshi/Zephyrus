<?php

namespace Tests\Unit\Demo;

use App\Services\Demo\DemoClock;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class DemoClockTest extends TestCase
{
    public function test_freezes_explicit_anchor_and_derives_symmetric_window(): void
    {
        $anchor = CarbonImmutable::parse('2026-07-10 12:00:00');
        $clock = new DemoClock($anchor, 48);

        $this->assertTrue($clock->anchor()->equalTo($anchor));
        $this->assertSame('2026-07-09 12:00:00', $clock->windowStart()->toDateTimeString());
        $this->assertSame('2026-07-11 12:00:00', $clock->windowEnd()->toDateTimeString());
        $this->assertSame(48, $clock->windowHours());
    }

    public function test_parses_now_null_and_iso_option_forms(): void
    {
        $this->assertInstanceOf(CarbonImmutable::class, DemoClock::fromOption('now')->anchor());
        $this->assertInstanceOf(CarbonImmutable::class, DemoClock::fromOption(null)->anchor());
        $this->assertSame('2026-01-02 03:04:05', DemoClock::fromOption('2026-01-02 03:04:05')->anchor()->toDateTimeString());
    }

    public function test_knows_whether_a_timestamp_is_inside_the_window(): void
    {
        $clock = new DemoClock(CarbonImmutable::parse('2026-07-10 12:00:00'), 48);

        $this->assertTrue($clock->contains(CarbonImmutable::parse('2026-07-10 12:00:00')));
        $this->assertTrue($clock->contains(CarbonImmutable::parse('2026-07-09 12:00:00')));
        $this->assertTrue($clock->contains(CarbonImmutable::parse('2026-07-11 12:00:00')));
        $this->assertFalse($clock->contains(CarbonImmutable::parse('2026-07-08 23:59:59')));
        $this->assertFalse($clock->contains(CarbonImmutable::parse('2026-07-11 12:00:01')));
    }
}
