<?php

namespace Tests\Unit\Cockpit;

use App\Enums\CockpitStatus;
use App\Models\Ops\MetricDefinition;
use App\Services\Cockpit\StatusEngine;
use App\Support\Cockpit\MetricValue;
use PHPUnit\Framework\TestCase;

class MetricValueTest extends TestCase
{
    private function nedocsDefinition(): MetricDefinition
    {
        return new MetricDefinition([
            'metric_key' => 'ed.nedocs',
            'label' => 'NEDOCS',
            'unit' => null,
            'direction' => 'down',
            'warn_edge' => 101,
            'crit_edge' => 141,
            'target_value' => 60,
        ]);
    }

    public function test_from_definition_resolves_status_through_the_engine_once(): void
    {
        $metric = MetricValue::fromDefinition(142.0, $this->nedocsDefinition(), new StatusEngine, [
            'updatedAt' => '2026-07-03T12:00:00+00:00',
        ]);

        $this->assertSame('ed.nedocs', $metric->key);
        $this->assertSame(CockpitStatus::CRIT, $metric->status);
        $this->assertSame(142.0, $metric->value);
        $this->assertSame(60.0, $metric->target);
        $this->assertSame('down', $metric->direction);
    }

    public function test_server_data_quality_override_can_only_neutralize_a_last_known_value_explicitly(): void
    {
        $metric = MetricValue::fromDefinition(142.0, $this->nedocsDefinition(), new StatusEngine, [
            'status' => CockpitStatus::NORMAL,
            'sub' => 'Last known · stale',
            'metadata' => ['dataState' => 'degraded', 'sourceState' => 'stale'],
        ]);

        $this->assertSame(CockpitStatus::NORMAL, $metric->status);
        $this->assertSame(142.0, $metric->value);
        $this->assertSame('Last known · stale', $metric->sub);
        $this->assertSame('degraded', $metric->metadata['dataState']);
    }

    public function test_to_array_emits_the_spec_wire_shape_with_the_logical_status_name(): void
    {
        $metric = MetricValue::fromDefinition(142.0, $this->nedocsDefinition(), new StatusEngine, [
            'sub' => 'Severe overcrowding',
            'trend' => [120, 131, 142],
            'trendLabel' => '4h',
            'updatedAt' => '2026-07-03T12:00:00+00:00',
        ]);

        $this->assertSame([
            'key' => 'ed.nedocs',
            'label' => 'NEDOCS',
            'value' => 142.0,
            'display' => '142',
            'unit' => null,
            'sub' => 'Severe overcrowding',
            'status' => 'crit',
            'target' => 60.0,
            'direction' => 'down',
            'trend' => [120, 131, 142],
            'trendLabel' => '4h',
            'updatedAt' => '2026-07-03T12:00:00+00:00',
        ], $metric->toArray());
    }

    public function test_metadata_is_omitted_when_empty_and_carried_when_set(): void
    {
        $engine = new StatusEngine;
        $bare = MetricValue::fromDefinition(50.0, $this->nedocsDefinition(), $engine, [
            'updatedAt' => '2026-07-03T12:00:00+00:00',
        ]);
        $demo = MetricValue::fromDefinition(50.0, $this->nedocsDefinition(), $engine, [
            'updatedAt' => '2026-07-03T12:00:00+00:00',
            'metadata' => ['provenance' => 'demo'],
        ]);

        // json_encode([]) would emit [] not {} and fail the Zod record schema.
        $this->assertArrayNotHasKey('metadata', $bare->toArray());
        $this->assertSame(['provenance' => 'demo'], $demo->toArray()['metadata']);
    }

    public function test_default_display_formats_percent_units_and_trims_decimals(): void
    {
        $pctDef = new MetricDefinition([
            'metric_key' => 'rtdc.occupancy',
            'label' => 'Occupancy',
            'unit' => '%',
            'direction' => 'down',
            'warn_edge' => 90,
            'crit_edge' => 95,
        ]);
        $engine = new StatusEngine;

        $this->assertSame('88%', MetricValue::fromDefinition(88.0, $pctDef, $engine)->display);
        $this->assertSame('88.5%', MetricValue::fromDefinition(88.5, $pctDef, $engine)->display);
        $this->assertSame('1,250', MetricValue::fromDefinition(1250.0, $this->nedocsDefinition(), $engine)->display);
    }

    public function test_default_display_decomposes_elapsed_time_units(): void
    {
        $engine = new StatusEngine;

        foreach ([
            ['unit' => 'min', 'value' => 90.525, 'expected' => '1 hr 30 min 32 sec'],
            ['unit' => 'hours', 'value' => 1.525, 'expected' => '1 hr 31 min 30 sec'],
            ['unit' => 'seconds', 'value' => 90.5, 'expected' => '1 min 31 sec'],
        ] as $case) {
            $definition = new MetricDefinition([
                'metric_key' => 'duration.test',
                'label' => 'Duration',
                'unit' => $case['unit'],
                'direction' => 'down',
                'warn_edge' => null,
                'crit_edge' => null,
            ]);

            $this->assertSame(
                $case['expected'],
                MetricValue::fromDefinition($case['value'], $definition, $engine)->display,
            );
        }
    }

    public function test_avoidable_days_are_preserved_as_a_compound_bed_day_measure(): void
    {
        $definition = new MetricDefinition([
            'metric_key' => 'service.avoidable_days',
            'label' => 'Avoidable days',
            'unit' => 'days',
            'direction' => 'down',
            'warn_edge' => 101,
            'crit_edge' => 200,
        ]);

        $metric = MetricValue::fromDefinition(101.0, $definition, new StatusEngine);

        $this->assertSame('101 bed-days', $metric->display);
        $this->assertSame('bed-days', $metric->unit);
    }
}
