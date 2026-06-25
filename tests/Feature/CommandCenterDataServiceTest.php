<?php

// tests/Feature/CommandCenterDataServiceTest.php

namespace Tests\Feature;

use App\Services\CommandCenterDataService;
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
