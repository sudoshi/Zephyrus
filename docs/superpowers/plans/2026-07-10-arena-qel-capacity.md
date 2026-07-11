# Arena QEL Capacity Layer (Phase XO.3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Model per-unit **occupancy over time** as an OCEL quantity event log (QEL), derived purely from the already-projected `ocel.*` log, and surface the capacity/census curve (peak, nadir, current) in the Arena — the process-intelligence view of RTDC/NEDOCS.

**Architecture:** Two additive `ocel.*` tables (`object_quantities`, `quantity_operations`). A new `QuantityProjector` derives occupancy deltas **downstream from `ocel.events`/`ocel.event_object`** (admit/place = +1, discharge/depart = −1 on the referenced Unit), with initial occupancy at the window floor computed from the same log — no new instrumentation, no `prod.*` read, no external capacity table. A `QuantityExporter` emits a flat `{initial, operations}` payload. A pandas-only sidecar `/capacity` endpoint reconstructs the cumulative series per unit (works even if pm4py is absent). A Recharts pane renders the per-unit census curve.

**Tech Stack:** Laravel 11 / PHP 8.5 (projector, exporter, orchestrator); PostgreSQL (`ocel.*`); Python 3.12 + pandas (sidecar); React 19 + Recharts + Zod (frontend).

**Provenance:** Clean-room per [`2026-07-10-ocelescope-ocpm-integration-roadmap.md`](./2026-07-10-ocelescope-ocpm-integration-roadmap.md) §3. The QEL *idea* (initial quantities `oqty` + per-event operations `qop`) is from the OCEL 2.0 quantity extension; the implementation is our own. PHI-safe: quantities are **unit-level counts**, never patient-identifying.

---

### Task 1: Additive `ocel.*` quantity tables

**Files:**
- Create: `database/migrations/2026_07_10_000400_create_ocel_quantity_tables.php`
- Test: `tests/Feature/Arena/OcelQuantitySchemaTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Arena/OcelQuantitySchemaTest.php`:

```php
<?php

namespace Tests\Feature\Arena;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OcelQuantitySchemaTest extends TestCase
{
    public function test_quantity_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('ocel.object_quantities'));
        $this->assertTrue(Schema::hasTable('ocel.quantity_operations'));
        $this->assertTrue(Schema::hasColumns('ocel.quantity_operations', [
            'event_id', 'object_id', 'item_type', 'delta', 'event_time',
        ]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OcelQuantitySchemaTest`
Expected: FAIL — tables do not exist

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_10_000400_create_ocel_quantity_tables.php`:

```php
<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part X — Phase XO.3 (QEL capacity). Additive quantity extension for the OCEL
 * 2.0 store: initial quantities per object+item (oqty) and per-event quantity
 * operations (qop). Occupancy is a downstream projection of ocel.* (admit=+1,
 * discharge=-1 on a Unit); these tables carry unit-level counts only — no PHI.
 * Removable by dropping both tables; the rest of ocel.* is unaffected.
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS ocel');

        if (! Schema::hasTable('ocel.object_quantities')) {
            Schema::create('ocel.object_quantities', function (Blueprint $table) {
                $table->id();
                $table->string('object_id', 160);
                $table->string('item_type', 60);
                $table->integer('quantity')->default(0);
                $table->timestamps();

                $table->unique(['object_id', 'item_type'], 'ocel_oqty_unique');
            });
        }

        if (! Schema::hasTable('ocel.quantity_operations')) {
            Schema::create('ocel.quantity_operations', function (Blueprint $table) {
                $table->id();
                $table->string('event_id', 160);
                $table->string('object_id', 160);
                $table->string('item_type', 60);
                $table->integer('delta');
                $table->timestampTz('event_time');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['event_id', 'object_id', 'item_type'], 'ocel_qop_unique');
                $table->index(['object_id', 'item_type', 'event_time'], 'ocel_qop_series_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ocel.quantity_operations');
        Schema::dropIfExists('ocel.object_quantities');
    }
};
```

- [ ] **Step 4: Migrate the test DB + run the test**

Run: `php artisan migrate --path=database/migrations/2026_07_10_000400_create_ocel_quantity_tables.php`
Run: `php artisan test --filter=OcelQuantitySchemaTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_10_000400_create_ocel_quantity_tables.php tests/Feature/Arena/OcelQuantitySchemaTest.php
git commit -m "feat(ocel): additive QEL quantity tables (oqty + qop)"
```

---

### Task 2: `QuantityProjector` — pure occupancy classification

**Files:**
- Create: `app/Domain/Ocel/QuantityProjector.php`
- Test: `tests/Feature/Arena/QuantityProjectorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Arena/QuantityProjectorTest.php`:

```php
<?php

namespace Tests\Feature\Arena;

use App\Domain\Ocel\QuantityProjector;
use Tests\TestCase;

class QuantityProjectorTest extends TestCase
{
    public function test_compute_quantities_classifies_occupancy_deltas(): void
    {
        $projector = new QuantityProjector();

        $events = [
            ['event_id' => 'e1', 'activity' => 'admit', 'unit_id' => 'Unit:5N', 'time' => '2026-01-01T00:00:00Z'],
            ['event_id' => 'e2', 'activity' => 'discharge', 'unit_id' => 'Unit:5N', 'time' => '2026-01-01T06:00:00Z'],
            ['event_id' => 'e3', 'activity' => 'triage', 'unit_id' => 'Unit:5N', 'time' => '2026-01-01T01:00:00Z'],
        ];
        $out = $projector->computeQuantities($events, ['Unit:5N' => 4]);

        // admit => +1, discharge => -1, triage => skipped (not occupancy-affecting)
        $this->assertCount(2, $out['operations']);
        $deltas = array_column($out['operations'], 'delta');
        $this->assertEqualsCanonicalizing([1, -1], $deltas);

        $this->assertSame(
            [['object_id' => 'Unit:5N', 'item_type' => 'occupied_beds', 'quantity' => 4]],
            $out['initial'],
        );
        $this->assertSame('occupied_beds', $out['operations'][0]['item_type']);
    }

    public function test_compute_quantities_skips_events_without_a_unit(): void
    {
        $out = (new QuantityProjector())->computeQuantities(
            [['event_id' => 'e1', 'activity' => 'admit', 'unit_id' => null, 'time' => '2026-01-01T00:00:00Z']],
            [],
        );
        $this->assertSame([], $out['operations']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=QuantityProjectorTest`
Expected: FAIL — class `QuantityProjector` not found

- [ ] **Step 3: Write the pure core (IO shell added in Task 3)**

Create `app/Domain/Ocel/QuantityProjector.php`:

```php
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
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=QuantityProjectorTest`
Expected: PASS

- [ ] **Step 5: Run Pint + commit**

Run: `docker compose exec -T php sh -c "cd /var/www/html && vendor/bin/pint app/Domain/Ocel/QuantityProjector.php"`

```bash
git add app/Domain/Ocel/QuantityProjector.php tests/Feature/Arena/QuantityProjectorTest.php
git commit -m "feat(ocel): QuantityProjector occupancy classification (pure core)"
```

---

### Task 3: `QuantityProjector` IO shell — read `ocel.*`, upsert quantities

**Files:**
- Modify: `app/Domain/Ocel/QuantityProjector.php` (add `project`, `readOccupancyEvents`, `initialOccupancy`, `flush`)
- Test: `tests/Feature/Arena/QuantityProjectorProjectTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Arena/QuantityProjectorProjectTest.php` — seed a handful of `ocel.*` rows (our own tables, safe to seed) and assert the quantity tables populate:

```php
<?php

namespace Tests\Feature\Arena;

use App\Domain\Ocel\QuantityProjector;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuantityProjectorProjectTest extends TestCase
{
    public function test_project_populates_quantity_operations_from_ocel_log(): void
    {
        DB::table('ocel.objects')->insert([
            ['id' => 'Unit:5N', 'type' => 'Unit', 'attrs' => '{}', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('ocel.events')->insert([
            ['id' => 'ev-admit', 'activity' => 'admit', 'event_time' => '2026-01-01T00:00:00Z', 'attrs' => '{}', 'source_system' => 'test', 'source_ref' => 'a'],
            ['id' => 'ev-disch', 'activity' => 'discharge', 'event_time' => '2026-01-01T06:00:00Z', 'attrs' => '{}', 'source_system' => 'test', 'source_ref' => 'd'],
        ]);
        DB::table('ocel.event_object')->insert([
            ['event_id' => 'ev-admit', 'object_id' => 'Unit:5N', 'qualifier' => 'location'],
            ['event_id' => 'ev-disch', 'object_id' => 'Unit:5N', 'qualifier' => 'location'],
        ]);

        (new QuantityProjector())->project(Carbon::parse('2025-12-31'), Carbon::parse('2026-01-02'));

        $ops = DB::table('ocel.quantity_operations')->where('object_id', 'Unit:5N')->orderBy('event_time')->get();
        $this->assertCount(2, $ops);
        $this->assertSame(1, (int) $ops[0]->delta);
        $this->assertSame(-1, (int) $ops[1]->delta);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=QuantityProjectorProjectTest`
Expected: FAIL — `Call to undefined method QuantityProjector::project()`

- [ ] **Step 3: Add the IO shell**

Append to `app/Domain/Ocel/QuantityProjector.php` (inside the class):

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=QuantityProjectorProjectTest`
Expected: PASS

- [ ] **Step 5: Run Pint + commit**

Run: `docker compose exec -T php sh -c "cd /var/www/html && vendor/bin/pint app/Domain/Ocel/QuantityProjector.php"`

```bash
git add app/Domain/Ocel/QuantityProjector.php tests/Feature/Arena/QuantityProjectorProjectTest.php
git commit -m "feat(ocel): QuantityProjector reads ocel.* + upserts occupancy quantities"
```

---

### Task 4: `QuantityExporter` — flat `{initial, operations}` payload

**Files:**
- Create: `app/Domain/Ocel/QuantityExporter.php`
- Test: `tests/Feature/Arena/QuantityExporterTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Arena/QuantityExporterTest.php`:

```php
<?php

namespace Tests\Feature\Arena;

use App\Domain\Ocel\QuantityExporter;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuantityExporterTest extends TestCase
{
    public function test_export_emits_initial_and_operations(): void
    {
        DB::table('ocel.object_quantities')->insert([
            'object_id' => 'Unit:5N', 'item_type' => 'occupied_beds', 'quantity' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ocel.quantity_operations')->insert([
            ['event_id' => 'e1', 'object_id' => 'Unit:5N', 'item_type' => 'occupied_beds', 'delta' => 1, 'event_time' => '2026-01-01T00:00:00Z'],
        ]);

        $doc = (new QuantityExporter())->export();

        $this->assertSame(3, $doc['initial'][0]['quantity']);
        $this->assertSame('Unit:5N', $doc['operations'][0]['object_id']);
        $this->assertSame(1, $doc['operations'][0]['delta']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=QuantityExporterTest`
Expected: FAIL — class `QuantityExporter` not found

- [ ] **Step 3: Write the exporter**

Create `app/Domain/Ocel/QuantityExporter.php`:

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=QuantityExporterTest`
Expected: PASS

- [ ] **Step 5: Run Pint + commit**

Run: `docker compose exec -T php sh -c "cd /var/www/html && vendor/bin/pint app/Domain/Ocel/QuantityExporter.php"`

```bash
git add app/Domain/Ocel/QuantityExporter.php tests/Feature/Arena/QuantityExporterTest.php
git commit -m "feat(ocel): QuantityExporter flat {initial,operations} payload"
```

---

### Task 5: Sidecar `/capacity` — cumulative occupancy series (pandas-only)

**Files:**
- Create: `arena/app/capacity.py`
- Create: `arena/app/routers/capacity.py`
- Modify: `arena/app/main.py` (register the router)
- Modify: `arena/app/models.py` (`CapacityRequest`, `CapacityResponse`)
- Test: `arena/tests/test_capacity.py`

- [ ] **Step 1: Write the failing test**

Create `arena/tests/test_capacity.py`:

```python
"""Capacity series — cumulative occupancy from a QEL payload (no pm4py needed)."""

from __future__ import annotations

from app import capacity


def test_series_reconstructs_cumulative_occupancy():
    payload = {
        "initial": [{"object_id": "Unit:5N", "item_type": "occupied_beds", "quantity": 2}],
        "operations": [
            {"object_id": "Unit:5N", "item_type": "occupied_beds", "delta": 1, "event_time": "2026-01-01T00:00:00Z"},
            {"object_id": "Unit:5N", "item_type": "occupied_beds", "delta": 1, "event_time": "2026-01-01T01:00:00Z"},
            {"object_id": "Unit:5N", "item_type": "occupied_beds", "delta": -1, "event_time": "2026-01-01T02:00:00Z"},
        ],
    }
    out = capacity.series(payload)
    unit = out["objects"][0]
    assert unit["object_id"] == "Unit:5N"
    assert [p["value"] for p in unit["series"]] == [3, 4, 3]  # 2 +1 +1 -1
    assert unit["peak"] == 4
    assert unit["nadir"] == 2  # the baseline
    assert unit["current"] == 3


def test_series_handles_empty_operations():
    assert capacity.series({"initial": [], "operations": []})["objects"] == []
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/smudoshi/Github/Zephyrus/arena && python -m pytest tests/test_capacity.py -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'app.capacity'`

- [ ] **Step 3: Write the capacity module**

Create `arena/app/capacity.py`:

```python
"""Capacity / occupancy series from a QEL payload (Part X, Phase XO.3).

Reconstructs the absolute occupancy curve per object as initial + cumulative sum
of the timestamped operations. Pure pandas — no pm4py — so /capacity works even
in a mining-less sidecar build. Read-only, PHI-free: unit-level counts only.
"""

from __future__ import annotations

from typing import Any

import pandas as pd


def series(payload: dict[str, Any], item_type: str | None = None, threshold: int | None = None) -> dict[str, Any]:
    initial_rows = payload.get("initial", []) or []
    op_rows = payload.get("operations", []) or []

    init_map = {(r["object_id"], r["item_type"]): int(r.get("quantity", 0)) for r in initial_rows}

    if not op_rows:
        return {"objects": [], "stats": {"objects": 0}}

    ops = pd.DataFrame(op_rows)
    if item_type:
        ops = ops[ops["item_type"] == item_type]
    if ops.empty:
        return {"objects": [], "stats": {"objects": 0}}

    ops["event_time"] = pd.to_datetime(ops["event_time"], utc=True)

    objects: list[dict[str, Any]] = []
    for (oid, itype), grp in ops.groupby(["object_id", "item_type"]):
        grp = grp.sort_values("event_time")
        base = init_map.get((oid, itype), 0)
        running = base + grp["delta"].cumsum()
        points = [{"time": t.isoformat(), "value": int(v)} for t, v in zip(grp["event_time"], running)]
        values = [base] + [p["value"] for p in points]

        obj: dict[str, Any] = {
            "object_id": str(oid),
            "item_type": str(itype),
            "series": points,
            "peak": int(max(values)),
            "nadir": int(min(values)),
            "current": int(values[-1]),
        }
        if threshold is not None:
            obj["periods_above_threshold"] = int(sum(1 for p in points if p["value"] > threshold))
        objects.append(obj)

    objects.sort(key=lambda o: -o["peak"])
    return {"objects": objects, "stats": {"objects": len(objects)}}
```

- [ ] **Step 4: Add the models**

In `arena/app/models.py`, append:

```python
class CapacityRequest(BaseModel):
    """A QEL payload ({initial, operations}) + optional item-type / threshold. No
    OCEL doc needed — capacity is computed from quantity operations alone."""

    quantities: dict[str, Any]
    item_type: str | None = Field(default=None, description="restrict to one quantity item type, e.g. occupied_beds")
    threshold: int | None = Field(default=None, description="count series points above this value")


class CapacityPoint(BaseModel):
    time: str
    value: int


class CapacityObject(BaseModel):
    object_id: str
    item_type: str
    series: list[CapacityPoint]
    peak: int
    nadir: int
    current: int
    periods_above_threshold: int | None = None


class CapacityResponse(BaseModel):
    objects: list[CapacityObject]
    stats: dict[str, int]
```

- [ ] **Step 5: Add the router + register it**

Create `arena/app/routers/capacity.py`:

```python
"""Capacity surface (Part X §XO.3): POST /capacity turns a QEL payload into per-unit
occupancy curves. Pandas-only — no mining engine required. PHI-free."""

from __future__ import annotations

from fastapi import APIRouter

from app import capacity
from app.models import CapacityRequest, CapacityResponse

router = APIRouter(tags=["arena"])


@router.post("/capacity", response_model=CapacityResponse)
async def compute_capacity(req: CapacityRequest) -> CapacityResponse:
    result = capacity.series(req.quantities, item_type=req.item_type, threshold=req.threshold)
    return CapacityResponse(**result)
```

In `arena/app/main.py`, add `capacity` to the router imports and include it:

```python
from app.routers import capacity, conformance, copilot, discover, health, performance
# ...
app.include_router(capacity.router)
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd /home/smudoshi/Github/Zephyrus/arena && python -m pytest tests/test_capacity.py -v`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add arena/app/capacity.py arena/app/routers/capacity.py arena/app/main.py arena/app/models.py arena/tests/test_capacity.py
git commit -m "feat(arena): /capacity endpoint reconstructs per-unit occupancy series"
```

---

### Task 6: Laravel orchestration — client + service + controller + route

**Files:**
- Modify: `app/Domain/Arena/ArenaSidecarClient.php` (`capacity()`)
- Modify: `app/Domain/Arena/ArenaService.php` (inject `QuantityExporter`, add `capacity()`)
- Modify: `app/Http/Controllers/Api/ArenaController.php` (`capacity` action)
- Modify: routes file (register `api.arena.capacity`)
- Test: `tests/Feature/Arena/ArenaCapacityTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Arena/ArenaCapacityTest.php`:

```php
<?php

namespace Tests\Feature\Arena;

use App\Domain\Arena\ArenaSidecarClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArenaCapacityTest extends TestCase
{
    public function test_capacity_posts_quantities_payload_to_the_sidecar(): void
    {
        config()->set('services.arena.url', 'http://arena:8100');
        Http::fake([
            'arena:8100/capacity' => Http::response(['objects' => [], 'stats' => ['objects' => 0]], 200),
        ]);

        $client = new ArenaSidecarClient();
        $payload = ['initial' => [], 'operations' => []];
        $out = $client->capacity($payload, 'occupied_beds');

        $this->assertIsArray($out);
        Http::assertSent(fn ($r) => $r->url() === 'http://arena:8100/capacity'
            && $r['item_type'] === 'occupied_beds'
            && $r['quantities'] === $payload);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ArenaCapacityTest`
Expected: FAIL — `Call to undefined method ArenaSidecarClient::capacity()`

- [ ] **Step 3: Add the client method**

In `app/Domain/Arena/ArenaSidecarClient.php`, add:

```php
    /**
     * Per-unit occupancy series from a QEL payload (XO.3).
     *
     * @param  array{initial: array, operations: array}  $quantities
     * @return array<string, mixed>|null
     */
    public function capacity(array $quantities, ?string $itemType = null, ?int $threshold = null): ?array
    {
        $body = ['quantities' => $quantities];
        if ($itemType !== null) {
            $body['item_type'] = $itemType;
        }
        if ($threshold !== null) {
            $body['threshold'] = $threshold;
        }

        return $this->post('/capacity', $body);
    }
```

- [ ] **Step 4: Inject the exporter + add the service method**

In `app/Domain/Arena/ArenaService.php`, add `QuantityExporter` to the constructor and a `capacity` method. Change the constructor to:

```php
    public function __construct(
        private readonly ArenaSidecarClient $client,
        private readonly OcelJsonExporter $exporter,
        private readonly \App\Domain\Ocel\QuantityExporter $quantityExporter,
    ) {}
```

Add the method:

```php
    /**
     * Per-unit occupancy / capacity curve for the current QEL projection (§XO.3).
     * Uncached — a Study read.
     *
     * @return array<string, mixed>
     */
    public function capacity(string $itemType = 'occupied_beds', ?int $threshold = null): array
    {
        $payload = $this->quantityExporter->export();
        $result = $this->client->capacity($payload, $itemType, $threshold);

        if ($result === null) {
            return ['available' => false, 'reason' => 'sidecar_unavailable'];
        }

        return ['available' => true] + $result;
    }
```

(`ArenaService` is resolved from the container, so the new constructor dependency auto-injects — no manual binding needed.)

- [ ] **Step 5: Add the controller action + route**

In `app/Http/Controllers/Api/ArenaController.php`, add:

```php
    public function capacity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_type' => ['sometimes', 'string', 'max:60'],
            'threshold' => ['sometimes', 'integer', 'min:0'],
        ]);

        return response()->json($this->arena->capacity(
            $validated['item_type'] ?? 'occupied_beds',
            $validated['threshold'] ?? null,
        ));
    }
```

Register the route next to `api.arena.map`:

```php
Route::get('arena/capacity', [ArenaController::class, 'capacity'])->name('api.arena.capacity');
```

- [ ] **Step 6: Run test to verify it passes + Pint**

Run: `php artisan test --filter=ArenaCapacityTest`
Run: `docker compose exec -T php sh -c "cd /var/www/html && vendor/bin/pint app/Domain/Arena app/Http/Controllers/Api/ArenaController.php"`
Expected: test green, Pint clean

- [ ] **Step 7: Commit**

```bash
git add app/Domain/Arena/ app/Http/Controllers/Api/ArenaController.php routes/ tests/Feature/Arena/ArenaCapacityTest.php
git commit -m "feat(arena): capacity orchestration (client + service + route)"
```

---

### Task 7: Wire QEL projection into the projection command

**Files:**
- Modify: `app/Console/Commands/OcelProjectCommand.php` (invoke `QuantityProjector::project` after the main projection)
- Test: `tests/Feature/Arena/OcelProjectQuantityIntegrationTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Arena/OcelProjectQuantityIntegrationTest.php`:

```php
<?php

namespace Tests\Feature\Arena;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OcelProjectQuantityIntegrationTest extends TestCase
{
    public function test_ocel_project_command_populates_quantities(): void
    {
        // A projected Unit + admit/discharge already in ocel.* (as the main projector would leave them).
        DB::table('ocel.objects')->insert([['id' => 'Unit:ICU', 'type' => 'Unit', 'attrs' => '{}', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('ocel.events')->insert([
            ['id' => 'q-admit', 'activity' => 'admit', 'event_time' => now()->subHours(3)->toIso8601String(), 'attrs' => '{}', 'source_system' => 'test', 'source_ref' => 'qa'],
        ]);
        DB::table('ocel.event_object')->insert([['event_id' => 'q-admit', 'object_id' => 'Unit:ICU', 'qualifier' => 'location']]);

        $this->artisan('ocel:project', ['--quantities-only' => true])->assertSuccessful();

        $this->assertDatabaseHas('ocel.quantity_operations', ['object_id' => 'Unit:ICU', 'delta' => 1]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OcelProjectQuantityIntegrationTest`
Expected: FAIL — unknown option `--quantities-only` / no quantity rows written

- [ ] **Step 3: Invoke the QuantityProjector from the command**

In `app/Console/Commands/OcelProjectCommand.php`, add a `--quantities-only` option to the signature and run the `QuantityProjector` after (or instead of, when the flag is set) the main projection. Add to the `$signature`:

```php
    // append to the existing signature options:
    // {--quantities-only : project only the QEL quantity extension (occupancy), skipping the base projection}
```

In `handle()`, after resolving the window (`$since`/`$until` as the command already does), add:

```php
        if (! $this->option('quantities-only')) {
            // ... existing OcelProjector::project(...) call stays here ...
        }

        $quantities = app(\App\Domain\Ocel\QuantityProjector::class)->project($since, $until);
        $this->info("QEL quantities: {$quantities['operations']} operations, {$quantities['initial']} initial.");
```

(Match `$since`/`$until` to whatever variable names the command already uses when calling `OcelProjector::project`. If the command has no window parsing, default to `null, null` — the projector then uses its own 90-day default.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=OcelProjectQuantityIntegrationTest`
Expected: PASS

- [ ] **Step 5: Run Pint + commit**

Run: `docker compose exec -T php sh -c "cd /var/www/html && vendor/bin/pint app/Console/Commands/OcelProjectCommand.php"`

```bash
git add app/Console/Commands/OcelProjectCommand.php tests/Feature/Arena/OcelProjectQuantityIntegrationTest.php
git commit -m "feat(ocel): project QEL quantities as part of ocel:project"
```

---

### Task 8: Frontend — per-unit occupancy curve

**Files:**
- Modify: `resources/js/features/arena/schema.ts` (`arenaCapacityResponseSchema`)
- Modify: `resources/js/features/arena/hooks.ts` (`useArenaCapacity`)
- Create: `resources/js/Components/arena/CapacityPane.tsx`
- Modify: `resources/js/Pages/Analytics/Arena.tsx` (render the pane)

- [ ] **Step 1: Add the Zod schema**

In `resources/js/features/arena/schema.ts`, add:

```ts
export const arenaCapacityResponseSchema = z.object({
  available: z.boolean().optional(),
  objects: z.array(z.object({
    object_id: z.string(),
    item_type: z.string(),
    series: z.array(z.object({ time: z.string(), value: z.number() })),
    peak: z.number(),
    nadir: z.number(),
    current: z.number(),
  })).default([]),
  stats: z.record(z.number()).default({}),
});
export type ArenaCapacity = z.infer<typeof arenaCapacityResponseSchema>;
```

- [ ] **Step 2: Add the hook**

In `resources/js/features/arena/hooks.ts`, add:

```ts
export function useArenaCapacity() {
  return useQuery({
    queryKey: ['arena', 'capacity'],
    queryFn: async () => {
      const res = await axios.get(route('api.arena.capacity'));
      return arenaCapacityResponseSchema.parse(res.data);
    },
  });
}
```

- [ ] **Step 3: Create the CapacityPane**

Create `resources/js/Components/arena/CapacityPane.tsx`:

```tsx
import { LineChart, Line, XAxis, YAxis, Tooltip, ResponsiveContainer } from 'recharts';
import type { ArenaCapacity } from '@/features/arena/schema';

/**
 * Phase XO.3: per-unit occupancy (census) curve, mined from the QEL quantity ops.
 * This is the process-intelligence twin of the RTDC census — same reality the
 * cockpit shows, reconstructed from the OCEL log.
 */
export function CapacityPane({ data }: { data: ArenaCapacity }) {
  if (!data.objects.length) {
    return (
      <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        No occupancy quantities projected yet. Run <code>ocel:project</code> to populate them.
      </p>
    );
  }
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      {data.objects.map((unit) => (
        <div
          key={unit.object_id}
          className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:bg-healthcare-surface-dark"
        >
          <div className="flex items-baseline justify-between">
            <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {unit.object_id}
            </h3>
            <span className="tabular-nums text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              peak {unit.peak} · now {unit.current}
            </span>
          </div>
          <div className="mt-2 h-40">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={unit.series}>
                <XAxis dataKey="time" hide />
                <YAxis width={28} tick={{ fontSize: 11 }} allowDecimals={false} />
                <Tooltip formatter={((value: number) => [`${value} beds`, 'Occupied']) as never} />
                <Line
                  type="stepAfter"
                  dataKey="value"
                  stroke="var(--healthcare-primary)"
                  strokeWidth={2}
                  dot={false}
                  isAnimationActive={false}
                />
              </LineChart>
            </ResponsiveContainer>
          </div>
        </div>
      ))}
    </div>
  );
}
```

- [ ] **Step 4: Render the pane in the Arena page**

In `resources/js/Pages/Analytics/Arena.tsx`, import and render:

```tsx
import { CapacityPane } from '@/Components/arena/CapacityPane';
import { useArenaCapacity } from '@/features/arena/hooks';
// ...
const capacity = useArenaCapacity();
// in the JSX, in a new "Capacity" section:
{capacity.data && <CapacityPane data={capacity.data} />}
```

- [ ] **Step 5: Verify the frontend (both checkers) + canon**

Run: `docker compose exec node sh -c "cd /app && npx tsc --noEmit"`
Run: `docker compose exec node sh -c "cd /app && npx vite build"`
Run: `./scripts/check-ui-canon.sh`
Expected: tsc clean, vite build succeeds, canon passes

- [ ] **Step 6: Commit**

```bash
git add resources/js/features/arena/schema.ts resources/js/features/arena/hooks.ts resources/js/Components/arena/CapacityPane.tsx resources/js/Pages/Analytics/Arena.tsx
git commit -m "feat(arena): per-unit occupancy/capacity curve in the Study UI"
```

---

### Task 9: Final verification

- [ ] **Step 1: Clean-room guard**

Run: `./scripts/check-clean-room.sh`
Expected: `✅ clean-room: no ocelescope dependency or import found`

- [ ] **Step 2: Full suites**

Run: `cd /home/smudoshi/Github/Zephyrus/arena && python -m pytest -q`
Run: `php artisan test --filter=Arena`
Expected: both green

- [ ] **Step 3: End-to-end smoke (optional, requires DB + sidecar)**

Run:
```bash
php artisan ocel:project --quantities-only
curl -s "localhost/api/arena/capacity" -H 'accept: application/json' | head -c 400
```
Expected: a `objects[]` array of per-unit occupancy series (or `available:false` if the sidecar is down).

---

## Self-review checklist (run before handoff)

- **Spec coverage:** quantity tables (Task 1) ✓; occupancy classification + IO (Tasks 2–3) ✓; export (Task 4) ✓; sidecar series (Task 5) ✓; orchestration (Task 6) ✓; projection wiring (Task 7) ✓; frontend curve (Task 8) ✓.
- **Type consistency:** `QuantityProjector::computeQuantities` returns `{initial, operations}` with keys `object_id/item_type/quantity` and `event_id/object_id/item_type/delta/event_time` — identical in `QuantityExporter`, the `ocel.quantity_operations` columns (Task 1 migration), and the sidecar `capacity.series` reader. `item_type` default `'occupied_beds'` matches across projector const, service default, and controller default. Sidecar `series` output keys (`object_id/item_type/series/peak/nadir/current`) match `CapacityResponse` (Py) and `arenaCapacityResponseSchema` (TS).
- **No placeholders:** every step has complete code. The `--quantities-only` wiring in Task 7 Step 3 names the exact variables to match (`$since`/`$until`) and the fallback (`null, null`) — an instruction to mirror existing code, not a placeholder. Transfer-event occupancy is explicitly deferred as an additive follow-up (design note in `QuantityProjector`), not a gap in the tasks as scoped (admit/discharge occupancy).
- **PHI:** every new surface carries unit-level counts only; no patient/encounter identity enters the quantity tables, the export, the sidecar, or the chart.
