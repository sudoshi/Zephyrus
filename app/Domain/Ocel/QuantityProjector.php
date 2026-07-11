<?php

namespace App\Domain\Ocel;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Derives the OCEL quantity extension (Part X §XO.3) DOWNSTREAM from the
 * projected ocel.* log — occupancy is a projection of a projection, so it can
 * never drift from the events the cockpit already shows. admit/place raise a
 * unit's occupancy by one; discharge/depart lower it by one. Initial occupancy
 * at the window floor is the net of those same events before the floor. Nothing
 * new is instrumented and prod.* is never read here. Unit-level counts only —
 * PHI-safe by construction.
 */
class QuantityProjector
{
    public const ITEM = 'occupied_beds';

    /** Activities that raise unit occupancy. */
    public const PLUS = ['admit', 'place', 'register'];

    /** Activities that lower unit occupancy. */
    public const MINUS = ['discharge', 'depart'];

    /**
     * Pure classification: occupancy events + initial occupancy → QEL tables.
     *
     * @param  array<int, array{event_id:string, activity:string, unit_id:?string, time:string}>  $events
     * @param  array<string, int>  $initialOccupancy  unit object id => occupancy at floor
     * @return array{initial: array<int, array{object_id:string,item_type:string,quantity:int}>, operations: array<int, array{event_id:string,object_id:string,item_type:string,delta:int,event_time:string}>}
     */
    public function computeQuantities(array $events, array $initialOccupancy): array
    {
        $operations = [];
        foreach ($events as $ev) {
            $delta = in_array($ev['activity'], self::PLUS, true) ? 1
                : (in_array($ev['activity'], self::MINUS, true) ? -1 : 0);

            if ($delta === 0 || empty($ev['unit_id'])) {
                continue;
            }

            $operations[] = [
                'event_id' => $ev['event_id'],
                'object_id' => $ev['unit_id'],
                'item_type' => self::ITEM,
                'delta' => $delta,
                'event_time' => $ev['time'],
            ];
        }

        $initial = [];
        foreach ($initialOccupancy as $unit => $qty) {
            $initial[] = ['object_id' => $unit, 'item_type' => self::ITEM, 'quantity' => (int) $qty];
        }

        return ['initial' => $initial, 'operations' => $operations];
    }

    /**
     * Project the QEL quantity extension for a window. Idempotent upsert.
     *
     * @return array{operations:int, initial:int}
     */
    public function project(?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        $since ??= Carbon::now()->subDays(90);
        $until ??= Carbon::now();

        $events = $this->readOccupancyEvents($since, $until);
        $initial = $this->initialOccupancy($since);

        $quantities = $this->computeQuantities($events, $initial);
        $this->flush($quantities);

        return ['operations' => count($quantities['operations']), 'initial' => count($quantities['initial'])];
    }

    /**
     * Occupancy-affecting events in the window: an admit/place/discharge/depart
     * touching a Unit object, read from the projected ocel.* log.
     *
     * @return array<int, array{event_id:string, activity:string, unit_id:string, time:string}>
     */
    private function readOccupancyEvents(CarbonInterface $since, CarbonInterface $until): array
    {
        $activities = array_merge(self::PLUS, self::MINUS);

        return DB::table('ocel.events as e')
            ->join('ocel.event_object as eo', 'eo.event_id', '=', 'e.id')
            ->join('ocel.objects as o', 'o.id', '=', 'eo.object_id')
            ->where('o.type', 'Unit')
            ->whereIn('e.activity', $activities)
            ->whereBetween('e.event_time', [$since, $until])
            ->orderBy('e.event_time')
            ->get(['e.id as event_id', 'e.activity', 'o.id as unit_id', 'e.event_time as time'])
            ->map(fn ($r) => [
                'event_id' => $r->event_id,
                'activity' => $r->activity,
                'unit_id' => $r->unit_id,
                'time' => \Carbon\Carbon::parse($r->time)->toIso8601String(),
            ])->all();
    }

    /**
     * Net occupancy per unit at the window floor = (admits before floor) −
     * (discharges before floor), clamped to ≥ 0. Pure log-derived; no external
     * capacity table needed.
     *
     * @return array<string, int>
     */
    private function initialOccupancy(CarbonInterface $since): array
    {
        $rows = DB::table('ocel.events as e')
            ->join('ocel.event_object as eo', 'eo.event_id', '=', 'e.id')
            ->join('ocel.objects as o', 'o.id', '=', 'eo.object_id')
            ->where('o.type', 'Unit')
            ->whereIn('e.activity', array_merge(self::PLUS, self::MINUS))
            ->where('e.event_time', '<', $since)
            ->get(['o.id as unit_id', 'e.activity']);

        $occupancy = [];
        foreach ($rows as $r) {
            $delta = in_array($r->activity, self::PLUS, true) ? 1 : -1;
            $occupancy[$r->unit_id] = ($occupancy[$r->unit_id] ?? 0) + $delta;
        }

        return array_map(fn ($n) => max(0, $n), $occupancy);
    }

    /** @param  array{initial: array, operations: array}  $quantities */
    private function flush(array $quantities): void
    {
        $now = Carbon::now();

        DB::transaction(function () use ($quantities, $now) {
            $initialRows = array_map(fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now], $quantities['initial']);
            foreach (array_chunk($initialRows, 500) as $chunk) {
                DB::table('ocel.object_quantities')->upsert($chunk, ['object_id', 'item_type'], ['quantity', 'updated_at']);
            }

            foreach (array_chunk($quantities['operations'], 500) as $chunk) {
                DB::table('ocel.quantity_operations')->upsert($chunk, ['event_id', 'object_id', 'item_type'], ['delta', 'event_time']);
            }
        });
    }
}
