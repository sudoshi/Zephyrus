<?php

// tests/Feature/CommandCenterDataServiceTest.php

namespace Tests\Feature;

use App\Services\CommandCenterDataService;
use App\Services\CommandCenterDrilldownService;
use Tests\TestCase;

class CommandCenterDataServiceTest extends TestCase
{
    public function test_build_returns_all_top_level_keys(): void
    {
        $data = (new CommandCenterDataService)->build();

        foreach (['generatedAtIso', 'strain', 'heroMetrics', 'capacity', 'flow',
            'outcomes', 'forecast', 'forecastDetail', 'unitCensus', 'objectives'] as $key) {
            $this->assertArrayHasKey($key, $data, "missing key: {$key}");
        }
    }

    public function test_bands_have_correct_keys_and_flow_has_subgroups(): void
    {
        $data = (new CommandCenterDataService)->build();

        $this->assertSame('capacity', $data['capacity']['key']);
        $this->assertSame('flow', $data['flow']['key']);
        $this->assertSame('outcomes', $data['outcomes']['key']);
        $this->assertSame('forecast', $data['forecast']['key']);
        $this->assertArrayHasKey('subgroups', $data['flow']);
        $this->assertGreaterThanOrEqual(3, count($data['flow']['subgroups'])); // ED, IP, OR
    }

    public function test_strain_level_is_derived_from_drivers(): void
    {
        $data = (new CommandCenterDataService)->build();

        $this->assertIsInt($data['strain']['level']);
        $this->assertGreaterThanOrEqual(0, $data['strain']['level']);
        $this->assertLessThanOrEqual(4, $data['strain']['level']);
        $this->assertNotEmpty($data['strain']['drivers']);
    }

    public function test_hero_metrics_include_occupancy_and_net_beds(): void
    {
        $data = (new CommandCenterDataService)->build();
        $keys = array_column($data['heroMetrics'], 'key');

        $this->assertContains('occupancy', $keys);
        $this->assertContains('net_beds', $keys);

        $occupancy = collect($data['heroMetrics'])->firstWhere('key', 'occupancy');
        $this->assertArrayHasKey('sourceTrust', $occupancy);
        $this->assertArrayHasKey('score', $occupancy['sourceTrust']);
        $this->assertSame('/api/analytics/metrics/occupancy/lineage', $occupancy['lineageHref']);
    }

    public function test_every_kpi_metric_has_required_shape(): void
    {
        $data = (new CommandCenterDataService)->build();
        $required = ['key', 'label', 'value', 'unit', 'display', 'target',
            'targetDisplay', 'status', 'trajectory', 'drillHref', 'definition'];

        foreach ($data['heroMetrics'] as $m) {
            foreach ($required as $field) {
                $this->assertArrayHasKey($field, $m, "metric missing {$field}");
            }
            $this->assertContains($m['status'], ['critical', 'warning', 'success', 'info', 'neutral']);
        }
    }

    public function test_every_kpi_metric_has_ninety_day_trajectory(): void
    {
        $data = (new CommandCenterDataService)->build();

        foreach ($this->allMetrics($data) as $metric) {
            $this->assertIsArray($metric['trajectory'], "{$metric['key']} missing trajectory");
            $this->assertCount(90, $metric['trajectory']['points'], "{$metric['key']} must have 90 trend points");
        }
    }

    public function test_drilldown_payload_has_at_least_ninety_days_of_detail(): void
    {
        $data = app(CommandCenterDrilldownService::class)->build(requestedDays: 14);

        $this->assertSame(90, $data['window']['days']);
        $this->assertTrue($data['window']['synthetic']);
        $this->assertCount(90, $data['timeline']);
        $this->assertGreaterThanOrEqual(90, count($data['events']));

        foreach ($data['panels'] as $panel) {
            $this->assertCount(90, $panel['daily'], "{$panel['key']} panel must expose 90 daily rows");

            foreach ($panel['metrics'] as $metric) {
                $this->assertCount(90, $metric['history'], "{$metric['key']} metric must expose 90 daily rows");
            }
        }
    }

    public function test_drilldown_focus_resolves_metric_keys(): void
    {
        $data = app(CommandCenterDrilldownService::class)->build('metric:ed_lwbs');

        $this->assertSame('metric', $data['focus']['type']);
        $this->assertSame('ed_lwbs', $data['focus']['key']);
        $this->assertTrue($data['focus']['matched']);
    }

    public function test_excess_bed_days_are_not_reinterpreted_as_elapsed_hours(): void
    {
        $dashboard = (new CommandCenterDataService)->build();
        $metric = collect($dashboard['outcomes']['metrics'])->firstWhere('key', 'excess_days');

        $this->assertSame('bed-days', $metric['unit']);
        $this->assertStringEndsWith(' bed-days', $metric['display']);
        $this->assertStringNotContainsString(' hr', $metric['display']);

        $drilldown = app(CommandCenterDrilldownService::class)->build('metric:excess_days');
        $panel = collect($drilldown['panels'])->firstWhere('key', 'outcomes');
        $drillMetric = collect($panel['metrics'])->firstWhere('key', 'excess_days');

        $this->assertStringEndsWith(' bed-days', $drillMetric['history'][0]['display']);
        $this->assertStringNotContainsString(' hr', $drillMetric['history'][0]['display']);
    }

    /** @return list<array<string,mixed>> */
    private function allMetrics(array $data): array
    {
        $metrics = [
            ...$data['heroMetrics'],
            ...$data['capacity']['metrics'],
            ...$data['outcomes']['metrics'],
            ...$data['forecast']['metrics'],
        ];

        foreach ($data['flow']['subgroups'] as $group) {
            array_push($metrics, ...$group['metrics']);
        }

        return $metrics;
    }
}
