<?php

namespace App\Domain\Ocel;

use Illuminate\Support\Facades\DB;

/**
 * Exports the relational ocel.* store to the canonical OCEL 2.0 JSON interchange
 * shape (Part X §X.2 / §X.3.1) — the format `pm4py.read_ocel2_json` and `ocpa`
 * consume directly. The relational store is the system of record; JSON is a
 * disposable, lossless export (§X.2 risk "standard drift").
 *
 * Event attributes are single-valued (the event is at one time); object
 * attributes are time-varying (name/time/value) — the OCEL 2.0 feature that
 * carries bed status and OR phase. Non-scalar attribute values (clinical code
 * arrays) are JSON-encoded to strings so the export stays within the OCEL 2.0
 * scalar-attribute contract.
 */
final class OcelJsonExporter
{
    /**
     * Build the OCEL 2.0 JSON document as a PHP array.
     *
     * @return array{objectTypes: array, eventTypes: array, objects: array, events: array}
     */
    public function export(): array
    {
        $floor = DB::table('ocel.events')->min('event_time') ?? now()->toIso8601String();

        $e2o = DB::table('ocel.event_object')->get()->groupBy('event_id');
        $o2o = DB::table('ocel.object_object')->get()->groupBy('from_id');
        $changes = DB::table('ocel.object_changes')->orderBy('changed_at')->get()->groupBy('object_id');

        $events = [];
        $activityAttrKeys = [];
        foreach (DB::table('ocel.events')->orderBy('event_time')->get() as $ev) {
            $attrs = $this->decode($ev->attrs);
            foreach (array_keys($attrs) as $k) {
                $activityAttrKeys[$ev->activity][$k] = true;
            }
            $events[] = [
                'id' => $ev->id,
                'type' => $ev->activity,
                'time' => $this->iso($ev->event_time),
                'attributes' => $this->eventAttributes($attrs),
                'relationships' => ($e2o[$ev->id] ?? collect())->map(fn ($r) => [
                    'objectId' => $r->object_id,
                    'qualifier' => (string) $r->qualifier,
                ])->values()->all(),
            ];
        }

        $objects = [];
        $typeAttrKeys = [];
        foreach (DB::table('ocel.objects')->get() as $ob) {
            $static = $this->decode($ob->attrs);
            foreach (array_keys($static) as $k) {
                $typeAttrKeys[$ob->type][$k] = true;
            }
            $objects[] = [
                'id' => $ob->id,
                'type' => $ob->type,
                'attributes' => $this->objectAttributes($static, $changes[$ob->id] ?? collect(), $floor),
                'relationships' => ($o2o[$ob->id] ?? collect())->map(fn ($r) => [
                    'objectId' => $r->to_id,
                    'qualifier' => (string) $r->qualifier,
                ])->values()->all(),
            ];
        }

        $objectTypes = DB::table('ocel.object_types')->orderBy('type')->pluck('type')
            ->map(fn ($t) => ['name' => $t, 'attributes' => $this->declaredAttributes($typeAttrKeys[$t] ?? [])])
            ->all();

        $eventTypes = collect(array_keys($activityAttrKeys))->sort()->values()
            ->map(fn ($a) => ['name' => $a, 'attributes' => $this->declaredAttributes($activityAttrKeys[$a] ?? [])])
            ->all();

        return [
            'objectTypes' => $objectTypes,
            'eventTypes' => $eventTypes,
            'objects' => $objects,
            'events' => $events,
        ];
    }

    /**
     * Structural validation of the exported document against the OCEL 2.0 shape
     * and referential integrity (every relationship points at a declared object;
     * every type is declared). Returns [] when valid, else a list of problems.
     *
     * @param  array<string, mixed>  $doc
     * @return array<int, string>
     */
    public function validate(array $doc): array
    {
        $problems = [];
        foreach (['objectTypes', 'eventTypes', 'objects', 'events'] as $key) {
            if (! array_key_exists($key, $doc) || ! is_array($doc[$key])) {
                $problems[] = "missing or non-array top-level key: {$key}";
            }
        }
        if ($problems) {
            return $problems;
        }

        $declaredObjectTypes = array_column($doc['objectTypes'], 'name');
        $declaredEventTypes = array_column($doc['eventTypes'], 'name');
        $objectIds = array_column($doc['objects'], 'id');
        $objectIdSet = array_flip($objectIds);

        foreach ($doc['objects'] as $i => $o) {
            if (! in_array($o['type'] ?? null, $declaredObjectTypes, true)) {
                $problems[] = "object[{$i}] {$o['id']}: undeclared object type '{$o['type']}'";
            }
            foreach ($o['relationships'] as $rel) {
                if (! isset($objectIdSet[$rel['objectId']])) {
                    $problems[] = "object[{$i}] {$o['id']}: O2O points at unknown object '{$rel['objectId']}'";
                }
            }
        }
        foreach ($doc['events'] as $i => $e) {
            if (! in_array($e['type'] ?? null, $declaredEventTypes, true)) {
                $problems[] = "event[{$i}] {$e['id']}: undeclared event type '{$e['type']}'";
            }
            if (empty($e['relationships'])) {
                $problems[] = "event[{$i}] {$e['id']}: no E2O relationships (an event must touch an object)";
            }
            foreach ($e['relationships'] as $rel) {
                if (! isset($objectIdSet[$rel['objectId']])) {
                    $problems[] = "event[{$i}] {$e['id']}: E2O points at unknown object '{$rel['objectId']}'";
                }
            }
        }

        return $problems;
    }

    /** @param  array<string, mixed>  $attrs */
    private function eventAttributes(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $name => $value) {
            $out[] = ['name' => $name, 'value' => $this->scalarize($value)];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $static */
    private function objectAttributes(array $static, mixed $changeRows, string $floor): array
    {
        $out = [];
        foreach ($static as $name => $value) {
            $out[] = ['name' => $name, 'time' => $this->iso($floor), 'value' => $this->scalarize($value)];
        }
        foreach ($changeRows as $c) {
            $out[] = ['name' => $c->attr, 'time' => $this->iso($c->changed_at), 'value' => $this->scalarize($this->decode($c->value))];
        }

        return $out;
    }

    /** @param  array<string, bool>  $keys */
    private function declaredAttributes(array $keys): array
    {
        return collect(array_keys($keys))->sort()->values()
            ->map(fn ($k) => ['name' => $k, 'type' => 'string'])->all();
    }

    private function scalarize(mixed $value): string|int|float|bool|null
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return json_encode($value);
    }

    private function decode(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return json_decode($value, true);
        }

        return [];
    }

    private function iso(string $value): string
    {
        return \Carbon\Carbon::parse($value)->toIso8601String();
    }
}
