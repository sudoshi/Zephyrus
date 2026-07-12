<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Services\Analytics\OperationalIntervalCalculator;
use App\Services\Analytics\SuiteMetricCalculator;
use Tests\TestCase;

final class SuiteMetricCalculatorTest extends TestCase
{
    public function test_identical_perioperative_and_ir_interval_fixtures_share_one_result(): void
    {
        $calculator = new SuiteMetricCalculator(new OperationalIntervalCalculator);
        $fixture = [
            'available' => [['start' => '2026-07-12T08:00:00Z', 'end' => '2026-07-12T16:00:00Z']],
            'occupied' => [
                ['start' => '2026-07-12T08:10:00Z', 'end' => '2026-07-12T09:00:00Z'],
                ['start' => '2026-07-12T09:40:00Z', 'end' => '2026-07-12T10:30:00Z'],
            ],
        ];

        // Both adapters receive the same shared authority; a domain label is
        // deliberately not an input to any calculation.
        $perioperative = $calculator->utilization($fixture['available'], $fixture['occupied']);
        $ir = $calculator->utilization($fixture['available'], $fixture['occupied']);

        $this->assertEquals($perioperative, $ir);
        $this->assertSame(480, $ir['availableMinutes']);
        $this->assertSame(100, $ir['examMinutes']);
        $this->assertSame(20.8, $ir['utilizationPercent']);
        $this->assertTrue($calculator->firstCaseOnTime('2026-07-12T08:00:00Z', '2026-07-12T08:15:00Z'));
        $this->assertFalse($calculator->firstCaseOnTime('2026-07-12T08:00:00Z', '2026-07-12T08:16:00Z'));
        $this->assertSame(40, $calculator->turnoverMinutes('2026-07-12T09:00:00Z', '2026-07-12T09:40:00Z'));
        $this->assertNull($calculator->turnoverMinutes('2026-07-12T09:00:00Z', '2026-07-12T08:59:00Z'));
        $this->assertSame(1, $calculator->roomsRunningAt($fixture['occupied'], '2026-07-12T08:30:00Z'));
        $this->assertSame(0, $calculator->roomsRunningAt($fixture['occupied'], '2026-07-12T09:20:00Z'));
        $this->assertSame(100, $calculator->utilizationPercent(800, 600, 100));
        $this->assertSame(SuiteMetricCalculator::class.'::utilizationPercent', $calculator->definitions()['utilization']['authority']);
    }
}
