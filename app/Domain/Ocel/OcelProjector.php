<?php

namespace App\Domain\Ocel;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The OCEL projector (Part X §X.3.2) — a read-side projection, the OCPM analogue
 * of the cockpit's SnapshotBuilder. It consumes assets already on `main`
 * (flow_core.flow_events, prod.care_journey_milestones, prod.transport_requests)
 * and writes the OCEL 2.0 relational log into ocel.*. It NEVER instruments new
 * logging and NEVER mutates prod.* — projection only.
 *
 * Everything is idempotent by construction: events/objects carry deterministic
 * ids and are UPSERTed, so re-running a window (or the nightly full reconcile)
 * converges rather than duplicating. Because it reads the same event store as the
 * cockpit, a bottleneck the Arena discovers at A3 is provably the same reality
 * the cockpit shows at A0 — the live/analytic story stays singular (Principle 7).
 */
class OcelProjector
{
    /** @var array<string, array{type: string, attrs: array<string, mixed>}> */
    private array $objects = [];

    /** @var array<string, array<string, mixed>> */
    private array $events = [];

    /** @var array<string, array{event_id: string, object_id: string, qualifier: string}> */
    private array $e2o = [];

    /** @var array<string, array{from_id: string, to_id: string, qualifier: string}> */
    private array $o2o = [];

    /** @var array<int, array{object_id: string, attr: string, value: mixed, changed_at: string}> */
    private array $changes = [];

    /**
     * Project the OCEL log for a time window. Returns per-source and total
     * counts for the caller (command / reconcile).
     *
     * @return array<string, mixed>
     */
    public function project(?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        $since ??= Carbon::now()->subDays(90);
        $until ??= Carbon::now();

        $this->reset();
        $this->ensureCatalog();

        $sourceRows = [
            'flow_core.flow_events' => $this->collectFlowEvents($since, $until),
            'prod.care_journey_milestones' => $this->collectMilestones($since, $until),
            'prod.transport_requests' => $this->collectTransport($since, $until),
        ];

        $this->flush();

        return [
            'window' => ['since' => $since->toIso8601String(), 'until' => $until->toIso8601String()],
            'source_rows' => $sourceRows,
            'events' => count($this->events),
            'objects' => count($this->objects),
            'e2o' => count($this->e2o),
            'o2o' => count($this->o2o),
            'object_changes' => count($this->changes),
        ];
    }

    /**
     * Reconcile projected event counts against the source-of-truth row counts
     * (§X.3.3 nightly reconcile). A drift beyond tolerance signals a projection
     * gap the caller can surface.
     *
     * @return array<int, array{source: string, source_rows: int, projected_events: int, distinct_source_refs: int}>
     */
    public function reconcile(?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        $since ??= Carbon::now()->subDays(90);
        $until ??= Carbon::now();

        $out = [];
        foreach (['flow_core.flow_events', 'prod.care_journey_milestones', 'prod.transport_requests'] as $src) {
            $projected = (int) DB::table('ocel.events')->where('source_system', $src)->count();
            $distinct = (int) DB::table('ocel.events')->where('source_system', $src)->distinct('source_ref')->count('source_ref');
            $out[] = [
                'source' => $src,
                'source_rows' => $this->sourceRowCount($src, $since, $until),
                'projected_events' => $projected,
                'distinct_source_refs' => $distinct,
            ];
        }

        return $out;
    }

    // --- source collectors -------------------------------------------------

    private function collectFlowEvents(CarbonInterface $since, CarbonInterface $until): int
    {
        $n = 0;
        DB::table('flow_core.flow_events')
            ->whereBetween('occurred_at', [$since, $until])
            ->orderBy('occurred_at')
            ->chunk(1000, function ($rows) use (&$n) {
                foreach ($rows as $row) {
                    if ($e = EmissionMap::forFlowEvent($row)) {
                        $this->absorb($e);
                        $n++;
                    }
                }
            });

        return $n;
    }

    private function collectMilestones(CarbonInterface $since, CarbonInterface $until): int
    {
        $n = 0;
        DB::table('prod.care_journey_milestones as m')
            ->leftJoin('prod.or_cases as c', 'c.case_id', '=', 'm.case_id')
            ->whereRaw('COALESCE(m.completed_at, m.created_at) BETWEEN ? AND ?', [$since, $until])
            ->select([
                'm.id as milestone_id', 'm.case_id', 'm.milestone_type', 'm.status', 'm.required',
                'm.completed_at', 'm.created_at',
                'c.room_id', 'c.patient_id', 'c.safety_status', 'c.journey_progress', 'c.surgery_date',
            ])
            ->orderBy('m.id')
            ->chunk(1000, function ($rows) use (&$n) {
                foreach ($rows as $row) {
                    if ($e = EmissionMap::forMilestone($row)) {
                        $this->absorb($e);
                        $n++;
                    }
                }
            });

        return $n;
    }

    private function collectTransport(CarbonInterface $since, CarbonInterface $until): int
    {
        $n = 0;
        DB::table('prod.transport_requests')
            ->where('is_deleted', false)
            ->whereBetween('requested_at', [$since, $until])
            ->orderBy('transport_request_id')
            ->chunk(1000, function ($rows) use (&$n) {
                foreach ($rows as $row) {
                    foreach (EmissionMap::forTransport($row) as $e) {
                        $this->absorb($e);
                        $n++;
                    }
                }
            });

        return $n;
    }

    private function sourceRowCount(string $src, CarbonInterface $since, CarbonInterface $until): int
    {
        return match ($src) {
            'flow_core.flow_events' => (int) DB::table('flow_core.flow_events')
                ->whereBetween('occurred_at', [$since, $until])->count(),
            'prod.care_journey_milestones' => (int) DB::table('prod.care_journey_milestones')
                ->whereRaw('COALESCE(completed_at, created_at) BETWEEN ? AND ?', [$since, $until])->count(),
            'prod.transport_requests' => (int) DB::table('prod.transport_requests')
                ->where('is_deleted', false)->whereBetween('requested_at', [$since, $until])->count(),
            default => 0,
        };
    }

    // --- accumulation + write ---------------------------------------------

    private function absorb(EmittedEvent $e): void
    {
        $this->events[$e->id] = [
            'id' => $e->id,
            'activity' => $e->activity,
            'event_time' => $e->timestamp->toIso8601String(),
            'attrs' => json_encode((object) $e->attrs),
            'source_system' => $e->sourceSystem,
            'source_ref' => $e->sourceRef,
        ];

        foreach ($e->objects as $obj) {
            $id = $obj['id'];
            $attrs = $obj['attrs'] ?? [];
            if (isset($this->objects[$id])) {
                $this->objects[$id]['attrs'] = array_merge($this->objects[$id]['attrs'], $attrs);
            } else {
                $this->objects[$id] = ['type' => $obj['type'], 'attrs' => $attrs];
            }

            $q = $obj['qualifier'] ?? '';
            $this->e2o[$e->id.'|'.$id.'|'.$q] = ['event_id' => $e->id, 'object_id' => $id, 'qualifier' => $q];
        }

        foreach ($e->o2o as $rel) {
            $key = $rel['from'].'|'.$rel['to'].'|'.$rel['qualifier'];
            $this->o2o[$key] = ['from_id' => $rel['from'], 'to_id' => $rel['to'], 'qualifier' => $rel['qualifier']];
        }

        foreach ($e->changes as $chg) {
            $this->changes[] = [
                'object_id' => $chg['object_id'],
                'attr' => $chg['attr'],
                'value' => json_encode($chg['value']),
                'changed_at' => $chg['at']->toIso8601String(),
            ];
        }
    }

    private function flush(): void
    {
        $now = Carbon::now();

        DB::transaction(function () use ($now) {
            // Objects first, then events, then relationships — no cross-table
            // FKs, but this ordering keeps the log readable mid-write.
            $objectRows = [];
            foreach ($this->objects as $id => $o) {
                $objectRows[] = [
                    'id' => $id,
                    'type' => $o['type'],
                    'attrs' => json_encode((object) $o['attrs']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($objectRows, 500) as $chunk) {
                DB::table('ocel.objects')->upsert($chunk, ['id'], ['type', 'attrs', 'updated_at']);
            }

            foreach (array_chunk(array_values($this->events), 500) as $chunk) {
                DB::table('ocel.events')->upsert($chunk, ['id'], ['activity', 'event_time', 'attrs', 'source_system', 'source_ref']);
            }

            foreach (array_chunk(array_values($this->e2o), 1000) as $chunk) {
                DB::table('ocel.event_object')->upsert($chunk, ['event_id', 'object_id', 'qualifier'], ['qualifier']);
            }

            foreach (array_chunk(array_values($this->o2o), 1000) as $chunk) {
                DB::table('ocel.object_object')->upsert($chunk, ['from_id', 'to_id', 'qualifier'], ['qualifier']);
            }

            // object_changes are append-only history; insert only the ones not
            // already present for this (object, attr, changed_at).
            foreach (array_chunk($this->changes, 500) as $chunk) {
                DB::table('ocel.object_changes')->insertOrIgnore($chunk);
            }
        });
    }

    /**
     * Upsert the object-type + activity catalog (§X.2.2 / §X.2.3). Idempotent;
     * runs before every projection so `ocel:project` needs no seed step.
     */
    public function ensureCatalog(): void
    {
        $now = Carbon::now();

        $typeRows = [];
        foreach (OcelCatalog::objectTypes() as $type => $meta) {
            $typeRows[] = [
                'type' => $type,
                'lens' => $meta['lens'],
                'source_system' => $meta['source_system'],
                'version' => OcelCatalog::VERSION,
                'attrs_schema' => json_encode((object) []),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('ocel.object_types')->upsert($typeRows, ['type'], ['lens', 'source_system', 'version', 'updated_at']);

        $activityRows = [];
        foreach (OcelCatalog::activities() as $activity => $domain) {
            $activityRows[] = ['activity' => $activity, 'domain' => $domain, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($activityRows, 500) as $chunk) {
            DB::table('ocel.activities')->upsert($chunk, ['activity'], ['domain', 'updated_at']);
        }
    }

    private function reset(): void
    {
        $this->objects = [];
        $this->events = [];
        $this->e2o = [];
        $this->o2o = [];
        $this->changes = [];
    }
}
