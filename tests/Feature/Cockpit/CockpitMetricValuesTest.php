<?php

namespace Tests\Feature\Cockpit;

use App\Services\Cockpit\MetricValueWriter;
use App\Services\Cockpit\SnapshotBuilder;
use Database\Seeders\CockpitKpiDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Zephyrus 2.0 P1 — the ops.metric_values writer + its 90-day retention
 * prune (they ship together per the plan's execution notes). Only
 * grain='snapshot' rows are ever pruned.
 */
class CockpitMetricValuesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(SnapshotBuilder::CACHE_KEY);
        $this->seed(CockpitKpiDefinitionSeeder::class);
    }

    public function test_refresh_appends_snapshot_scalars_with_definition_linkage(): void
    {
        app(SnapshotBuilder::class)->refresh();

        $rows = DB::table('ops.metric_values')->where('grain', MetricValueWriter::GRAIN)->get();
        $this->assertGreaterThan(20, $rows->count());

        // ed.nedocs is live as of P7 — assert it's written with its definition
        // linkage (its value depends on live ED census, not a fixed constant).
        $nedocs = $rows->firstWhere('metric_key', 'ed.nedocs');
        $this->assertNotNull($nedocs);
        $this->assertNotNull($nedocs->metric_definition_id);

        // A still-demo metric carries provenance through to the history row.
        $handHygiene = $rows->firstWhere('metric_key', 'quality.hand_hygiene');
        $this->assertNotNull($handHygiene);
        $this->assertSame('demo', json_decode($handHygiene->metadata ?? '{}', true)['provenance'] ?? null);

        // A second refresh appends history, never replaces it.
        app(SnapshotBuilder::class)->refresh();
        $this->assertSame(
            $rows->count() * 2,
            DB::table('ops.metric_values')->where('grain', MetricValueWriter::GRAIN)->count(),
        );
    }

    public function test_prune_deletes_only_expired_snapshot_grain_rows(): void
    {
        $writer = app(MetricValueWriter::class);

        $insert = fn (string $key, string $grain, int $daysAgo) => DB::table('ops.metric_values')->insert([
            'metric_key' => $key,
            'measured_at' => now()->subDays($daysAgo),
            'grain' => $grain,
            'value' => 1,
            'status' => 'normal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $insert('rtdc.occupancy', 'snapshot', 91);   // expired snapshot → pruned
        $insert('rtdc.occupancy', 'snapshot', 89);   // inside retention → kept
        $insert('rtdc.occupancy', 'daily', 400);     // other grain → NEVER pruned

        $deleted = $writer->prune();

        $this->assertSame(1, $deleted);
        $this->assertSame(
            1,
            DB::table('ops.metric_values')->where('grain', 'snapshot')->count(),
        );
        $this->assertSame(
            1,
            DB::table('ops.metric_values')->where('grain', 'daily')->count(),
        );
    }
}
