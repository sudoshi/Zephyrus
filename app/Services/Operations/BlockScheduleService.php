<?php

namespace App\Services\Operations;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the Block Schedule page payload from the live `prod` schema, matching
 * the exact shape consumed by resources/js/Pages/Operations/BlockSchedule.jsx.
 *
 * Sources: prod.block_templates, prod.block_utilization, prod.rooms,
 * prod.services, prod.providers.
 *
 * Output shape:
 *   [
 *     'metrics' => [
 *       'totalBlocks' => ['value' => int,    'trend' => int,   'trendLabel' => string],
 *       'utilization' => ['value' => string, 'trend' => float, 'trendLabel' => string],
 *       'released'    => ['value' => int,    'trend' => int,   'trendLabel' => string],
 *       'requests'    => ['value' => int,    'trend' => int,   'trendLabel' => string],
 *     ],
 *     'calendar' => [
 *       'rangeLabel' => string,
 *       'days' => list<['date' => string, 'label' => string, 'weekday' => string]>,
 *       'rows' => list<[
 *         'room' => string,
 *         'cells' => list<[
 *           'date' => string, 'hasBlock' => bool, 'service' => ?string,
 *           'surgeon' => ?string, 'timeRange' => ?string, 'utilization' => ?int,
 *           'casesScheduled' => ?int, 'casesPerformed' => ?int,
 *           'statusTier' => ?string, 'statusLabel' => ?string,
 *         ]>,
 *       ]>,
 *     ],
 *   ]
 *
 * Design notes:
 *  - Deterministic and safe on empty tables (returns plausible zeros + empty
 *    calendar, never throws).
 *  - Respects soft deletes (is_deleted = false) on every table.
 *  - Trends are derived by splitting the available block window chronologically
 *    (recent half vs earlier half) so the comparison arrow is meaningful
 *    without a hardcoded baseline.
 *  - "Released" = blocks whose utilization fell below the release threshold
 *    (underutilized capacity that would normally be released back to the pool).
 *  - "Requests" = derived pending-allocation demand: one request per released
 *    block plus one per fully-saturated block (>= saturation threshold), a
 *    deterministic proxy for outstanding block-time requests since the schema
 *    has no dedicated request ledger for block time.
 *  - Status tiers ration color (success/info/warning) by utilization and pair
 *    with a label so status is never conveyed by color alone.
 */
class BlockScheduleService
{
    /** Below this utilization a block is considered released (underutilized). */
    private const RELEASE_THRESHOLD = 70.0;

    /** At/above this utilization a block is saturated (drives request demand). */
    private const SATURATION_THRESHOLD = 90.0;

    /** @return array<string,mixed> */
    public function build(): array
    {
        $window = $this->dataWindow();

        return [
            'metrics' => $this->metrics($window),
            'calendar' => $this->calendar($window),
        ];
    }

    // -----------------------------------------------------------------------
    // Working window — anchor on the block_templates date span. Recent half is
    // the later portion of the span; prior half is the earlier portion. Empty
    // tables short-circuit every downstream query to zeros.
    // -----------------------------------------------------------------------

    /** @return array{min:?string,max:?string,split:?string,hasData:bool} */
    private function dataWindow(): array
    {
        $bounds = DB::table('prod.block_templates')
            ->where('is_deleted', false)
            ->selectRaw('MIN(block_date) AS min_d, MAX(block_date) AS max_d')
            ->first();

        $min = $bounds?->min_d ? Carbon::parse($bounds->min_d)->startOfDay() : null;
        $max = $bounds?->max_d ? Carbon::parse($bounds->max_d)->startOfDay() : null;

        if ($min === null || $max === null) {
            return ['min' => null, 'max' => null, 'split' => null, 'hasData' => false];
        }

        $spanDays = max(1, $min->diffInDays($max) + 1);
        $split = $max->copy()->subDays((int) floor($spanDays / 2))->startOfDay();

        return [
            'min' => $min->toDateString(),
            'max' => $max->toDateString(),
            'split' => $split->toDateString(),
            'hasData' => true,
        ];
    }

    // -----------------------------------------------------------------------
    // Metric cards
    // -----------------------------------------------------------------------

    /**
     * @param  array{min:?string,max:?string,split:?string,hasData:bool}  $w
     * @return array<string,mixed>
     */
    private function metrics(array $w): array
    {
        if (! $w['hasData']) {
            return [
                'totalBlocks' => ['value' => 0, 'trend' => 0, 'trendLabel' => 'vs. last period'],
                'utilization' => ['value' => '0%', 'trend' => 0, 'trendLabel' => 'vs. target'],
                'released' => ['value' => 0, 'trend' => 0, 'trendLabel' => 'vs. last period'],
                'requests' => ['value' => 0, 'trend' => 0, 'trendLabel' => 'pending'],
            ];
        }

        $now = $this->aggregate($w['split'], $w['max']);
        $prior = $this->aggregate($w['min'], $this->dayBefore($w['split']));

        $blocksTrend = (int) ($now->total_blocks - $prior->total_blocks);
        $utilTrend = round((float) $now->avg_util - (float) $prior->avg_util, 1);
        $releasedTrend = (int) ($now->released - $prior->released);
        $requestsTrend = (int) ($now->requests - $prior->requests);

        return [
            'totalBlocks' => [
                'value' => (int) $now->total_blocks_all,
                'trend' => $blocksTrend,
                'trendLabel' => 'vs. last period',
            ],
            'utilization' => [
                'value' => ((int) round((float) $now->avg_util_all)).'%',
                'trend' => $utilTrend,
                'trendLabel' => 'vs. target',
            ],
            'released' => [
                'value' => (int) $now->released_all,
                'trend' => $releasedTrend,
                'trendLabel' => 'vs. last period',
            ],
            'requests' => [
                'value' => (int) $now->requests_all,
                'trend' => $requestsTrend,
                'trendLabel' => 'pending',
            ],
        ];
    }

    /**
     * Aggregate block counts + utilization for a date range, plus the
     * whole-dataset ("_all") values used as the headline numbers. The headline
     * is dataset-wide so it is never an artificial half-window slice; the
     * windowed values feed the trend comparison only.
     *
     * @return object{total_blocks:int,avg_util:float,released:int,requests:int,total_blocks_all:int,avg_util_all:float,released_all:int,requests_all:int}
     */
    private function aggregate(?string $from, ?string $to): object
    {
        $zero = (object) [
            'total_blocks' => 0, 'avg_util' => 0.0, 'released' => 0, 'requests' => 0,
            'total_blocks_all' => 0, 'avg_util_all' => 0.0, 'released_all' => 0, 'requests_all' => 0,
        ];

        if ($from === null || $to === null) {
            return $zero;
        }

        $row = DB::selectOne(
            'SELECT
                 COUNT(*) FILTER (WHERE bt.block_date >= ? AND bt.block_date <= ?) AS total_blocks,
                 COALESCE(AVG(bu.utilization_percentage) FILTER (WHERE bt.block_date >= ? AND bt.block_date <= ?), 0) AS avg_util,
                 COUNT(*) FILTER (WHERE bt.block_date >= ? AND bt.block_date <= ? AND bu.utilization_percentage < ?::numeric) AS released,
                 COUNT(*) FILTER (WHERE bt.block_date >= ? AND bt.block_date <= ? AND (bu.utilization_percentage < ?::numeric OR bu.utilization_percentage >= ?::numeric)) AS requests,
                 COUNT(*) AS total_blocks_all,
                 COALESCE(AVG(bu.utilization_percentage), 0) AS avg_util_all,
                 COUNT(*) FILTER (WHERE bu.utilization_percentage < ?::numeric) AS released_all,
                 COUNT(*) FILTER (WHERE bu.utilization_percentage < ?::numeric OR bu.utilization_percentage >= ?::numeric) AS requests_all
             FROM prod.block_templates bt
             LEFT JOIN prod.block_utilization bu
                    ON bu.block_id = bt.block_id
                   AND bu.date = bt.block_date
                   AND bu.is_deleted = false
             WHERE bt.is_deleted = false',
            [
                $from, $to,                                                 // total_blocks
                $from, $to,                                                 // avg_util
                $from, $to, self::RELEASE_THRESHOLD,                          // released
                $from, $to, self::RELEASE_THRESHOLD, self::SATURATION_THRESHOLD, // requests
                self::RELEASE_THRESHOLD,                                      // released_all
                self::RELEASE_THRESHOLD, self::SATURATION_THRESHOLD,         // requests_all
            ]
        );

        if ($row === null) {
            return $zero;
        }

        return (object) [
            'total_blocks' => (int) $row->total_blocks,
            'avg_util' => (float) $row->avg_util,
            'released' => (int) $row->released,
            'requests' => (int) $row->requests,
            'total_blocks_all' => (int) $row->total_blocks_all,
            'avg_util_all' => (float) $row->avg_util_all,
            'released_all' => (int) $row->released_all,
            'requests_all' => (int) $row->requests_all,
        ];
    }

    // -----------------------------------------------------------------------
    // Calendar grid — rooms (rows) x dates (columns)
    // -----------------------------------------------------------------------

    /**
     * @param  array{min:?string,max:?string,split:?string,hasData:bool}  $w
     * @return array{rangeLabel:string,days:list<array<string,string>>,rows:list<array<string,mixed>>}
     */
    private function calendar(array $w): array
    {
        if (! $w['hasData']) {
            return ['rangeLabel' => '', 'days' => [], 'rows' => []];
        }

        $blocks = DB::select(
            'SELECT bt.block_date AS d,
                    r.name AS room,
                    s.name AS service,
                    p.name AS surgeon,
                    to_char(bt.start_time, \'HH24:MI\') AS start_t,
                    to_char(bt.end_time, \'HH24:MI\') AS end_t,
                    bu.utilization_percentage AS util,
                    bu.cases_scheduled AS cases_sched,
                    bu.cases_performed AS cases_perf
             FROM prod.block_templates bt
             JOIN prod.rooms r ON r.room_id = bt.room_id AND r.is_deleted = false
             JOIN prod.services s ON s.service_id = bt.service_id AND s.is_deleted = false
             LEFT JOIN prod.providers p ON p.provider_id = bt.surgeon_id AND p.is_deleted = false
             LEFT JOIN prod.block_utilization bu
                    ON bu.block_id = bt.block_id
                   AND bu.date = bt.block_date
                   AND bu.is_deleted = false
             WHERE bt.is_deleted = false
             ORDER BY bt.block_date ASC, r.name ASC',
            []
        );

        // Collect the distinct ordered set of dates and rooms.
        $dayMap = [];   // date string => true
        $roomMap = [];  // room name => true
        $byCell = [];   // "room|date" => block payload
        foreach ($blocks as $b) {
            $date = Carbon::parse($b->d)->toDateString();
            $dayMap[$date] = true;
            $roomMap[(string) $b->room] = true;

            $util = $b->util !== null ? (int) round((float) $b->util) : null;
            [$tier, $tierLabel] = $this->statusTier($util);

            $byCell[$b->room.'|'.$date] = [
                'date' => $date,
                'hasBlock' => true,
                'service' => (string) $b->service,
                'surgeon' => $b->surgeon !== null ? (string) $b->surgeon : null,
                'timeRange' => $b->start_t.'–'.$b->end_t,
                'utilization' => $util,
                'casesScheduled' => $b->cases_sched !== null ? (int) $b->cases_sched : null,
                'casesPerformed' => $b->cases_perf !== null ? (int) $b->cases_perf : null,
                'statusTier' => $tier,
                'statusLabel' => $tierLabel,
            ];
        }

        $dates = array_keys($dayMap);
        sort($dates);
        $rooms = array_keys($roomMap);
        sort($rooms);

        $days = array_map(
            static function (string $date): array {
                $c = Carbon::parse($date);

                return [
                    'date' => $date,
                    'label' => $c->format('M j'),
                    'weekday' => $c->format('D'),
                ];
            },
            $dates
        );

        $rows = [];
        foreach ($rooms as $room) {
            $cells = [];
            foreach ($dates as $date) {
                $cells[] = $byCell[$room.'|'.$date] ?? [
                    'date' => $date,
                    'hasBlock' => false,
                    'service' => null,
                    'surgeon' => null,
                    'timeRange' => null,
                    'utilization' => null,
                    'casesScheduled' => null,
                    'casesPerformed' => null,
                    'statusTier' => null,
                    'statusLabel' => null,
                ];
            }
            $rows[] = ['room' => $room, 'cells' => $cells];
        }

        $rangeLabel = '';
        if ($dates !== []) {
            $first = Carbon::parse($dates[0]);
            $last = Carbon::parse($dates[count($dates) - 1]);
            $rangeLabel = $first->isSameDay($last)
                ? $first->format('M j, Y')
                : $first->format('M j').' – '.$last->format('M j, Y');
        }

        return ['rangeLabel' => $rangeLabel, 'days' => $days, 'rows' => $rows];
    }

    /**
     * Ration a status tier (+ paired label) by block utilization. Color is
     * always accompanied by a label so status is never color-alone.
     *
     * @return array{0:?string,1:?string} [tier, label]
     */
    private function statusTier(?int $util): array
    {
        if ($util === null) {
            return [null, null];
        }
        if ($util >= 85) {
            return ['success', 'Optimal'];
        }
        if ($util >= self::RELEASE_THRESHOLD) {
            return ['info', 'On track'];
        }

        return ['warning', 'Underutilized'];
    }

    private function dayBefore(?string $date): ?string
    {
        return $date === null ? null : Carbon::parse($date)->subDay()->toDateString();
    }
}
