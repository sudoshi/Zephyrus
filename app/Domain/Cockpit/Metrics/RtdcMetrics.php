<?php

namespace App\Domain\Cockpit\Metrics;

use App\Domain\Cockpit\SnapshotContext;
use App\Support\Cockpit\MetricValue;
use Illuminate\Support\Facades\DB;

/**
 * RTDC house summary — the 8 census-strip keys (spec §2.2). LIVE.
 *
 * Occupancy / available / blocked / boarders / pending discharges read the
 * legacy payload (same values as /dashboard, bed-board fallback included —
 * Part II.3 drift pin #6). Census, ICU occupancy, and pending admits derive
 * from the payload's unitCensus rows plus one small count query.
 */
class RtdcMetrics extends BaseMetrics
{
    public function domain(): string
    {
        return 'rtdc';
    }

    /** @return list<MetricValue> */
    public function metrics(SnapshotContext $ctx): array
    {
        $units = $ctx->legacy['unitCensus'] ?? [];

        $occupied = (int) array_sum(array_column($units, 'occupied'));
        $staffed = (int) array_sum(array_column($units, 'staffed'));

        $icuUnits = array_values(array_filter(
            $units,
            fn (array $u): bool => str_contains(strtolower((string) ($u['type'] ?? '')), 'critical')
                || str_contains(strtolower((string) ($u['type'] ?? '')), 'icu'),
        ));
        $icuOccupied = (int) array_sum(array_column($icuUnits, 'occupied'));
        $icuStaffed = (int) array_sum(array_column($icuUnits, 'staffed'));
        $icuPct = $icuStaffed > 0 ? round(100.0 * $icuOccupied / $icuStaffed) : null;

        $pendingAdmits = (int) DB::table('prod.bed_requests')
            ->where('status', 'pending')
            ->where('is_deleted', false)
            ->count();

        return $this->compact([
            $this->fromKey($ctx, 'rtdc.census', (float) $occupied, [
                'sub' => "of {$staffed} staffed",
            ]),
            $this->fromLegacy($ctx, 'rtdc.available', 'available_beds'),
            $this->fromKey($ctx, 'rtdc.pending_admits', (float) $pendingAdmits),
            $this->fromLegacy($ctx, 'rtdc.pending_dc', 'dc_ready'),
            $this->fromLegacy($ctx, 'rtdc.boarders', 'ed_boarding'),
            $icuPct === null ? null : $this->fromKey($ctx, 'rtdc.icu_occupancy', $icuPct, [
                'sub' => "{$icuOccupied} of {$icuStaffed} ICU beds",
            ]),
            $this->fromLegacy($ctx, 'rtdc.blocked_beds', 'blocked_beds'),
            $this->fromLegacy($ctx, 'rtdc.occupancy', 'occupancy'),
        ]);
    }
}
