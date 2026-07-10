<?php

namespace Tests\Unit\PatientFlow;

use App\Services\PatientFlow\OccupancyInsightProjector;
use App\Support\Operations\DurationFormatter;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class OccupancyInsightProjectorDurationTest extends TestCase
{
    public function test_average_stay_rounds_once_at_the_whole_second_boundary(): void
    {
        $asOf = CarbonImmutable::parse('2026-07-09T16:00:00Z');
        $events = collect([3690, 3691])->map(fn (int $seconds, int $index): array => [
            'patient_id' => 'patient-'.$index,
            'event_type' => 'admit',
            'event_category' => 'movement',
            'occurred_at' => $asOf->subSeconds($seconds)->toISOString(),
            'to_location' => 'BED-1',
            'unit_code' => '7W',
            'service_line' => 'medicine',
        ])->all();
        $locations = [
            'BED-1' => [
                'name' => 'Bed 1',
                'unit_code' => '7W',
                'service_line' => 'medicine',
                'position_m' => ['x' => 0, 'y' => 0, 'z' => 0],
            ],
        ];

        $projection = app(OccupancyInsightProjector::class)->project(
            $events,
            $locations,
            [],
            $asOf->toISOString(),
            ['patient_dots' => 'deidentified'],
        );

        $this->assertSame('1 hr 1 min 31 sec', DurationFormatter::minutes($projection['summary']['avg_stay_minutes']));
        $this->assertSame('1 hr 1 min 31 sec', DurationFormatter::minutes($projection['summary']['service_lines'][0]['avg_stay_minutes']));
    }
}
