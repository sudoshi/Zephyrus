<?php

namespace App\Domain\Ocel;

use Illuminate\Support\Facades\DB;

/**
 * Exports the ocel.* quantity extension (Part X §XO.3) to the flat
 * {initial, operations} payload the Arena capacity sidecar consumes. Pure read;
 * the relational tables are the system of record. Unit-level counts only — PHI-safe.
 */
final class QuantityExporter
{
    /**
     * @return array{initial: array<int, array{object_id:string,item_type:string,quantity:int}>, operations: array<int, array{object_id:string,item_type:string,delta:int,event_time:string}>}
     */
    public function export(): array
    {
        $initial = DB::table('ocel.object_quantities')
            ->orderBy('object_id')
            ->get(['object_id', 'item_type', 'quantity'])
            ->map(fn ($r) => [
                'object_id' => $r->object_id,
                'item_type' => $r->item_type,
                'quantity' => (int) $r->quantity,
            ])->all();

        $operations = DB::table('ocel.quantity_operations')
            ->orderBy('event_time')
            ->get(['object_id', 'item_type', 'delta', 'event_time'])
            ->map(fn ($r) => [
                'object_id' => $r->object_id,
                'item_type' => $r->item_type,
                'delta' => (int) $r->delta,
                'event_time' => \Carbon\Carbon::parse($r->event_time)->toIso8601String(),
            ])->all();

        return ['initial' => $initial, 'operations' => $operations];
    }
}
