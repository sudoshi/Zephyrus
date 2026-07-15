<?php

namespace Tests\Feature\Demo;

use App\Services\Demo\DemoClock;
use App\Services\Demo\DemoInvariantService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoInvariantTemporalTest extends TestCase
{
    use RefreshDatabase;

    public function test_expected_discharge_date_is_compared_as_a_calendar_date(): void
    {
        $anchor = CarbonImmutable::parse('2026-07-12T20:00:00Z');
        $unitId = DB::table('prod.units')->insertGetId([
            'name' => 'Temporal invariant unit',
            'abbreviation' => 'TIU',
            'type' => 'med_surg',
            'is_deleted' => false,
            'created_at' => $anchor,
            'updated_at' => $anchor,
        ], 'unit_id');

        DB::table('prod.encounters')->insert([
            'patient_ref' => 'same-day-target',
            'unit_id' => $unitId,
            'status' => 'active',
            'admitted_at' => $anchor->subHours(8),
            'expected_discharge_date' => $anchor->toDateString(),
            'is_deleted' => false,
            'created_at' => $anchor,
            'updated_at' => $anchor,
        ]);

        $finding = collect(app(DemoInvariantService::class)->run(new DemoClock($anchor)))
            ->firstWhere('key', 'temporal.discharge_after_admit');

        $this->assertTrue($finding['passed']);
        $this->assertSame('0 encounters discharge-before-admit', $finding['observed']);

        DB::table('prod.encounters')->where('patient_ref', 'same-day-target')->update([
            'expected_discharge_date' => $anchor->subDay()->toDateString(),
        ]);

        $finding = collect(app(DemoInvariantService::class)->run(new DemoClock($anchor)))
            ->firstWhere('key', 'temporal.discharge_after_admit');

        $this->assertFalse($finding['passed']);
        $this->assertSame('1 encounters discharge-before-admit', $finding['observed']);
    }
}
