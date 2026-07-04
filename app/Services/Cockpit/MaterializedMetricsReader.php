<?php

namespace App\Services\Cockpit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Zephyrus 2.0 P7 (WS-5) — reads the three MTD materialized views
 * (mv_hai_ledger / mv_service_line_los / mv_cost_center_productivity) as one
 * flat [metric_key => value] map for the Quality / Service / Financial
 * providers. The MVs are refreshed hourly by RefreshCockpitMaterializedViews;
 * this reader is a cheap point-read, never a recompute. Memoized for the life
 * of the request so all three providers share one pass.
 */
class MaterializedMetricsReader
{
    private const VIEWS = [
        'ops.mv_hai_ledger',
        'ops.mv_service_line_los',
        'ops.mv_cost_center_productivity',
    ];

    /** @var array<string, float>|null */
    private ?array $cache = null;

    public function value(string $metricKey): ?float
    {
        $map = $this->all();

        return $map[$metricKey] ?? null;
    }

    /** @return array<string, float> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $map = [];

        foreach (self::VIEWS as $view) {
            try {
                foreach (DB::table($view)->get(['metric_key', 'value']) as $row) {
                    if ($row->value !== null) {
                        $map[$row->metric_key] = (float) $row->value;
                    }
                }
            } catch (\Throwable $e) {
                // A missing/empty MV must never blank the snapshot — the domain
                // simply shows fewer tiles until the next refresh lands.
                Log::warning('cockpit.mv.read_failed', ['view' => $view, 'error' => $e->getMessage()]);
            }
        }

        return $this->cache = $map;
    }
}
