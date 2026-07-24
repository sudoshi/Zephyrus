# Arena OCPM — ocelescope Integration (XO.1 / XO.2 / XO.3) — Devlog 2026-07-11

## Summary

Investigated **[promi4s/ocelescope](https://github.com/promi4s/ocelescope)** (RWTH Aachen
PADS — the group behind the OCEL 2.0 standard) and adopted three of its capabilities into
the Zephyrus **Patient-Flow Arena** (Part X) as **clean-room, Apache-2.0 reimplementations**.
ocelescope is used **only as a design reference**; none of its source is installed, vendored,
or copied — because the upstream is **AGPL-3.0** and network-linking it would trigger AGPL
§13's source-disclosure clause against a proprietary, network-deployed product.

Three phases shipped, each an independently-valuable vertical slice, all behind the existing
`ARENA_ENABLED` gate:

| Phase | Capability | Merge | What it buys |
|---|---|---|---|
| **XO.1** | Composable OCEL filter engine | `32340ba` | Slice any Arena view by object-type / event-type / time window / attribute |
| **XO.2** | OC Petri-net discovery + token-replay fitness | `d35ea61` | A real control-flow model per object type; a hardened copilot trust-gate |
| **XO.3** | QEL occupancy / capacity layer | `860cb3e` | Per-unit bed census over time, mined from the log itself — the RTDC twin |

**Net:** 56 files changed, +5271 / −62 across 23 commits. Executed with
**subagent-driven development** (a fresh implementer per task + two-stage adversarial review),
which caught and fixed several real bugs before merge. All three merged to `main`, pushed to
`origin`, and **deployed to prod 2026-07-11** — live and functional. `scripts/check-clean-room.sh`
guards CI against any future `ocelescope` dependency leak.

Master roadmap: `docs/superpowers/plans/2026-07-10-ocelescope-ocpm-integration-roadmap.md`.
Sub-plans: `…-arena-ocel-filter-engine.md`, `…-arena-oc-petrinet-alignment.md`,
`…-arena-qel-capacity.md`. Provenance discipline: `arena/CLEAN-ROOM.md`.

---

## The licensing decision (the gate everything hangs on)

ocelescope is a research OCPM platform: a pandas-backed OCEL 2.0 class with an 11-filter
composable engine, inductive-miner OC Petri-net discovery, a QEL (quantity event log)
extension, and a `@plugin_method`-driven FastAPI plugin host that auto-generates its React UI.
Attractive shape; wrong licence.

- The repository root `LICENSE` is **AGPL-3.0**, and there is **no separate licence** on the
  `src/ocelescope` library — the root AGPL governs it. The PyPI package declares **no licence
  at all** (`license: None`), which grants no rights on its own. Safe reading: **the entire
  stack, library included, is AGPL-3.0.**
- Zephyrus is a **network-deployed product** with protected proprietary components
  (`.claude/rules/auth-system.md`). Importing or linking AGPL code triggers AGPL §13's
  network-use clause — it would require offering Zephyrus's source under AGPL. Incompatible
  with the project's Apache-2.0 posture.

Two paths were rejected: **(a)** `pip install ocelescope` — rejected on licence; **(b)** run
`ocelescope-backend` as an arms-length HTTP sidecar (legally viable — a network API call is
not a derivative work) — rejected because the upstream is self-described *"not
production-ready,"* ships its own React UI we cannot adopt into the canon, and would fork our
Arena's operational surface. We already own a production Arena sidecar; we extended it.

**Decision (user, 2026-07-10): clean-room reimplementation under Apache-2.0.** The five rules
(from the roadmap §3, enforced by review):

1. **No dependency on ocelescope** anywhere (`requirements.txt`, `pyproject.toml`,
   `composer.json`, `package.json`). CI greps for it.
2. **No copied code.** Reimplement the *pattern* from the public API shape + OCPM literature
   (Berti & van der Aalst OC-DFG; OPerA; token-based replay). Our identifiers, file layout, and
   contracts are our own (flat `nodes/edges`/`{initial,operations}` JSON, not ocelescope's
   pydantic `Resource` classes).
3. **Apache-2.0 headers** — new files carry no AGPL notice.
4. **Reference, not artifact** — the cloned repo was a scratch reference for the author only;
   never committed, never a submodule, never a build input.
5. **Attribution in prose only** — cite the OCEL papers in a docstring; never imply code
   lineage.

`arena/CLEAN-ROOM.md` records this in-tree so no future contributor `pip install`s the thing;
`scripts/check-clean-room.sh` hard-fails CI on any leak.

---

## Starting state

Part X was already live on prod (2026-07-05): the `ocel.*` schema (X0), the Python FastAPI
OCPM sidecar `zephyrus-arena.service:8101` running pm4py 2.7.23.1 (X1), OPerA performance (X2),
pathway conformance (X3), and the governed AI copilot (X4). The Study UI at `/analytics/arena`
rendered an OC-DFG, performance candidates, and conformance signals.

What it lacked, and what these three phases added:

| Capability | Before | After |
|---|---|---|
| Interactive filtering | ❌ | ✅ object-type / event-type / time / attribute (XO.1) |
| OC Petri net | ❌ | ✅ places / transitions / variable-arcs per object type (XO.2) |
| Copilot trust-gate | DFG edge-set recall only | + per-object-type token-replay fitness (XO.2) |
| Capacity over time from the log | ❌ | ✅ QEL quantity ops → per-unit census curve (XO.3) |

---

## What shipped

### XO.1 — Composable OCEL filter engine (`32340ba`)

A mask-based filter engine so any Arena view can be sliced server-side.

- **`arena/app/filters.py`** — `BaseFilter(ABC, BaseModel)` with an abstract `mask()`;
  `ObjectTypeFilter` / `EventTypeFilter` / `TimeFrameFilter` / `EventAttributeFilter` (each a
  `kind` discriminator + include/exclude mode); `parse_filters(specs)` (discriminated union,
  raises `ValueError` on a bad `kind`); `apply_filters(ocel, filters)` AND-combines masks,
  prunes E2O relations **and** the OCEL 2.0 optional frames (`o2o` / `e2e` / `object_changes`),
  drops orphaned events, and rebuilds the pm4py OCEL.
- Wired into `discovery` / `performance` / `conformance` (each gained an optional `filters=`
  arg applied after `read_ocel`).
- Laravel: `ArenaSidecarClient` / `ArenaService` forward the filter pipeline; a
  `filterSignature()` folds into the `arena.maps` cache key so each filter set caches
  independently. `ArenaController::filtersFrom(Request)` decodes a JSON-encoded `filters` query
  param (validated, capped at 12).
- Frontend: `FilterBar.tsx` time-window control across the Study views (object-type selection
  already existed in the map controls, so the new surface is the non-redundant time filter).

**Adversarial review caught, pre-merge:** (1) the rebuild silently **dropped the OCEL 2.0
optional frames** on filter — fixed by pruning each frame to surviving ids; (2)
`<input type="date">` emits **midnight**, so an inclusive `ts <= end` dropped every event on the
chosen end-day — fixed by emitting `T23:59:59`.

### XO.2 — OC Petri-net discovery + replay fitness (`d35ea61`)

- **`arena/app/petrinet.py`** — `discover(path, filters=)` mines an object-centric Petri net
  (`pm4py.discover_oc_petri_net`) and serializes each per-object-type subnet to a flat canon
  contract: `places` (with `initial`/`final` markers), `transitions` (`label=null` ⇒ silent τ),
  `arcs` (`variable` set from `double_arcs_on_activity` = synchronization across object counts).
  Exposed as `POST /discover/petrinet`.
- **`arena/app/replay.py`** — `fitness(path, object_types=, filters=)` flattens the OCEL per
  object type (`pm4py.ocel_flattening`), mines an inductive Petri net, and computes
  **token-based replay fitness** (`pm4py.fitness_token_based_replay`) with a min/mean aggregate.
  This is a model-based conformance signal, strictly stronger than the copilot's DFG edge-set
  recall.
- **Copilot gate hardening (strictly additive).** `copilot.structural_cross_check()` scores the
  object types a copilot-proposed map asserts and returns two default-empty fields on
  `ModelFitnessResponse` (`structural_fitness_by_type`, `structural_warnings`). The existing
  `published` decision is **byte-for-byte unchanged** — all five governed published-decision
  tests still pass. A proposed object type with **zero events in the log** (the extreme of "the
  AI claims structure that isn't there") earns a `no_events_in_log` warning instead of being
  silently dropped — a gap review flagged and I closed.
- Laravel `ArenaSidecarClient/ArenaService::petrinet` passthrough + **GET** `/api/arena/petrinet`;
  React `PetriNetPane.tsx` renders a per-object-type structural summary (places / transitions /
  silent-τ / variable-arc counts).

**Review caught, pre-merge:** the plan template called `.get()` on pm4py's return, but
`discover_oc_petri_net` returns a dict-**like** `OCPetriNet` (not a real `dict`) — switched to
subscript + `in`; plus an `object_types`-vs-`stats` divergence and the convoluted variable-arc
`None`-key lookup, both cleaned.

### XO.3 — QEL occupancy / capacity layer (`860cb3e`)

Per-unit bed occupancy over time, derived **purely from the already-projected `ocel.*` log** —
a projection of a projection, so it can never drift from what the cockpit shows.

- **Migration `2026_07_10_000400_create_ocel_quantity_tables`** — two additive `ocel.*` tables:
  `object_quantities` (`object_id, item_type, quantity`) and `quantity_operations`
  (`event_id, object_id, item_type, delta, event_time`). Guarded (`hasTable` + `CREATE SCHEMA IF
  NOT EXISTS`), reversible, `SafeMigration`. Removable by dropping both; the rest of `ocel.*` is
  untouched.
- **`QuantityProjector`** — admit/place/register = **+1**, discharge/depart = **−1** on the
  referenced `Unit`. Initial occupancy at the window floor = net of the same events *before* the
  floor, clamped ≥ 0. Reads `ocel.*` only (never `prod.*`), upserts idempotently. Unit-level
  counts only — **PHI-safe by construction**.
- **`QuantityExporter`** — flat `{initial, operations}` payload.
- **`arena/app/capacity.py`** — `series(payload, item_type?, threshold?)` reconstructs the
  absolute occupancy curve per object as `initial + cumsum(operations)`. **Pure pandas, no
  pm4py** — `/capacity` works even in a mining-less sidecar build. Returns
  `{objects:[{object_id, item_type, series:[{time,value}], peak, nadir, current}], stats}`.
- Laravel `ArenaSidecarClient/ArenaService::capacity` (+ `QuantityExporter` DI) + **GET**
  `/api/arena/capacity`; `ocel:project` gained `--quantities-only`; React `CapacityPane.tsx`
  renders the per-unit census curve (Recharts `stepAfter` line).

**Review caught, pre-merge:** a muddled Task-2/3 commit split (collapsed into one honest
commit); missing `RefreshDatabase` traits on the DB-seeding tests (the test DB won't have
`ocel.*` without it); a `threshold` int-cast and an inline-FQCN in the orchestration layer; and
I *added* coverage the plan lacked — an initial-occupancy integration test and `available`-envelope
service tests.

---

## Architecture & data flow

Everything is **additive**: new `ocel.*` tables, new sidecar modules/endpoints, new Laravel
methods/routes, new React panes. No `prod.*` mutation, no change to the Part X object-type /
activity catalog, no breaking change to an existing endpoint.

**XO.3 end-to-end (the deepest slice), traced key-by-key across eight hops:**

```
ocel.events (admit/discharge on a Unit)
   │  QuantityProjector (PHP)  admit=+1 / discharge=-1, initial = net pre-floor
   ▼
ocel.quantity_operations / ocel.object_quantities      {event_id,object_id,item_type,delta,event_time} / {object_id,item_type,quantity}
   │  QuantityExporter (PHP)
   ▼
{initial:[…], operations:[…]}
   │  ArenaSidecarClient::capacity  → POST 127.0.0.1:8101/capacity
   ▼
capacity.series (Python / pandas)   base + cumsum, baseline in peak/nadir
   ▼
{objects:[{object_id,item_type,series:[{time,value}],peak,nadir,current}], stats}
   │  CapacityResponse (Pydantic) → JSON
   │  ArenaService::capacity  wraps ['available'=>true] + result   (or {available:false,reason})
   ▼
GET /api/arena/capacity  → fetchArenaCapacity → useArenaCapacity → page-level Zod safeParse
   ▼
CapacityPane (Recharts)   per-unit census curve
```

The XO.1 filter pipeline composes with everything upstream of the sidecar; XO.2's Petri
net/replay follow the same `discover`/`copilot` transport the DFG already used.

---

## Methodology — subagent-driven development

Per the session's chosen workflow (`superpowers:subagent-driven-development`): a fresh
implementer subagent per task, followed by a two-stage review (spec-compliance, then code
quality), looping until clean; a final holistic cross-cutting review per phase before merge.
Reconnaissance (a read-only `Explore` agent) mapped the exact integration points up front so
each implementer got precise, reconciled instructions rather than the plan's assumptions.

This earned its keep. Beyond the per-phase bugs above, reconnaissance caught **plan drift** the
same way XO.1 had: the plan snippets used `POST` + `route('api.arena.capacity')` and
`z.record(z.number())`, but the established Arena pattern is **GET, literal-URL `api.ts`
transport, page-level `safeParse`, and zod-v4 `z.record(z.string(), z.number())`**. Reconciled
before the implementer touched a file. The final holistic reviews traced every cross-layer
contract (PHP → PG → PHP → JSON → pandas → Pydantic → JSON → Zod → React) and confirmed the
additive-only guarantee on the governed copilot gate.

---

## Testing

All green on merged `main` and re-verified on the deployed prod result:

- **Sidecar (pytest, `arena/.venv`):** 34 passed — filter engine (9), Petri net (2), replay (4),
  copilot structural (7 incl. the 5 pre-existing governed tests), capacity (2), + existing.
- **PHP (`php artisan test`):** full suite **773 passed, 1 skipped, 0 failures** (9919
  assertions); Arena feature suite 35.
- **Frontend:** `npx tsc --noEmit` 0 errors; `npx vite build` clean.
- **Clean-room guard:** `scripts/check-clean-room.sh` passes (no `ocelescope` anywhere).

**Env gotchas worth remembering:**
- pm4py lives **only** in the dev venv → run sidecar tests with `arena/.venv/bin/python -m
  pytest`, not bare `python`.
- Sidecar route tests need **`httpx2`** in the venv — starlette 1.3.1 does `import httpx2 as
  httpx` (the real httpx 2.x line, **not** a typosquat). Noted test-only in `requirements.txt`.
- Every DB-seeding PHP test class must **explicitly** `use RefreshDatabase` — `php artisan test`
  here is PHPUnit-style, and without the trait the `ocel.*` schema isn't migrated into
  `zephyrus_test`.
- `scripts/check-ui-canon.sh` is **pre-existing red** on `main` (`ProcessModelLandscape.tsx` /
  `ReferenceProcessMap.tsx` `font-bold`+`text-[Npx]`, raw-palette ratchet 80>76). All three
  phases add **zero** new violations (proven via `git stash`). Recharts `tick={{ fontSize: 11 }}`
  is a JS/SVG prop, not a `text-[Npx]` className — canon-safe.

---

## Prod deployment (executed 2026-07-11)

Non-destructive, step-by-step. The Arena sidecar **code lives at `/var/www/Zephyrus/arena/`**
(the systemd `WorkingDirectory`) but runs off the venv at `/opt/arena-sidecar/venv` — so
`deploy.sh`'s repo-wide rsync updates the sidecar code; it just needs a service restart.

1. **Pushed** `main` → `origin` (`1e8c4e9`). `deploy.sh` refuses to run if local is behind
   remote, so push first. Also patched `deploy.sh` to `--exclude 'arena/.venv' '__pycache__'
   '.pytest_cache'` from the rsync (dev venv must not leak to prod; rsync ignores `.gitignore`).
2. **`./deploy.sh`** — rebuilt the frontend, rsynced app + `arena/` code, chowned `www-data`,
   cleared caches, restarted Apache + queue worker, verified the site. **Did NOT** set
   `DEPLOY_RUN_MIGRATIONS=1` (that path runs a **bare** `migrate --force`, which is catastrophic
   here — prod migration tracking is unreliable).
3. **Targeted migration** as `www-data` with `HOME=/tmp XDG_*=/tmp`:
   `php artisan migrate --path=database/migrations/2026_07_10_000400_create_ocel_quantity_tables.php --force`
   → additive, guarded, 224 ms, both tables live.
4. **Refreshed the sidecar:** cleared stale bytecode (`find /var/www/Zephyrus/arena -name
   __pycache__ -exec rm -rf`), then `systemctl restart zephyrus-arena.service` (picks up
   petrinet/replay/capacity/filters) **and** `systemctl restart php8.5-fpm` (deploy.sh restarts
   only Apache + the queue worker, not fpm → opcache would otherwise serve stale PHP).
5. **Backfilled occupancy:** `php artisan ocel:project --quantities-only` → **1723 operations**
   projected from the existing prod OCEL log.

**Verified live:** sidecar `/capacity` returns the correct cumulative series; health reports
pm4py 2.7.23.1 with a clean startup (no import errors); `ArenaService::capacity()` end-to-end =
`available=true, 488 units`; `api/arena/{capacity,petrinet}` registered; `/analytics/arena` →
HTTP 200; all four services active; gates on (`ARENA_ENABLED=true`, `ARENA_AI_ENABLED=true`).

---

## Demo howto — try the new features

There are two ways in: the **browser** (the Study UI, what an operator sees) and **curl/artisan**
(what proves the plumbing). The sidecar (`127.0.0.1:8101`) is host-local; the Laravel
`/api/arena/*` routes are `auth`-gated, so browser is the easy path there.

### 0. Prerequisites

- The feature is behind `ARENA_ENABLED=true` (already set on prod). Locally, set it in `.env`
  and `php artisan config:clear`.
- Sidecar running: `systemctl is-active zephyrus-arena.service` → `active`
  (`curl -s localhost:8101/health` should report `pm4py_available:true`).
- Occupancy projected at least once (prod is backfilled): `php artisan ocel:project
  --quantities-only`.
- Open **`/analytics/arena`** (authenticated). You'll see the OC-DFG map, then the sections
  below stacked underneath it.

### 1. XO.1 — Filter engine (time window)

**Browser:** at the top of the Study surface, the **Time window** control (`FilterBar`) has
**From** / **To** date inputs. Pick a range and every view — the OC-DFG map, performance
candidates, and conformance — recomputes server-side to just that window. Clear resets it. Each
filter set caches independently (the `filterSignature` in the `arena.maps` cache key), so
re-selecting a prior window is instant.

**Prove it (curl, host-local, filters are a JSON-encoded query param):**
```bash
# Unfiltered vs. a Jan-2026 window — compare node/edge counts
curl -s "localhost/api/arena/map" -H "Host: zephyrus.acumenus.net"   # (auth-gated; use the browser, or run via tinker below)

# Sidecar-direct: apply a TimeFrameFilter to a discover call
#   filters is an ordered list; each item is discriminated by `kind`.
```
Via `tinker` (bypasses HTTP auth), compare a filtered vs unfiltered map:
```php
php artisan tinker --execute='
  $svc = app(App\Domain\Arena\ArenaService::class);
  $all  = $svc->map();                                   // unfiltered
  $win  = $svc->map(filters: [["kind"=>"time_frame","start"=>"2026-01-01T00:00:00","end"=>"2026-03-31T23:59:59"]]);
  echo "all nodes=".count($all["map"]["nodes"] ?? [])." | windowed nodes=".count($win["map"]["nodes"] ?? []);'
```
Filter kinds: `object_type` (`object_types:[…]`), `event_type` (`activities:[…]`), `time_frame`
(`start`/`end` ISO8601), `event_attribute` (`name`/`values:[…]`), each with optional
`mode:"include"|"exclude"`.

### 2. XO.2 — OC Petri net (structural model)

**Browser:** the **"OC Petri-net model"** section shows one card per object type with counts of
**Places / Transitions / Silent (τ) / Variable arcs** — a linear lifecycle shows few
transitions and no variable arcs; a branchy one shows many. It composes with the XO.1 time
window.

**Prove it (sidecar-direct):**
```bash
curl -s -X POST localhost:8101/discover/petrinet \
  -H 'content-type: application/json' \
  -d '{"ocel":{
        "objectTypes":[{"name":"Encounter","attributes":[]}],
        "eventTypes":[{"name":"triage","attributes":[]},{"name":"admit","attributes":[]},{"name":"discharge","attributes":[]}],
        "objects":[{"id":"enc1","type":"Encounter","attributes":[],"relationships":[]}],
        "events":[
          {"id":"e1","type":"triage","time":"2026-01-01T00:00:00Z","attributes":[],"relationships":[{"objectId":"enc1","qualifier":"subject"}]},
          {"id":"e2","type":"admit","time":"2026-01-01T01:00:00Z","attributes":[],"relationships":[{"objectId":"enc1","qualifier":"subject"}]},
          {"id":"e3","type":"discharge","time":"2026-01-01T02:00:00Z","attributes":[],"relationships":[{"objectId":"enc1","qualifier":"subject"}]}]}}' | jq '.stats, (.nets[0] | {object_type, places:(.places|length), transitions:(.transitions|length)})'
```
Or against the live prod log via `tinker`:
```php
php artisan tinker --execute='
  $r = app(App\Domain\Arena\ArenaService::class)->petrinet();
  echo "available=".json_encode($r["available"] ?? null)." object_types=".json_encode($r["object_types"] ?? []);
  echo " stats=".json_encode($r["stats"] ?? []);'
```

### 3. XO.2 — Token-replay fitness (the hardened copilot gate)

Replay fitness is a **model-based** conformance signal per object type (how well the real
behaviour fits a structured Petri net), stronger than the copilot's DFG edge-set recall. It
rides **additively** on the copilot `model-fitness` verdict — the existing publish/withhold
decision is unchanged; it only *arms the narrative with a caveat*. Requires
`ARENA_AI_ENABLED=true`.

**Prove it (sidecar-direct — a perfectly-structured log fits near-1.0):**
```bash
# via the copilot structural cross-check, called from the model-fitness path; or unit-probe replay:
arena/.venv/bin/python -c '
import json, tempfile
from app import replay
doc={"objectTypes":[{"name":"Encounter","attributes":[]}],
     "eventTypes":[{"name":a,"attributes":[]} for a in ("triage","admit","discharge")],
     "objects":[{"id":e,"type":"Encounter","attributes":[],"relationships":[]} for e in ("enc1","enc2")],
     "events":[{"id":f"{e}-{a}","type":a,"time":f"2026-01-0{d}T0{i}:00:00Z","attributes":[],
                "relationships":[{"objectId":e,"qualifier":"subject"}]}
               for e,d in (("enc1","1"),("enc2","2")) for i,a in enumerate(("triage","admit","discharge"))]}
f=tempfile.NamedTemporaryFile("w",suffix=".json",delete=False); json.dump(doc,f); f.close()
print(replay.fitness(f.name))'   # → Encounter fitness ~1.0
```
A copilot that proposes edges for an object type with **no events in the log** now earns a
`no_events_in_log` structural warning in the `model-fitness` response — the exact "structure
that isn't there" case the gate exists to catch.

### 4. XO.3 — Unit occupancy / capacity curve (the RTDC twin)

**Browser:** the **"Unit occupancy (census)"** section renders a per-unit **census curve** — a
`stepAfter` line of bed occupancy over time, with `peak N · now N` in each card header. This is
the process-intelligence twin of the RTDC census, reconstructed purely from the OCEL log
(admit = +1, discharge = −1). If nothing is projected, the empty state explains there's no
admit/discharge activity in the window.

**Backfill / refresh occupancy (safe, additive — reads `ocel.*`, writes only the two QEL tables):**
```bash
php artisan ocel:project --quantities-only     # occupancy only, skips the base projection
# or, as part of the full projection:
php artisan ocel:project                        # base OCEL projection + quantities
```

**Prove it (sidecar-direct — cumulative reconstruction, no DB needed):**
```bash
curl -s -X POST localhost:8101/capacity -H 'content-type: application/json' -d '{
  "quantities":{
    "initial":[{"object_id":"Unit:5N","item_type":"occupied_beds","quantity":2}],
    "operations":[
      {"object_id":"Unit:5N","item_type":"occupied_beds","delta":1,"event_time":"2026-01-01T00:00:00Z"},
      {"object_id":"Unit:5N","item_type":"occupied_beds","delta":-1,"event_time":"2026-01-01T02:00:00Z"}]}}' | jq
# → objects[0]: series [3,2], peak 3, nadir 2, current 2
```

**Prove it end-to-end against the live log (`tinker`):**
```php
php artisan tinker --execute='
  $r = app(App\Domain\Arena\ArenaService::class)->capacity();
  echo "available=".json_encode($r["available"])." units=".count($r["objects"] ?? []);
  if(!empty($r["objects"])){$u=$r["objects"][0];
    echo " | top ".$u["object_id"]." peak=".$u["peak"]." now=".$u["current"]." pts=".count($u["series"]);}'
```

---

## Known limitations & follow-ups

- **Occupancy is log-bounded.** Occupancy is derived purely from what the OCEL log emits, so a
  unit whose admit events aren't matched by a discharge/depart on the *same* `Unit` shows a
  monotonically-climbing curve (observed on prod: `unit-ed-triage-002` peak = now = 92, 92
  `+1` points). This is faithful to the log, not a bug — **balancing admit↔discharge per unit in
  the Part X `EmissionMap`** is the follow-up that makes the census physically realistic. Units
  with a balanced lifecycle already render correct curves. Initial occupancy is clamped ≥ 0
  because a patient admitted before the projection horizon is invisible.
- **`threshold` / `periods_above_threshold`** is wired controller → sidecar (a "time above
  capacity" knob) but not yet surfaced in the Zod schema / UI — a deferred, sidecar-first
  additive increment (like XO.2's `structural_warnings`, also produced but not yet rendered).
- **Full node-link Petri-net layout** is deferred; the XO.2 pane is the structural-summary MVP
  (the counts already tell an operator linear-vs-branchy). Reusing the existing `OcdfgMap` SVG
  machinery for a proper places/transitions render is the additive next step.
- **Canon debt (pre-existing, unrelated):** `check-ui-canon.sh` is red on `main` from
  `ProcessModelLandscape.tsx` / `ReferenceProcessMap.tsx`; worth a dedicated cleanup pass if CI
  ever gates on canon.

---

## Commit ledger

```
XO.1  32340ba  Merge XO.1: composable OCEL filter engine
      6e340ba  feat(arena): time-window filter surfaced across Arena views
      8fb7da9  feat(arena): forward + cache-key the OCEL filter pipeline
      370103d  feat(arena): apply OCEL filter pipeline to discovery/performance/conformance
      cd07b26  feat(arena): clean-room composable OCEL filter engine

XO.2  d35ea61  Merge XO.2: OC Petri-net discovery + replay fitness
      0743505  refactor(arena): self-guard replay.fitness against missing pm4py
      28475e9  feat(arena): surface OC Petri net in the Study UI
      d05a789  feat(arena): additive structural replay-fitness cross-check on the copilot gate
      95007d0  feat(arena): per-object-type token-based replay fitness
      9b8ff6c  feat(arena): /discover/petrinet route + response contract
      c4d33d1  feat(arena): object-centric Petri-net discovery (flat canon contract)

XO.3  860cb3e  Merge XO.3: QEL occupancy/capacity layer
      ac3f79d  feat(arena): per-unit occupancy/capacity curve in the Study UI
      98962f0  feat(ocel): project QEL quantities as part of ocel:project
      56671e6  feat(arena): capacity orchestration (client + service + route)
      497c41b  feat(arena): /capacity endpoint reconstructs per-unit occupancy series
      32d83dc  feat(ocel): QuantityExporter flat {initial,operations} payload
      4bb1d00  feat(ocel): QuantityProjector — occupancy classification + ocel.* projection
      b7dfad7  feat(ocel): additive QEL quantity tables (oqty + qop)

Docs  8ccbfdc  docs(arena): OCPM integration roadmap + XO.1-3 plans; ignore arena/.venv
      f11332e  chore(arena): record clean-room OCPM discipline + CI guard
Deploy 1e8c4e9 chore(deploy): exclude dev python venv + caches from prod rsync
```

*Net: 56 files changed, +5271 / −62. Clean-room throughout — no dependency on the AGPL
ocelescope project; `scripts/check-clean-room.sh` guards CI.*
