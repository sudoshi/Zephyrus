<?php

namespace App\Domain\Cockpit\Metrics;

use App\Domain\Cockpit\SnapshotContext;
use App\Support\Cockpit\MetricValue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Emergency Department (spec §2.3). LIVE except ed.nedocs — its inputs
 * (vent_pts / longest_admit_wait / last_bed_time) don't exist on
 * prod.ed_visits until P7, so NEDOCS is a seeded demo value (Part II.1 #7).
 *
 * D2P / LWBS / boarders / LOS-discharged come from the legacy payload;
 * in-department, waiting, and admitted-LOS are one COUNT-FILTER query and
 * one median; diversion is the DiversionEvent active convention; EMS
 * inbound counts active 'ems' transport requests.
 */
class EdMetrics extends BaseMetrics
{
    public function domain(): string
    {
        return 'ed';
    }

    /** @return list<MetricValue> */
    public function metrics(SnapshotContext $ctx): array
    {
        $counts = DB::selectOne(
            'SELECT
                 COUNT(*) FILTER (WHERE departed_at IS NULL) AS in_dept,
                 COUNT(*) FILTER (WHERE departed_at IS NULL AND provider_seen_at IS NULL) AS waiting
             FROM prod.ed_visits
             WHERE is_deleted = false
               AND arrived_at >= ?',
            [Carbon::now()->subHours(36)->toDateTimeString()]
        );

        $losAdmit = DB::selectOne(
            "SELECT CAST(percentile_cont(0.5) WITHIN GROUP (
                 ORDER BY EXTRACT(EPOCH FROM departed_at - arrived_at) / 60
             ) AS integer) AS med_min
             FROM prod.ed_visits
             WHERE disposition = 'admitted'
               AND departed_at IS NOT NULL
               AND arrived_at >= ?
               AND is_deleted = false",
            [Carbon::now()->subHours(24)->toDateTimeString()]
        );

        $activeDiversions = (int) DB::table('prod.diversion_events')
            ->where('is_deleted', false)
            ->where('started_at', '<', Carbon::now())
            ->whereNull('ended_at')
            ->count();

        // Same convention as TransportOperationsService::overview()'s
        // metrics.ems_inbound — the model's active() scope.
        $emsInbound = (int) \App\Models\Transport\TransportRequest::active()
            ->where('request_type', 'ems')
            ->count();

        $losAdmitMin = $losAdmit->med_min !== null ? (float) $losAdmit->med_min : null;

        return $this->compact([
            $this->demo($ctx, 'ed.nedocs', [
                'sub' => '142 of 200 — severe',
            ]),
            $this->fromKey($ctx, 'ed.in_dept', (float) ($counts->in_dept ?? 0)),
            $this->fromKey($ctx, 'ed.waiting', (float) ($counts->waiting ?? 0)),
            $this->fromLegacy($ctx, 'ed.door_to_provider', 'ed_d2p'),
            $this->fromLegacy($ctx, 'ed.lwbs', 'ed_lwbs'),
            $this->fromLegacy($ctx, 'ed.boarders', 'ed_boarding'),
            $losAdmitMin !== null
                ? $this->fromKey($ctx, 'ed.los_admit', $losAdmitMin)
                : $this->demo($ctx, 'ed.los_admit'),
            $this->fromLegacy($ctx, 'ed.los_discharge', 'ed_los'),
            $this->fromKey($ctx, 'ed.ems_inbound', (float) $emsInbound),
            $this->fromKey($ctx, 'ed.diversion', (float) $activeDiversions, [
                'display' => $activeDiversions > 0 ? 'ON' : 'OFF',
            ]),
        ]);
    }
}
