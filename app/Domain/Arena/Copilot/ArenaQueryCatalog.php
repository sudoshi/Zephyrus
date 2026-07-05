<?php

namespace App\Domain\Arena\Copilot;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Part X (X4) — the allow-listed NL-query surface (§X.8.1, §X.8.2). The copilot
 * answers questions over the object-centric log by SELECTING a named, parameterized
 * query from this fixed catalog and filling its typed parameters — it NEVER emits
 * SQL. A hallucinated question cannot become a destructive or PHI-exposing query
 * because there is no path from free text to SQL: the LLM (or the deterministic
 * keyword router) only ever chooses a `query_id` + params, and this class binds
 * every parameter. All queries are read-only aggregates over PHI-free tables
 * (activity labels, object-type names, counts, conformance/performance signals).
 */
class ArenaQueryCatalog
{
    /** @var list<string>|null cached whitelist of real object-type names */
    private ?array $objectTypes = null;

    /**
     * The fixed query set. Closures can't live in a const, so the catalog is a
     * method; each entry declares its typed params and a bound-only query builder.
     *
     * @return array<string, array{label:string, description:string, keywords:list<string>, params:array<string,array<string,mixed>>, columns:list<string>, run:callable}>
     */
    private function definitions(): array
    {
        return [
            'busiest_activities' => [
                'label' => 'Busiest activities',
                'description' => 'The most frequent activities in the log, by event count.',
                'keywords' => ['busiest', 'most frequent', 'top activities', 'common activities', 'frequent'],
                'params' => ['limit' => ['type' => 'int', 'default' => 10, 'min' => 1, 'max' => 50]],
                'columns' => ['activity', 'events'],
                'run' => fn (array $p): array => DB::table('ocel.events')
                    ->selectRaw('activity, count(*) as events')
                    ->groupBy('activity')
                    ->orderByDesc('events')
                    ->limit($p['limit'])
                    ->get()->map(fn ($r): array => ['activity' => $r->activity, 'events' => (int) $r->events])->all(),
            ],
            'object_type_volumes' => [
                'label' => 'Object-type volumes',
                'description' => 'How many objects of each type the log contains (patients, beds, encounters, …).',
                'keywords' => ['object types', 'how many', 'volumes', 'counts by type', 'object counts'],
                'params' => [],
                'columns' => ['object_type', 'objects'],
                'run' => fn (array $p): array => DB::table('ocel.objects')
                    ->selectRaw('type as object_type, count(*) as objects')
                    ->groupBy('type')
                    ->orderByDesc('objects')
                    ->get()->map(fn ($r): array => ['object_type' => $r->object_type, 'objects' => (int) $r->objects])->all(),
            ],
            'activities_for_object_type' => [
                'label' => 'Activities touching an object type',
                'description' => 'Which activities involve a given object type, by event count.',
                'keywords' => ['activities for', 'touching', 'involving'],
                'params' => ['object_type' => ['type' => 'object_type']],
                'columns' => ['activity', 'events'],
                'run' => fn (array $p): array => DB::table('ocel.events as e')
                    ->join('ocel.event_object as eo', 'eo.event_id', '=', 'e.id')
                    ->join('ocel.objects as o', 'o.id', '=', 'eo.object_id')
                    ->where('o.type', $p['object_type'])
                    ->selectRaw('e.activity as activity, count(*) as events')
                    ->groupBy('e.activity')
                    ->orderByDesc('events')
                    ->get()->map(fn ($r): array => ['activity' => $r->activity, 'events' => (int) $r->events])->all(),
            ],
            'pathway_conformance_rates' => [
                'label' => 'Care-pathway conformance',
                'description' => 'The latest object-centric conformance rate for each reference pathway (sepsis, surgical safety).',
                'keywords' => ['conformance', 'pathway', 'sepsis', 'surgical', 'compliance', 'bundle'],
                'params' => [],
                'columns' => ['pathway', 'conformance_pct', 'cases', 'deviant'],
                'run' => fn (array $p): array => DB::table('arena.conformance_signals')
                    ->select('pathway', 'value', 'cases', 'deviant')
                    ->orderBy('pathway')
                    ->get()->map(fn ($r): array => [
                        'pathway' => $r->pathway, 'conformance_pct' => (float) $r->value,
                        'cases' => (int) $r->cases, 'deviant' => (int) $r->deviant,
                    ])->all(),
            ],
            'handoff_bottlenecks' => [
                'label' => 'Hand-off bottlenecks',
                'description' => 'The worst object-side wait at a shared hand-off (the OPerA synchronization constraint).',
                'keywords' => ['bottleneck', 'hand-off', 'handoff', 'wait', 'slowest', 'constraint', 'delay'],
                'params' => [],
                'columns' => ['metric', 'value_min', 'context'],
                'run' => fn (array $p): array => DB::table('arena.performance_signals')
                    ->select('metric_key', 'value', 'context')
                    ->orderByDesc('value')
                    ->get()->map(fn ($r): array => [
                        'metric' => $r->metric_key, 'value_min' => (float) $r->value, 'context' => $r->context,
                    ])->all(),
            ],
            'event_volume_by_day' => [
                'label' => 'Event volume by day',
                'description' => 'Daily event counts over the recent window — the log’s activity trend.',
                'keywords' => ['by day', 'per day', 'volume', 'trend', 'over time', 'daily'],
                'params' => ['days' => ['type' => 'int', 'default' => 14, 'min' => 1, 'max' => 90]],
                'columns' => ['day', 'events'],
                'run' => fn (array $p): array => DB::table('ocel.events')
                    ->selectRaw("to_char(date_trunc('day', event_time), 'YYYY-MM-DD') as day, count(*) as events")
                    ->groupByRaw("date_trunc('day', event_time)")
                    ->orderByRaw("date_trunc('day', event_time) desc")
                    ->limit($p['days'])
                    ->get()->map(fn ($r): array => ['day' => $r->day, 'events' => (int) $r->events])->all(),
            ],
        ];
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->definitions());
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->definitions());
    }

    /**
     * The allow-list surfaced to the LLM prompt and the frontend — id, label,
     * description, and each param's type/bounds. No closures leak out.
     *
     * @return list<array<string, mixed>>
     */
    public function describe(): array
    {
        $out = [];
        foreach ($this->definitions() as $id => $def) {
            $out[] = [
                'id' => $id,
                'label' => $def['label'],
                'description' => $def['description'],
                'params' => $def['params'],
                'columns' => $def['columns'],
            ];
        }

        return $out;
    }

    /**
     * Execute a whitelisted query. Every parameter is validated and bound; an
     * unknown id or an out-of-whitelist object type is rejected. This is the single
     * security boundary — nothing here interpolates caller text into SQL.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function run(string $id, array $params = []): array
    {
        $def = $this->definitions()[$id] ?? null;
        if ($def === null) {
            throw new InvalidArgumentException("Unknown Arena query [{$id}] — not in the allow-list.");
        }

        $bound = $this->validateParams($def['params'], $params);
        $rows = ($def['run'])($bound);

        return [
            'query_id' => $id,
            'label' => $def['label'],
            'columns' => $def['columns'],
            // (object) so an empty param set serializes as {} not [] — a bare []
            // would fail the frontend's z.record(...) parse and blank the result.
            'params' => (object) $bound,
            'rows' => $rows,
            'provenance' => "Allow-listed query “{$id}” over the object-centric log (read-only, PHI-free).",
        ];
    }

    /**
     * Deterministic fallback router: map a natural-language question onto a query
     * id + params by keyword, with no model. Returns null when nothing matches — the
     * copilot then says so rather than fabricating a query.
     *
     * @return array{query_id:string, params:array<string,mixed>}|null
     */
    public function resolve(string $question): ?array
    {
        $q = mb_strtolower(trim($question));
        if ($q === '') {
            return null;
        }

        $best = null;
        $bestScore = 0;
        foreach ($this->definitions() as $id => $def) {
            $score = 0;
            foreach ($def['keywords'] as $kw) {
                if (str_contains($q, $kw)) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $id;
            }
        }

        if ($best === null) {
            return null;
        }

        return ['query_id' => $best, 'params' => $this->extractParams($best, $q)];
    }

    /**
     * Coerce/validate the params for a query against its declared schema. Ints are
     * clamped to [min,max]; an object_type must be a real type in the log (whitelist).
     *
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function validateParams(array $schema, array $params): array
    {
        $out = [];
        foreach ($schema as $name => $spec) {
            $raw = $params[$name] ?? ($spec['default'] ?? null);

            if ($spec['type'] === 'int') {
                $val = (int) $raw;
                $val = max((int) $spec['min'], min((int) $spec['max'], $val));
                $out[$name] = $val;
            } elseif ($spec['type'] === 'object_type') {
                $val = (string) $raw;
                if (! in_array($val, $this->realObjectTypes(), true)) {
                    throw new InvalidArgumentException("Object type [{$val}] is not present in the log.");
                }
                $out[$name] = $val;
            }
        }

        return $out;
    }

    /** Pull simple params out of the question text for the deterministic router. */
    private function extractParams(string $id, string $q): array
    {
        $params = [];
        $schema = $this->definitions()[$id]['params'];

        if (isset($schema['limit']) && preg_match('/\b(?:top|first)\s+(\d{1,2})\b/', $q, $m)) {
            $params['limit'] = (int) $m[1];
        }
        if (isset($schema['days']) && preg_match('/\b(\d{1,3})\s*days?\b/', $q, $m)) {
            $params['days'] = (int) $m[1];
        }
        if (isset($schema['object_type'])) {
            foreach ($this->realObjectTypes() as $type) {
                if (str_contains($q, mb_strtolower($type))) {
                    $params['object_type'] = $type;
                    break;
                }
            }
        }

        return $params;
    }

    /** @return list<string> */
    private function realObjectTypes(): array
    {
        if ($this->objectTypes === null) {
            try {
                $this->objectTypes = DB::table('ocel.object_types')->pluck('type')->map(fn ($t): string => (string) $t)->all();
            } catch (\Throwable) {
                $this->objectTypes = [];
            }
        }

        return $this->objectTypes;
    }
}
