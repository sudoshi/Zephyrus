<?php

namespace App\Services\Cockpit;

use App\Support\Cockpit\MetricValue;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writes each snapshot's computed scalars into the EXISTING ops.metric_values
 * trust table (Zephyrus 2.0 P1) — this is the history that retires the
 * synthetic crc32 sparklines and feeds MetricLineageService freshness.
 *
 * Retention decision (plan P1 execution notes): the writer and the pruner
 * ship together — grain='snapshot' rows older than the configured retention
 * (default 90 days, aligned to TREND_DAYS) are deleted daily. No other grain
 * is ever pruned by this class.
 */
class MetricValueWriter
{
    public const GRAIN = 'snapshot';

    private const PRUNE_BATCH = 10000;

    /**
     * @param  array<string, MetricValue>  $values  keyed by metric_key
     * @param  Collection<string, \App\Models\Ops\MetricDefinition>  $definitions  keyed by metric_key
     * @return int rows written
     */
    public function write(array $values, Collection $definitions, CarbonInterface $measuredAt): int
    {
        if ($values === []) {
            return 0;
        }

        $now = now();
        $rows = [];

        foreach ($values as $value) {
            $row = [
                'metric_definition_id' => $definitions->get($value->key)?->metric_definition_id,
                'metric_key' => $value->key,
                'measured_at' => $measuredAt,
                'grain' => self::GRAIN,
                'value' => $value->value,
                'display' => mb_substr($value->display, 0, 80),
                'status' => $value->status->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($value->metadata !== []) {
                $row['metadata'] = json_encode($value->metadata);
            }

            $rows[] = $row;
        }

        // Two shapes (with/without metadata) can't share one multi-row
        // insert; chunk by identical key sets.
        foreach (collect($rows)->groupBy(fn (array $row): string => implode(',', array_keys($row))) as $group) {
            DB::table('ops.metric_values')->insert($group->all());
        }

        return count($rows);
    }

    /** @return int rows deleted */
    public function prune(): int
    {
        $retentionDays = max(1, (int) config('cockpit.metric_values_retention_days', 90));
        $cutoff = now()->subDays($retentionDays);
        $deleted = 0;

        // PG has no DELETE ... LIMIT — chunk through a keyed subquery so a
        // large backlog never holds one long transaction.
        do {
            $affected = DB::affectingStatement(
                'DELETE FROM ops.metric_values
                 WHERE metric_value_id IN (
                     SELECT metric_value_id FROM ops.metric_values
                     WHERE grain = ? AND measured_at < ?
                     LIMIT ?
                 )',
                [self::GRAIN, $cutoff->toDateTimeString(), self::PRUNE_BATCH]
            );
            $deleted += $affected;
        } while ($affected === self::PRUNE_BATCH);

        return $deleted;
    }
}
