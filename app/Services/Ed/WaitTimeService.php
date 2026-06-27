<?php

declare(strict_types=1);

namespace App\Services\Ed;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the ED Wait Time analytics payload from the live `prod.ed_visits` table.
 *
 * Surfaces the three classic ED interval distributions — door-to-provider,
 * door-to-disposition, and total length of stay — with median / p90 summaries,
 * a per-ESI breakdown table, and an hourly door-to-provider trend across the
 * analysis window. Query idioms mirror App\Services\Dashboard\EdDashboardService
 * (percentile_cont WITHIN GROUP, FILTER, EXTRACT EPOCH / 60). Deterministic and
 * safe on empty tables (returns zeros / empty arrays, never throws). Enrichment
 * with no source system (none is required here — all intervals are real
 * timestamps) is avoided; only the configured targets are static.
 */
class WaitTimeService
{
    /** Rolling analysis window, in hours, anchored to wall-clock now. */
    private const WINDOW_HOURS = 24;

    /** Performance targets (configured, not live-derived) in minutes. */
    private const TARGET_DOOR_TO_PROVIDER = 30;

    private const TARGET_DOOR_TO_DISPOSITION = 150;

    private const TARGET_LOS = 180;

    /**
     * ESI labels rendered in the per-acuity breakdown table.
     *
     * @var array<int,string>
     */
    private const ESI_LABELS = [
        1 => 'ESI 1 — Resuscitation',
        2 => 'ESI 2 — Emergent',
        3 => 'ESI 3 — Urgent',
        4 => 'ESI 4 — Less Urgent',
        5 => 'ESI 5 — Non-Urgent',
    ];

    /**
     * Assemble the full Wait Time analytics payload.
     *
     * @return array{
     *     window:array{hours:int,from:string,to:string,visitCount:int},
     *     kpis:array<string,array{value:int,target:int,trend:string,trendValue:int,withinTarget:bool}>,
     *     distributions:array<string,array{median:int,p90:int,target:int,n:int}>,
     *     byEsi:list<array{esi:int,label:string,n:int,doorToProvider:int,doorToDisposition:int,los:int}>,
     *     trend:list<array{hour:string,doorToProvider:int,doorToDisposition:int}>
     * }
     */
    public function build(): array
    {
        $now = Carbon::now();
        $from = $now->copy()->subHours(self::WINDOW_HOURS);

        $summary = $this->intervalSummary($from, $now);
        $byEsi = $this->byEsi($from, $now);
        $trend = $this->hourlyTrend($from, $now);
        $visitCount = $this->visitCount($from, $now);

        $d2p = $summary['door_to_provider'];
        $d2disp = $summary['door_to_disposition'];
        $los = $summary['los'];

        return [
            'window' => [
                'hours' => self::WINDOW_HOURS,
                'from' => $from->toIso8601String(),
                'to' => $now->toIso8601String(),
                'visitCount' => $visitCount,
            ],
            'kpis' => [
                'doorToProvider' => $this->kpi($d2p['median'], self::TARGET_DOOR_TO_PROVIDER),
                'doorToDisposition' => $this->kpi($d2disp['median'], self::TARGET_DOOR_TO_DISPOSITION),
                'lengthOfStay' => $this->kpi($los['median'], self::TARGET_LOS),
                'p90DoorToProvider' => $this->kpi($d2p['p90'], self::TARGET_DOOR_TO_PROVIDER),
            ],
            'distributions' => [
                'doorToProvider' => [
                    'median' => $d2p['median'],
                    'p90' => $d2p['p90'],
                    'target' => self::TARGET_DOOR_TO_PROVIDER,
                    'n' => $d2p['n'],
                ],
                'doorToDisposition' => [
                    'median' => $d2disp['median'],
                    'p90' => $d2disp['p90'],
                    'target' => self::TARGET_DOOR_TO_DISPOSITION,
                    'n' => $d2disp['n'],
                ],
                'lengthOfStay' => [
                    'median' => $los['median'],
                    'p90' => $los['p90'],
                    'target' => self::TARGET_LOS,
                    'n' => $los['n'],
                ],
            ],
            'byEsi' => $byEsi,
            'trend' => $trend,
        ];
    }

    // -----------------------------------------------------------------------
    // Interval summaries (median + p90 over the window)
    // -----------------------------------------------------------------------

    /**
     * Median + p90 (minutes) for the three intervals over the window.
     *
     * @return array{
     *     door_to_provider:array{median:int,p90:int,n:int},
     *     door_to_disposition:array{median:int,p90:int,n:int},
     *     los:array{median:int,p90:int,n:int}
     * }
     */
    private function intervalSummary(Carbon $from, Carbon $to): array
    {
        $row = DB::selectOne(
            'SELECT
                 CAST(percentile_cont(0.5) WITHIN GROUP (
                     ORDER BY EXTRACT(EPOCH FROM provider_seen_at - arrived_at) / 60
                 ) FILTER (WHERE provider_seen_at IS NOT NULL) AS integer) AS d2p_med,
                 CAST(percentile_cont(0.9) WITHIN GROUP (
                     ORDER BY EXTRACT(EPOCH FROM provider_seen_at - arrived_at) / 60
                 ) FILTER (WHERE provider_seen_at IS NOT NULL) AS integer) AS d2p_p90,
                 COUNT(*) FILTER (WHERE provider_seen_at IS NOT NULL) AS d2p_n,
                 CAST(percentile_cont(0.5) WITHIN GROUP (
                     ORDER BY EXTRACT(EPOCH FROM admit_decision_at - arrived_at) / 60
                 ) FILTER (WHERE admit_decision_at IS NOT NULL) AS integer) AS d2d_med,
                 CAST(percentile_cont(0.9) WITHIN GROUP (
                     ORDER BY EXTRACT(EPOCH FROM admit_decision_at - arrived_at) / 60
                 ) FILTER (WHERE admit_decision_at IS NOT NULL) AS integer) AS d2d_p90,
                 COUNT(*) FILTER (WHERE admit_decision_at IS NOT NULL) AS d2d_n,
                 CAST(percentile_cont(0.5) WITHIN GROUP (
                     ORDER BY EXTRACT(EPOCH FROM departed_at - arrived_at) / 60
                 ) FILTER (WHERE departed_at IS NOT NULL) AS integer) AS los_med,
                 CAST(percentile_cont(0.9) WITHIN GROUP (
                     ORDER BY EXTRACT(EPOCH FROM departed_at - arrived_at) / 60
                 ) FILTER (WHERE departed_at IS NOT NULL) AS integer) AS los_p90,
                 COUNT(*) FILTER (WHERE departed_at IS NOT NULL) AS los_n
             FROM prod.ed_visits
             WHERE is_deleted = false
               AND arrived_at >= ?
               AND arrived_at <= ?',
            [$from->toDateTimeString(), $to->toDateTimeString()]
        );

        return [
            'door_to_provider' => [
                'median' => (int) ($row->d2p_med ?? 0),
                'p90' => (int) ($row->d2p_p90 ?? 0),
                'n' => (int) ($row->d2p_n ?? 0),
            ],
            'door_to_disposition' => [
                'median' => (int) ($row->d2d_med ?? 0),
                'p90' => (int) ($row->d2d_p90 ?? 0),
                'n' => (int) ($row->d2d_n ?? 0),
            ],
            'los' => [
                'median' => (int) ($row->los_med ?? 0),
                'p90' => (int) ($row->los_p90 ?? 0),
                'n' => (int) ($row->los_n ?? 0),
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Per-ESI breakdown
    // -----------------------------------------------------------------------

    /**
     * Median intervals (minutes) broken out by ESI acuity level. Every ESI 1-5
     * row is present (zero-filled when no visits at that acuity in the window),
     * so the table renders deterministically.
     *
     * @return list<array{esi:int,label:string,n:int,doorToProvider:int,doorToDisposition:int,los:int}>
     */
    private function byEsi(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->whereNotNull('esi_level')
            ->whereBetween('arrived_at', [$from, $to])
            ->selectRaw(
                'esi_level,
                 COUNT(*) AS n,
                 CAST(percentile_cont(0.5) WITHIN GROUP (
                     ORDER BY EXTRACT(EPOCH FROM provider_seen_at - arrived_at) / 60
                 ) FILTER (WHERE provider_seen_at IS NOT NULL) AS integer) AS d2p,
                 CAST(percentile_cont(0.5) WITHIN GROUP (
                     ORDER BY EXTRACT(EPOCH FROM admit_decision_at - arrived_at) / 60
                 ) FILTER (WHERE admit_decision_at IS NOT NULL) AS integer) AS d2d,
                 CAST(percentile_cont(0.5) WITHIN GROUP (
                     ORDER BY EXTRACT(EPOCH FROM departed_at - arrived_at) / 60
                 ) FILTER (WHERE departed_at IS NOT NULL) AS integer) AS los'
            )
            ->groupBy('esi_level')
            ->get()
            ->keyBy('esi_level');

        $out = [];
        foreach (self::ESI_LABELS as $esi => $label) {
            $r = $rows->get($esi);
            $out[] = [
                'esi' => $esi,
                'label' => $label,
                'n' => (int) ($r->n ?? 0),
                'doorToProvider' => (int) ($r->d2p ?? 0),
                'doorToDisposition' => (int) ($r->d2d ?? 0),
                'los' => (int) ($r->los ?? 0),
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Hourly trend (door-to-provider + door-to-disposition by arrival hour)
    // -----------------------------------------------------------------------

    /**
     * Average door-to-provider and door-to-disposition (minutes) bucketed into
     * the clock-hours spanned by the window, ordered chronologically. Hours with
     * arrivals but no completed interval carry a 0 for that metric so the line is
     * continuous; the bucket key is a short clock label (e.g. "08:00").
     *
     * @return list<array{hour:string,doorToProvider:int,doorToDisposition:int}>
     */
    private function hourlyTrend(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->whereBetween('arrived_at', [$from, $to])
            ->selectRaw(
                "to_char(date_trunc('hour', arrived_at), 'YYYY-MM-DD HH24:00') AS bucket,
                 EXTRACT(HOUR FROM arrived_at)::int AS hr,
                 CAST(AVG(EXTRACT(EPOCH FROM provider_seen_at - arrived_at) / 60)
                     FILTER (WHERE provider_seen_at IS NOT NULL) AS integer) AS avg_d2p,
                 CAST(AVG(EXTRACT(EPOCH FROM admit_decision_at - arrived_at) / 60)
                     FILTER (WHERE admit_decision_at IS NOT NULL) AS integer) AS avg_d2d"
            )
            ->groupBy('bucket', 'hr')
            ->orderBy('bucket')
            ->get();

        $trend = [];
        foreach ($rows as $row) {
            $trend[] = [
                'hour' => sprintf('%02d:00', (int) $row->hr),
                'doorToProvider' => (int) ($row->avg_d2p ?? 0),
                'doorToDisposition' => (int) ($row->avg_d2d ?? 0),
            ];
        }

        return $trend;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function visitCount(Carbon $from, Carbon $to): int
    {
        return (int) DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->whereBetween('arrived_at', [$from, $to])
            ->count();
    }

    /**
     * Build a KPI tile descriptor against a lower-is-better target. Trend is
     * 'up' (bad) when current exceeds target, 'down' (good) when at/under.
     *
     * @return array{value:int,target:int,trend:string,trendValue:int,withinTarget:bool}
     */
    private function kpi(int $value, int $target): array
    {
        $withinTarget = $value <= $target;

        return [
            'value' => $value,
            'target' => $target,
            'trend' => $withinTarget ? 'down' : 'up',
            'trendValue' => abs($value - $target),
            'withinTarget' => $withinTarget,
        ];
    }
}
