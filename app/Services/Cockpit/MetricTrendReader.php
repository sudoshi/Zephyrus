<?php

namespace App\Services\Cockpit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Zephyrus 2.0 P7 (WS-6) — real metric sparklines from the ops.metric_values
 * history the snapshot writer has appended every minute since P1, retiring the
 * synthetic crc32+sin/cos trajectories.
 *
 * The raw history is minute-grained (one row per snapshot), which reads as a
 * flat line over any useful span, so we DOWN-SAMPLE to one point per hour (the
 * latest value in each hour) over a trailing window — a legible multi-hour
 * trend. Providers fall back to their prior trend when a metric has too little
 * history to be meaningful yet, so this is strictly additive: real where it
 * can be, synthetic where it must be, until history accrues.
 */
class MetricTrendReader
{
    /** Trailing window to sample; one point per hour within it. */
    private const WINDOW_HOURS = 18;

    /** Cap the sparkline width (most recent points). */
    private const MAX_POINTS = 12;

    /** Below this many points a real trend isn't worth showing. */
    public const MIN_POINTS = 3;

    /**
     * @return array<string, list<float>> metric_key => chronological values
     */
    public function recent(): array
    {
        try {
            $rows = DB::select(
                'SELECT metric_key, value FROM (
                     SELECT metric_key, value, measured_at,
                            ROW_NUMBER() OVER (
                                PARTITION BY metric_key, date_trunc(\'hour\', measured_at)
                                ORDER BY measured_at DESC
                            ) AS rn
                       FROM ops.metric_values
                      WHERE grain = ? AND measured_at >= ?
                 ) t
                 WHERE rn = 1
                 ORDER BY metric_key, measured_at',
                [MetricValueWriter::GRAIN, Carbon::now()->subHours(self::WINDOW_HOURS)->toDateTimeString()]
            );
        } catch (\Throwable $e) {
            Log::warning('cockpit.trend.read_failed', ['error' => $e->getMessage()]);

            return [];
        }

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row->metric_key][] = (float) $row->value;
        }

        // Keep only the most recent MAX_POINTS per metric.
        foreach ($byKey as $key => $values) {
            if (count($values) > self::MAX_POINTS) {
                $byKey[$key] = array_slice($values, -self::MAX_POINTS);
            }
        }

        return $byKey;
    }
}
