<?php

namespace Tests\Feature\Cockpit;

use App\Services\Cockpit\MetricTrendReader;
use App\Services\Cockpit\MetricValueWriter;
use App\Services\Cockpit\SnapshotBuilder;
use Database\Seeders\CockpitKpiDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Zephyrus 2.0 P7 (WS-6) — real sparklines from ops.metric_values history,
 * hourly-down-sampled, with the synthetic trend as the fallback until enough
 * history accrues.
 */
class MetricTrendReaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(SnapshotBuilder::CACHE_KEY);
        $this->seed(CockpitKpiDefinitionSeeder::class);
    }

    private function history(string $key, int $hoursAgo, float $value, int $minute = 0): void
    {
        DB::table('ops.metric_values')->insert([
            'metric_key' => $key,
            'measured_at' => now()->subHours($hoursAgo)->startOfHour()->addMinutes($minute),
            'grain' => MetricValueWriter::GRAIN,
            'value' => $value,
            'status' => 'normal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_recent_downsamples_to_one_point_per_hour_latest_wins(): void
    {
        // Two rows in the same hour → only the later (minute 50) survives.
        $this->history('ed.in_dept', 3, 10, 5);
        $this->history('ed.in_dept', 3, 14, 50);
        $this->history('ed.in_dept', 2, 18);
        $this->history('ed.in_dept', 1, 22);

        $trend = app(MetricTrendReader::class)->recent()['ed.in_dept'] ?? null;

        $this->assertSame([14.0, 18.0, 22.0], $trend); // chronological, hourly, latest-per-hour
    }

    public function test_history_outside_the_window_is_ignored(): void
    {
        $this->history('ed.in_dept', 40, 99); // older than the 18h window
        $this->history('ed.in_dept', 2, 12);
        $this->history('ed.in_dept', 1, 13);

        $trend = app(MetricTrendReader::class)->recent()['ed.in_dept'] ?? [];
        $this->assertNotContains(99.0, $trend);
    }

    public function test_snapshot_tile_uses_real_history_when_enough_points_exist(): void
    {
        foreach ([5, 4, 3, 2, 1] as $h) {
            $this->history('ed.in_dept', $h, 30 + $h);
        }

        $payload = app(SnapshotBuilder::class)->build();
        $tile = collect($payload['domains']['ed']['tiles'])->firstWhere('key', 'ed.in_dept');

        $this->assertSame([35.0, 34.0, 33.0, 32.0, 31.0], $tile['trend']);
    }

    public function test_too_little_history_falls_back_rather_than_showing_a_stub(): void
    {
        // Only 2 points — below MIN_POINTS, so no real trend is imposed.
        $this->history('ed.in_dept', 2, 12);
        $this->history('ed.in_dept', 1, 13);

        $payload = app(SnapshotBuilder::class)->build();
        $tile = collect($payload['domains']['ed']['tiles'])->firstWhere('key', 'ed.in_dept');

        // ed.in_dept has no legacy trajectory, so the fallback is an empty
        // trend — never the 2-point sliver.
        $this->assertNotSame([12.0, 13.0], $tile['trend']);
    }
}
