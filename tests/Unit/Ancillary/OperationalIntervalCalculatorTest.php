<?php

namespace Tests\Unit\Ancillary;

use App\Services\Analytics\OperationalIntervalCalculator;
use InvalidArgumentException;
use Tests\TestCase;

class OperationalIntervalCalculatorTest extends TestCase
{
    public function test_interval_union_and_precedence_prevent_double_counting(): void
    {
        $result = (new OperationalIntervalCalculator)->calculate(
            available: [
                ['start' => '2026-07-12T08:00:00Z', 'end' => '2026-07-12T16:00:00Z'],
                ['start' => '2026-07-12T08:00:00Z', 'end' => '2026-07-12T12:00:00Z'],
            ],
            exams: [
                ['start' => '2026-07-12T09:00:00Z', 'end' => '2026-07-12T10:00:00Z'],
                ['start' => '2026-07-12T09:30:00Z', 'end' => '2026-07-12T11:00:00Z'],
            ],
            plannedDowntime: [['start' => '2026-07-12T10:30:00Z', 'end' => '2026-07-12T12:00:00Z']],
            unplannedDowntime: [['start' => '2026-07-12T11:30:00Z', 'end' => '2026-07-12T12:30:00Z']],
        );

        $this->assertSame(480, $result['availableMinutes']);
        $this->assertSame(90, $result['examMinutes']);
        $this->assertSame(60, $result['plannedDowntimeMinutes']);
        $this->assertSame(60, $result['unplannedDowntimeMinutes']);
        $this->assertSame(270, $result['idleMinutes']);
        $this->assertSame(0, $result['reconciliationDeltaMinutes']);
        $this->assertSame(['idle', 'exam', 'planned_downtime', 'unplanned_downtime', 'idle'], array_column($result['segments'], 'type'));
    }

    public function test_union_merges_adjacent_intervals_and_rejects_reversed_bounds(): void
    {
        $calculator = new OperationalIntervalCalculator;
        $union = $calculator->union([
            ['start' => '2026-07-12T08:00:00Z', 'end' => '2026-07-12T09:00:00Z'],
            ['start' => '2026-07-12T09:00:00Z', 'end' => '2026-07-12T10:00:00Z'],
        ]);
        $this->assertCount(1, $union);
        $this->assertSame('2026-07-12T08:00:00+00:00', $union[0]['start']->toAtomString());
        $this->assertSame('2026-07-12T10:00:00+00:00', $union[0]['end']->toAtomString());

        $this->expectException(InvalidArgumentException::class);
        $calculator->union([['start' => '2026-07-12T10:00:00Z', 'end' => '2026-07-12T09:00:00Z']]);
    }
}
