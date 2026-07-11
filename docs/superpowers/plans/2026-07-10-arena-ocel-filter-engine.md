# Arena OCEL Filter Engine (Phase XO.1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a composable, server-side OCEL filter engine to the Arena sidecar so every view (map, performance, conformance) can be restricted by object-type, event-type, time window, and event-attribute — recomputed and cached per filter signature.

**Architecture:** A clean-room, mask-based filter layer in `arena/app/filters.py` (no `ocelescope` dependency). Each filter produces a boolean mask over the pm4py OCEL's `events`/`objects` DataFrames; masks AND-combine; `apply_filters` slices the frames, prunes relations, and rebuilds a pm4py `OCEL`. The three analysis entrypoints apply the filters immediately after loading. Laravel passes a validated `filters[]` array through and folds it into the map cache key.

**Tech Stack:** Python 3.12, FastAPI, pandas, `pm4py>=2.7,<3`, Pydantic v2 (sidecar); Laravel 11 / PHP 8.5 (orchestrator); React 19 + Zod (frontend).

**Provenance:** Clean-room per [`2026-07-10-ocelescope-ocpm-integration-roadmap.md`](./2026-07-10-ocelescope-ocpm-integration-roadmap.md) §3. ocelescope is a reference only; nothing is copied or installed.

---

### Task 1: Record the clean-room discipline + add a CI guard

**Files:**
- Create: `arena/CLEAN-ROOM.md`
- Create: `scripts/check-clean-room.sh`

- [ ] **Step 1: Write the clean-room note**

Create `arena/CLEAN-ROOM.md`:

```markdown
# Clean-room provenance (Arena OCPM)

The Arena's OCPM capabilities (filter engine, OC Petri-net discovery, QEL capacity)
are **clean-room reimplementations** inspired by ocelescope
(https://github.com/promi4s/ocelescope), which is AGPL-3.0.

DO NOT:
- add `ocelescope` to `requirements.txt` or any dependency manifest
- copy ocelescope source into this repository

Reimplement patterns from the public API shape and the OCPM literature
(Berti & van der Aalst, object-centric process mining). All code here is
Apache-2.0 under the repository license.
```

- [ ] **Step 2: Write the CI guard**

Create `scripts/check-clean-room.sh`:

```bash
#!/usr/bin/env bash
# Fails if the AGPL 'ocelescope' package leaks into a dependency manifest or import.
set -euo pipefail
cd "$(dirname "$0")/.."

hits=$(grep -RInE '(^|[^a-z])ocelescope' \
  arena/requirements.txt \
  arena/app \
  composer.json package.json 2>/dev/null \
  | grep -viE 'CLEAN-ROOM|clean-room|# reference|inspired by' || true)

if [ -n "$hits" ]; then
  echo "❌ clean-room violation: 'ocelescope' referenced as code/dependency:"
  echo "$hits"
  exit 1
fi
echo "✅ clean-room: no ocelescope dependency or import found"
```

- [ ] **Step 3: Make it executable and run it**

Run: `chmod +x scripts/check-clean-room.sh && ./scripts/check-clean-room.sh`
Expected: `✅ clean-room: no ocelescope dependency or import found`

- [ ] **Step 4: Commit**

```bash
git add arena/CLEAN-ROOM.md scripts/check-clean-room.sh
git commit -m "chore(arena): record clean-room OCPM discipline + CI guard"
```

---

### Task 2: The filter engine (`arena/app/filters.py`)

**Files:**
- Create: `arena/app/filters.py`
- Test: `arena/tests/test_filters.py`

- [ ] **Step 1: Write the failing test**

Create `arena/tests/test_filters.py`:

```python
"""Filter engine — clean-room mask-based OCEL filtering (Phase XO.1).

Builds a tiny in-memory pm4py OCEL fixture (no file IO) and asserts each filter
plus the composition/pruning semantics: an event that loses all its objects is
dropped, object filters prune relations, and filters AND-compose.
"""

from __future__ import annotations

import pandas as pd
import pytest

pm4py = pytest.importorskip("pm4py")
from pm4py.objects.ocel.obj import OCEL as PMOCEL  # noqa: E402

from app.filters import (  # noqa: E402
    EventAttributeFilter,
    EventTypeFilter,
    ObjectTypeFilter,
    TimeFrameFilter,
    apply_filters,
    parse_filters,
)

OCEL_EID = "ocel:eid"
OCEL_OID = "ocel:oid"
OCEL_ACTIVITY = "ocel:activity"
OCEL_TYPE = "ocel:type"
OCEL_TIME = "ocel:timestamp"


def _toy_ocel() -> PMOCEL:
    ts = pd.to_datetime(
        ["2026-01-01T00:00:00Z", "2026-01-01T01:00:00Z", "2026-01-01T02:00:00Z"], utc=True
    )
    events = pd.DataFrame(
        {
            OCEL_EID: ["e1", "e2", "e3"],
            OCEL_ACTIVITY: ["triage", "admit", "discharge"],
            OCEL_TIME: ts,
            "acuity": ["ESI-2", "ESI-2", "ESI-4"],
        }
    )
    objects = pd.DataFrame(
        {OCEL_OID: ["enc1", "pat1", "bed1"], OCEL_TYPE: ["Encounter", "Patient", "Bed"]}
    )
    relations = pd.DataFrame(
        {
            OCEL_EID: ["e1", "e1", "e2", "e2", "e3", "e3"],
            OCEL_OID: ["enc1", "pat1", "enc1", "bed1", "enc1", "bed1"],
            OCEL_TYPE: ["Encounter", "Patient", "Encounter", "Bed", "Encounter", "Bed"],
            OCEL_ACTIVITY: ["triage", "triage", "admit", "admit", "discharge", "discharge"],
            OCEL_TIME: [ts[0], ts[0], ts[1], ts[1], ts[2], ts[2]],
            "ocel:qualifier": ["subject", "subject", "subject", "location", "subject", "location"],
        }
    )
    return PMOCEL(events=events, objects=objects, relations=relations)


def test_object_type_exclude_prunes_relations_but_keeps_events():
    ocel = apply_filters(_toy_ocel(), [ObjectTypeFilter(object_types=["Bed"], mode="exclude")])
    assert set(ocel.objects[OCEL_OID]) == {"enc1", "pat1"}
    assert "bed1" not in set(ocel.relations[OCEL_OID])
    # e2/e3 still touch enc1, so they survive; no relation points at bed1
    assert set(ocel.events[OCEL_EID]) == {"e1", "e2", "e3"}


def test_event_type_include_keeps_only_matching_events():
    ocel = apply_filters(_toy_ocel(), [EventTypeFilter(activities=["triage"], mode="include")])
    assert set(ocel.events[OCEL_EID]) == {"e1"}
    assert set(ocel.relations[OCEL_EID]) == {"e1"}


def test_time_frame_start_keeps_later_events():
    ocel = apply_filters(_toy_ocel(), [TimeFrameFilter(start="2026-01-01T01:00:00Z")])
    assert set(ocel.events[OCEL_EID]) == {"e2", "e3"}


def test_event_attribute_include():
    ocel = apply_filters(_toy_ocel(), [EventAttributeFilter(name="acuity", values=["ESI-4"])])
    assert set(ocel.events[OCEL_EID]) == {"e3"}


def test_filters_and_compose():
    ocel = apply_filters(
        _toy_ocel(),
        [
            EventTypeFilter(activities=["admit", "discharge"], mode="include"),
            ObjectTypeFilter(object_types=["Bed"], mode="exclude"),
        ],
    )
    assert set(ocel.events[OCEL_EID]) == {"e2", "e3"}
    assert "bed1" not in set(ocel.objects[OCEL_OID])


def test_parse_filters_builds_typed_filters():
    filters = parse_filters(
        [
            {"kind": "object_type", "object_types": ["Bed"], "mode": "exclude"},
            {"kind": "time_frame", "start": "2026-01-01T01:00:00Z"},
        ]
    )
    assert isinstance(filters[0], ObjectTypeFilter)
    assert isinstance(filters[1], TimeFrameFilter)


def test_parse_filters_rejects_unknown_kind():
    with pytest.raises(ValueError):
        parse_filters([{"kind": "nonsense"}])
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/smudoshi/Github/Zephyrus/arena && python -m pytest tests/test_filters.py -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'app.filters'`

- [ ] **Step 3: Write minimal implementation**

Create `arena/app/filters.py`:

```python
"""Composable, mask-based OCEL filtering (Part X, Phase XO.1).

Clean-room reimplementation (see arena/CLEAN-ROOM.md): each filter produces a
boolean mask over the pm4py OCEL's `events` / `objects` DataFrames. Masks
AND-combine; `apply_filters` slices the frames, prunes E2O relations to the
surviving events and objects, drops any event left touching no object (the OCEL
invariant the exporter validates), and rebuilds a pm4py OCEL. Read-only and
PHI-free — filters operate on de-identified ids, activity labels, timestamps and
event attributes only.
"""

from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass
from datetime import datetime
from typing import Any, Literal, Optional, Sequence

import pandas as pd
from pm4py.objects.ocel.obj import OCEL as PMOCEL
from pydantic import BaseModel

OCEL_EID = "ocel:eid"
OCEL_OID = "ocel:oid"
OCEL_ACTIVITY = "ocel:activity"
OCEL_TYPE = "ocel:type"
OCEL_TIME = "ocel:timestamp"


@dataclass
class FilterResult:
    """A partial verdict: an events mask, an objects mask, or either. `None` means
    the filter is silent on that dimension (treated as all-True when combined)."""

    events: Optional[pd.Series] = None
    objects: Optional[pd.Series] = None


class BaseFilter(ABC, BaseModel):
    @abstractmethod
    def mask(self, ocel: PMOCEL) -> FilterResult: ...


class ObjectTypeFilter(BaseFilter):
    kind: Literal["object_type"] = "object_type"
    object_types: list[str]
    mode: Literal["include", "exclude"] = "include"

    def mask(self, ocel: PMOCEL) -> FilterResult:
        m = ocel.objects[OCEL_TYPE].isin(self.object_types)
        return FilterResult(objects=~m if self.mode == "exclude" else m)


class EventTypeFilter(BaseFilter):
    kind: Literal["event_type"] = "event_type"
    activities: list[str]
    mode: Literal["include", "exclude"] = "include"

    def mask(self, ocel: PMOCEL) -> FilterResult:
        m = ocel.events[OCEL_ACTIVITY].isin(self.activities)
        return FilterResult(events=~m if self.mode == "exclude" else m)


def _to_utc(value: datetime | str) -> pd.Timestamp:
    ts = pd.Timestamp(value)
    return ts.tz_localize("UTC") if ts.tzinfo is None else ts.tz_convert("UTC")


class TimeFrameFilter(BaseFilter):
    kind: Literal["time_frame"] = "time_frame"
    start: Optional[datetime] = None
    end: Optional[datetime] = None

    def mask(self, ocel: PMOCEL) -> FilterResult:
        ts = pd.to_datetime(ocel.events[OCEL_TIME], utc=True)
        m = pd.Series(True, index=ocel.events.index)
        if self.start is not None:
            m &= ts >= _to_utc(self.start)
        if self.end is not None:
            m &= ts <= _to_utc(self.end)
        return FilterResult(events=m)


class EventAttributeFilter(BaseFilter):
    kind: Literal["event_attribute"] = "event_attribute"
    name: str
    values: list[str]
    mode: Literal["include", "exclude"] = "include"

    def mask(self, ocel: PMOCEL) -> FilterResult:
        events = ocel.events
        if self.name not in events.columns:
            base = pd.Series(False, index=events.index)
        else:
            base = events[self.name].astype("string").isin(self.values)
        return FilterResult(events=~base if self.mode == "exclude" else base)


_FILTERS: dict[str, type[BaseFilter]] = {
    "object_type": ObjectTypeFilter,
    "event_type": EventTypeFilter,
    "time_frame": TimeFrameFilter,
    "event_attribute": EventAttributeFilter,
}


def parse_filters(specs: Sequence[dict[str, Any]] | None) -> list[BaseFilter]:
    """Build typed filters from request dicts, discriminated by `kind`."""
    out: list[BaseFilter] = []
    for spec in specs or []:
        cls = _FILTERS.get(str(spec.get("kind")))
        if cls is None:
            raise ValueError(f"unknown filter kind: {spec.get('kind')!r}")
        out.append(cls.model_validate(spec))
    return out


def apply_filters(ocel: PMOCEL, filters: Sequence[BaseFilter] | None) -> PMOCEL:
    """Return a new pm4py OCEL with the AND of all filter masks applied."""
    if not filters:
        return ocel

    events, objects, relations = ocel.events, ocel.objects, ocel.relations
    ev_mask = pd.Series(True, index=events.index)
    ob_mask = pd.Series(True, index=objects.index)

    for f in filters:
        res = f.mask(ocel)
        if res.events is not None:
            ev_mask &= res.events.reindex(events.index, fill_value=False)
        if res.objects is not None:
            ob_mask &= res.objects.reindex(objects.index, fill_value=False)

    kept_oids = set(objects.loc[ob_mask, OCEL_OID])
    kept_eids_by_event = set(events.loc[ev_mask, OCEL_EID])

    rel = relations[
        relations[OCEL_OID].isin(kept_oids) & relations[OCEL_EID].isin(kept_eids_by_event)
    ]
    surviving_eids = set(rel[OCEL_EID])

    f_events = events[events[OCEL_EID].isin(surviving_eids)].reset_index(drop=True)
    f_objects = objects[ob_mask].reset_index(drop=True)
    f_relations = rel.reset_index(drop=True)

    return PMOCEL(events=f_events, objects=f_objects, relations=f_relations)
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /home/smudoshi/Github/Zephyrus/arena && python -m pytest tests/test_filters.py -v`
Expected: PASS — 7 passed

- [ ] **Step 5: Commit**

```bash
git add arena/app/filters.py arena/tests/test_filters.py
git commit -m "feat(arena): clean-room composable OCEL filter engine"
```

---

### Task 3: Wire filters into discovery, performance, and conformance

**Files:**
- Modify: `arena/app/models.py` (add `filters` to `OcelSource`)
- Modify: `arena/app/discovery.py:56` (`discover` signature + apply)
- Modify: `arena/app/performance.py:34` (`analyze` signature + apply)
- Modify: `arena/app/conformance.py:26` (`check` signature + apply)
- Modify: `arena/app/routers/discover.py`, `routers/performance.py`, `routers/conformance.py` (pass parsed filters)
- Test: `arena/tests/test_filter_wiring.py`

- [ ] **Step 1: Write the failing test**

Create `arena/tests/test_filter_wiring.py`:

```python
"""Filter wiring — the analysis entrypoints honor a `filters` argument."""

from __future__ import annotations

import json
import tempfile

import pytest

pytest.importorskip("pm4py")

from app import discovery  # noqa: E402
from app.filters import EventTypeFilter  # noqa: E402


def _write_toy_ocel2_json() -> str:
    doc = {
        "objectTypes": [
            {"name": "Encounter", "attributes": []},
            {"name": "Bed", "attributes": []},
        ],
        "eventTypes": [
            {"name": "triage", "attributes": []},
            {"name": "admit", "attributes": []},
        ],
        "objects": [
            {"id": "enc1", "type": "Encounter", "attributes": [], "relationships": []},
            {"id": "bed1", "type": "Bed", "attributes": [], "relationships": []},
        ],
        "events": [
            {
                "id": "e1",
                "type": "triage",
                "time": "2026-01-01T00:00:00Z",
                "attributes": [],
                "relationships": [{"objectId": "enc1", "qualifier": "subject"}],
            },
            {
                "id": "e2",
                "type": "admit",
                "time": "2026-01-01T01:00:00Z",
                "attributes": [],
                "relationships": [
                    {"objectId": "enc1", "qualifier": "subject"},
                    {"objectId": "bed1", "qualifier": "location"},
                ],
            },
        ],
    }
    tmp = tempfile.NamedTemporaryFile("w", suffix=".json", delete=False, encoding="utf-8")
    json.dump(doc, tmp)
    tmp.flush()
    tmp.close()
    return tmp.name


def test_discover_applies_filters():
    path = _write_toy_ocel2_json()
    unfiltered = discovery.discover(path)
    filtered = discovery.discover(path, filters=[EventTypeFilter(activities=["triage"])])
    assert unfiltered["stats"]["nodes"] >= filtered["stats"]["nodes"]
    assert "admit" not in {n["activity"] for n in filtered["nodes"]}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/smudoshi/Github/Zephyrus/arena && python -m pytest tests/test_filter_wiring.py -v`
Expected: FAIL — `discover() got an unexpected keyword argument 'filters'`

- [ ] **Step 3: Add `filters` to the request base and thread it through**

In `arena/app/models.py`, add the field to `OcelSource` (all three request models inherit it). Change the class to:

```python
class OcelSource(BaseModel):
    """Where to read the OCEL 2.0 log from, plus an optional filter pipeline.

    Exactly one source is used: `ocel_path` (a shared-volume export) is preferred,
    `ocel` inlines the doc for a fully stateless call, and omitting both falls back
    to the configured default export path. `filters` is an ordered list of filter
    specs (each an object with a `kind` discriminator) applied before analysis."""

    ocel_path: str | None = Field(default=None, description="path to an OCEL 2.0 JSON file readable by the sidecar")
    ocel: dict[str, Any] | None = Field(default=None, description="an inline OCEL 2.0 JSON document")
    filters: list[dict[str, Any]] | None = Field(default=None, description="ordered OCEL filter pipeline; each item has a 'kind' discriminator")
```

In `arena/app/discovery.py`, change the `discover` signature and body head:

```python
def discover(
    path: str,
    object_types: list[str] | None = None,
    activity_min_freq: int | None = None,
    filters: list["BaseFilter"] | None = None,
) -> dict[str, Any]:
    """Discover the object-centric DFG for the (optionally filtered) object types."""
    from app.filters import apply_filters  # local import: keep pm4py-optional import lazy

    settings = get_settings()
    ocel = read_ocel(path)
    ocel = apply_filters(ocel, filters)
```

(Add `from typing import TYPE_CHECKING` guard already present; the string annotation `"BaseFilter"` avoids a hard import at module load.)

In `arena/app/performance.py`, change `analyze`:

```python
def analyze(
    path: str,
    object_types: list[str] | None = None,
    top: int = 25,
    filters: list["BaseFilter"] | None = None,
) -> dict[str, Any]:
    from app.filters import apply_filters

    ocel = read_ocel(path)
    ocel = apply_filters(ocel, filters)
    ceiling = get_settings().arena_max_handoff_hours * 3600
    return {
        "handoffs": _handoff_durations(ocel, object_types, top, ceiling),
        "synchronization": _synchronization(ocel, object_types, top, ceiling),
    }
```

In `arena/app/conformance.py`, change `check`:

```python
def check(
    path: str,
    pathway_key: str | None = None,
    sample_limit: int = 8,
    filters: list["BaseFilter"] | None = None,
) -> list[dict[str, Any]]:
    """Run conformance for one pathway (or all) over the OCEL log at `path`."""
    from app.filters import apply_filters

    ocel = read_ocel(path)
    ocel = apply_filters(ocel, filters)
    keys = [pathway_key] if pathway_key else list(PATHWAYS.keys())
    return [
        _check_one(ocel.events, ocel.relations, PATHWAYS[key], key, sample_limit)
        for key in keys
        if key in PATHWAYS
    ]
```

- [ ] **Step 4: Parse filters in each router**

In `arena/app/routers/discover.py`, inside `discover_map`, after resolving `object_types`, parse and pass filters:

```python
from app.filters import parse_filters  # add to imports

# ... inside discover_map, replace the discovery.discover(...) call with:
    try:
        filters = parse_filters(req.filters)
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
    try:
        with resolve_ocel_path(req.ocel_path, req.ocel) as path:
            result = discovery.discover(
                path,
                object_types=object_types,
                activity_min_freq=req.activity_min_freq,
                filters=filters,
            )
        return DiscoverResponse(**result)
    except OcelUnavailable as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
```

In `arena/app/routers/performance.py`, inside `analyze_performance`:

```python
from app.filters import parse_filters  # add to imports

    try:
        filters = parse_filters(req.filters)
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
    try:
        with resolve_ocel_path(req.ocel_path, req.ocel) as path:
            result = performance.analyze(path, object_types=object_types, top=req.top, filters=filters)
        return PerformanceResponse(**result)
    except OcelUnavailable as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
```

In `arena/app/routers/conformance.py`, inside `check_conformance`:

```python
from app.filters import parse_filters  # add to imports

    try:
        filters = parse_filters(req.filters)
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
    try:
        with resolve_ocel_path(req.ocel_path, req.ocel) as path:
            results = conformance.check(path, pathway_key=req.pathway, filters=filters)
        return [PathwayConformance(**result) for result in results]
    except OcelUnavailable as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd /home/smudoshi/Github/Zephyrus/arena && python -m pytest tests/test_filter_wiring.py tests/test_filters.py -v`
Expected: PASS — all green

- [ ] **Step 6: Run the full sidecar suite (no regressions)**

Run: `cd /home/smudoshi/Github/Zephyrus/arena && python -m pytest -q`
Expected: PASS — existing discovery/performance/conformance/copilot tests still green

- [ ] **Step 7: Commit**

```bash
git add arena/app/models.py arena/app/discovery.py arena/app/performance.py arena/app/conformance.py arena/app/routers/
git add arena/tests/test_filter_wiring.py
git commit -m "feat(arena): apply OCEL filter pipeline to discovery/performance/conformance"
```

---

### Task 4: Laravel passthrough + filter-aware map cache key

**Files:**
- Modify: `app/Domain/Arena/ArenaSidecarClient.php` (add `$filters` to `discover`/`performance`/`conformance`)
- Modify: `app/Domain/Arena/ArenaService.php` (thread filters, fold into cache key)
- Modify: `app/Http/Controllers/Api/ArenaController.php` (validate + pass `filters`)
- Test: `tests/Feature/Arena/ArenaFilterPassthroughTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Arena/ArenaFilterPassthroughTest.php`:

```php
<?php

namespace Tests\Feature\Arena;

use App\Domain\Arena\ArenaSidecarClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArenaFilterPassthroughTest extends TestCase
{
    public function test_discover_forwards_filters_to_the_sidecar(): void
    {
        config()->set('services.arena.url', 'http://arena:8100');

        Http::fake([
            'arena:8100/discover' => Http::response([
                'object_types' => [], 'nodes' => [], 'edges' => [], 'stats' => [],
            ], 200),
        ]);

        $client = new ArenaSidecarClient();
        $filters = [['kind' => 'event_type', 'activities' => ['triage'], 'mode' => 'include']];
        $client->discover(['events' => [], 'objects' => []], null, null, $filters);

        Http::assertSent(function ($request) use ($filters) {
            return $request->url() === 'http://arena:8100/discover'
                && $request['filters'] === $filters;
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ArenaFilterPassthroughTest`
Expected: FAIL — `discover()` does not accept a 4th argument / `filters` not sent

- [ ] **Step 3: Add `$filters` to the client methods**

In `app/Domain/Arena/ArenaSidecarClient.php`, change `discover`:

```php
    public function discover(array $ocel, ?array $objectTypes = null, ?int $minFreq = null, ?array $filters = null): ?array
    {
        $body = ['ocel' => $ocel];
        if ($objectTypes !== null) {
            $body['object_types'] = array_values($objectTypes);
        }
        if ($minFreq !== null) {
            $body['activity_min_freq'] = $minFreq;
        }
        if (! empty($filters)) {
            $body['filters'] = array_values($filters);
        }

        return $this->post('/discover', $body);
    }
```

Change `performance`:

```php
    public function performance(array $ocel, ?array $objectTypes = null, int $top = 25, ?array $filters = null): ?array
    {
        $body = ['ocel' => $ocel, 'top' => $top];
        if ($objectTypes !== null) {
            $body['object_types'] = array_values($objectTypes);
        }
        if (! empty($filters)) {
            $body['filters'] = array_values($filters);
        }

        return $this->post('/performance', $body);
    }
```

Change `conformance`:

```php
    public function conformance(array $ocel, ?string $pathway = null, ?array $filters = null): ?array
    {
        $body = ['ocel' => $ocel];
        if ($pathway !== null) {
            $body['pathway'] = $pathway;
        }
        if (! empty($filters)) {
            $body['filters'] = array_values($filters);
        }
        $result = $this->post('/conformance', $body);

        return is_array($result) ? $result : null;
    }
```

- [ ] **Step 4: Thread filters through `ArenaService` and into the cache key**

In `app/Domain/Arena/ArenaService.php`, change `map` to accept `$filters` and include them in `$cacheKey`:

```php
    public function map(?array $objectTypes = null, ?int $minFreq = null, string $scope = 'house', bool $force = false, ?array $filters = null): array
    {
        $signature = $this->sourceSignature();
        $normTypes = $this->normaliseTypes($objectTypes);
        $filterSig = $this->filterSignature($filters);
        $cacheKey = sha1($scope.'|'.json_encode($normTypes).'|'.(int) $minFreq.'|'.$filterSig.'|'.$signature);
        $ttl = (int) config('services.arena.cache_ttl', 900);

        $cached = DB::table('arena.maps')->where('cache_key', $cacheKey)->first();
        if (! $force && $cached !== null && Carbon::parse($cached->mined_at)->gt(now()->subSeconds($ttl))) {
            return $this->wrapCached($cached, stale: false);
        }

        $doc = $this->exporter->export();
        $result = $this->client->discover($doc, $normTypes, $minFreq, $filters);
```

(The rest of `map` is unchanged.) Then add the helper below `sourceSignature`:

```php
    /** A stable fingerprint of the filter pipeline for cache keying (order-sensitive). */
    private function filterSignature(?array $filters): string
    {
        if (empty($filters)) {
            return 'nofilter';
        }

        return sha1(json_encode(array_values($filters)));
    }
```

Also update `performance` and `conformance` in `ArenaService` to accept + forward `$filters`:

```php
    public function conformance(?string $pathway = null, ?array $filters = null): array
    {
        $doc = $this->exporter->export();
        $results = $this->client->conformance($doc, $pathway, $filters);

        if ($results === null) {
            return ['available' => false, 'reason' => 'sidecar_unavailable'];
        }

        return ['available' => true, 'pathways' => $results];
    }

    public function performance(?array $objectTypes = null, int $top = 25, ?array $filters = null): array
    {
        $doc = $this->exporter->export();
        $result = $this->client->performance($doc, $objectTypes, $top, $filters);

        if ($result === null) {
            return ['available' => false, 'reason' => 'sidecar_unavailable'];
        }

        return ['available' => true] + $result;
    }
```

- [ ] **Step 5: Validate + accept `filters` in the controller**

In `app/Http/Controllers/Api/ArenaController.php`, add a private validator and pass filters in `map`, `performance`, `conformance`. Add this method to the controller:

```php
    /**
     * Validate the optional filter pipeline from the request. Returns a plain
     * array the sidecar accepts, or null when absent.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function filtersFrom(Request $request): ?array
    {
        $validated = $request->validate([
            'filters' => ['sometimes', 'array', 'max:12'],
            'filters.*.kind' => ['required_with:filters', 'string', 'in:object_type,event_type,time_frame,event_attribute'],
        ]);

        return $validated['filters'] ?? null;
    }
```

Then, in the `map` action, pass `filters: $this->filtersFrom($request)` into `$this->arena->map(...)`; likewise pass `$this->filtersFrom($request)` as the trailing argument to `performance(...)` and `conformance(...)`.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=ArenaFilterPassthroughTest`
Expected: PASS

- [ ] **Step 7: Run Pint + the Arena suite**

Run: `docker compose exec -T php sh -c "cd /var/www/html && vendor/bin/pint app/Domain/Arena app/Http/Controllers/Api/ArenaController.php"`
Run: `php artisan test --filter=Arena`
Expected: Pint clean; Arena tests green

- [ ] **Step 8: Commit**

```bash
git add app/Domain/Arena/ app/Http/Controllers/Api/ArenaController.php tests/Feature/Arena/ArenaFilterPassthroughTest.php
git commit -m "feat(arena): forward + cache-key the OCEL filter pipeline"
```

---

### Task 5: Minimal frontend filter control

**Files:**
- Modify: `resources/js/features/arena/schema.ts` (add `arenaFilterSchema`)
- Create: `resources/js/Components/arena/FilterBar.tsx`
- Modify: `resources/js/Pages/Analytics/Arena.tsx` (render `FilterBar`, pass filters to hooks)
- Modify: `resources/js/features/arena/hooks.ts` (accept `filters` in the query key + body)

- [ ] **Step 1: Add the Zod schema**

In `resources/js/features/arena/schema.ts`, add:

```ts
import { z } from 'zod';

export const arenaFilterSchema = z.object({
  kind: z.enum(['object_type', 'event_type', 'time_frame', 'event_attribute']),
  object_types: z.array(z.string()).optional(),
  activities: z.array(z.string()).optional(),
  name: z.string().optional(),
  values: z.array(z.string()).optional(),
  start: z.string().optional(),
  end: z.string().optional(),
  mode: z.enum(['include', 'exclude']).optional(),
});

export type ArenaFilter = z.infer<typeof arenaFilterSchema>;
```

- [ ] **Step 2: Create the FilterBar component**

Create `resources/js/Components/arena/FilterBar.tsx`. This renders the discovered object types as include/exclude chips and emits an `ArenaFilter[]`:

```tsx
import { useMemo } from 'react';
import type { ArenaFilter } from '@/features/arena/schema';

type Props = {
  objectTypes: string[];
  selected: string[];
  onChange: (filters: ArenaFilter[]) => void;
};

/**
 * Minimal Phase XO.1 filter surface: pick which object types the Arena views
 * should include. Emits a single ObjectTypeFilter (include mode). Time/attribute
 * filters are additive follow-ups that push more `ArenaFilter` items here.
 */
export function FilterBar({ objectTypes, selected, onChange }: Props) {
  const filters = useMemo<ArenaFilter[]>(
    () => (selected.length ? [{ kind: 'object_type', object_types: selected, mode: 'include' }] : []),
    [selected],
  );

  const toggle = (ot: string) => {
    const next = selected.includes(ot) ? selected.filter((t) => t !== ot) : [...selected, ot];
    onChange(next.length ? [{ kind: 'object_type', object_types: next, mode: 'include' }] : []);
  };

  return (
    <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Object type filter">
      <span className="text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        Object types
      </span>
      {objectTypes.map((ot) => {
        const on = selected.includes(ot);
        return (
          <button
            key={ot}
            type="button"
            aria-pressed={on}
            onClick={() => toggle(ot)}
            className={
              on
                ? 'rounded-full border border-healthcare-primary bg-healthcare-primary px-3 py-1 text-xs font-medium text-white'
                : 'rounded-full border border-healthcare-border px-3 py-1 text-xs font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
            }
          >
            {ot}
          </button>
        );
      })}
      {filters.length > 0 && (
        <button
          type="button"
          onClick={() => onChange([])}
          className="text-xs font-medium text-healthcare-text-secondary underline dark:text-healthcare-text-secondary-dark"
        >
          Clear
        </button>
      )}
    </div>
  );
}
```

- [ ] **Step 3: Thread filters through the hooks**

In `resources/js/features/arena/hooks.ts`, add `filters` to the map/performance/conformance hooks' query keys and POST bodies. For each hook (pattern shown for the map hook), include `filters` in both the `queryKey` and the request payload:

```ts
export function useArenaMap(filters: ArenaFilter[] = []) {
  return useQuery({
    queryKey: ['arena', 'map', filters],
    queryFn: async () => {
      const res = await axios.post(route('api.arena.map'), { filters });
      return arenaMapResponseSchema.parse(res.data);
    },
  });
}
```

(Apply the same `filters` addition to `useArenaPerformance` and `useArenaConformance`. `useArenaSummary` stays unfiltered — it is the cheap "is the log worth mining" probe.)

- [ ] **Step 4: Render the FilterBar in the Arena page**

In `resources/js/Pages/Analytics/Arena.tsx`, add local filter state driven by the summary's object types, render `FilterBar`, and pass `filters` into the hooks:

```tsx
import { FilterBar } from '@/Components/arena/FilterBar';
import type { ArenaFilter } from '@/features/arena/schema';
// ...
const [filters, setFilters] = useState<ArenaFilter[]>([]);
const selectedObjectTypes = filters.find((f) => f.kind === 'object_type')?.object_types ?? [];
const summary = useArenaSummary();
const objectTypeNames = Object.keys(summary.data?.object_types ?? {});
const map = useArenaMap(filters);
// render near the top of the page body:
<FilterBar objectTypes={objectTypeNames} selected={selectedObjectTypes} onChange={setFilters} />
```

- [ ] **Step 5: Verify the frontend builds (both checkers)**

Run: `docker compose exec node sh -c "cd /app && npx tsc --noEmit"`
Run: `docker compose exec node sh -c "cd /app && npx vite build"`
Run: `./scripts/check-ui-canon.sh`
Expected: tsc clean, vite build succeeds, canon check passes

- [ ] **Step 6: Commit**

```bash
git add resources/js/features/arena/schema.ts resources/js/features/arena/hooks.ts resources/js/Components/arena/FilterBar.tsx resources/js/Pages/Analytics/Arena.tsx
git commit -m "feat(arena): object-type filter bar wired to filterable Arena views"
```

---

### Task 6: Final verification

- [ ] **Step 1: Clean-room guard**

Run: `./scripts/check-clean-room.sh`
Expected: `✅ clean-room: no ocelescope dependency or import found`

- [ ] **Step 2: Full sidecar + backend suites**

Run: `cd /home/smudoshi/Github/Zephyrus/arena && python -m pytest -q`
Run: `php artisan test --filter=Arena`
Expected: both green

- [ ] **Step 3: Manual smoke (optional, requires sidecar running)**

Run:
```bash
curl -s -X POST localhost:8101/discover \
  -H 'content-type: application/json' \
  -d '{"filters":[{"kind":"object_type","object_types":["Encounter","Bed"],"mode":"include"}]}' | head -c 400
```
Expected: a `nodes`/`edges` JSON restricted to Encounter+Bed activity.

---

## Self-review checklist (run before handoff)

- **Spec coverage:** filter engine (Task 2) ✓, wiring into all three views (Task 3) ✓, Laravel passthrough + cache key (Task 4) ✓, frontend (Task 5) ✓, clean-room guard (Task 1) ✓.
- **Type consistency:** `apply_filters`/`parse_filters`/`BaseFilter`/`FilterResult` names identical across Task 2/3; `filters` param appended (never reordered) in every PHP signature; Zod `arenaFilterSchema` `kind` enum matches the controller `in:` rule and the sidecar `_FILTERS` keys.
- **No placeholders:** every code step shows complete code; the "additive follow-up" note in FilterBar is a design note, not a task dependency.
