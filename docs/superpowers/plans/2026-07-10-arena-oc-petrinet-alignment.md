# Arena OC Petri-Net Discovery + Replay Fitness (Phase XO.2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add object-centric Petri-net discovery to the Arena, and harden the governed copilot trust-gate with per-object-type token-based **replay fitness** — a model-based conformance signal stronger than the current DFG edge-set fitness.

**Architecture:** Two clean-room sidecar modules. `arena/app/petrinet.py` mines an OC Petri net with `pm4py.discover_oc_petri_net` and serializes each per-object-type subnet to a flat, canon-render JSON contract (our own, not ocelescope's). `arena/app/replay.py` flattens the log per object type, mines an inductive Petri net, and computes token-based replay fitness. The existing `/copilot/model-fitness` response gains **additive** structural-fitness fields (safe defaults, non-breaking) so the orchestrator can flag a proposed model that claims structure in a poorly-fitting object type. Everything composes with the XO.1 filter pipeline.

**Tech Stack:** Python 3.12, FastAPI, `pm4py>=2.7,<3` (`discover_oc_petri_net`, `discover_petri_net_inductive`, `fitness_token_based_replay`), Pydantic v2; Laravel 11 / PHP 8.5; React 19 + Zod.

**Provenance:** Clean-room per [`2026-07-10-ocelescope-ocpm-integration-roadmap.md`](./2026-07-10-ocelescope-ocpm-integration-roadmap.md) §3. Depends on Phase XO.1 only for the shared `filters` plumbing (`arena/app/filters.py`); if XO.1 is not yet merged, drop the `filters=` arguments — they are additive.

---

### Task 1: OC Petri-net discovery module (`arena/app/petrinet.py`)

**Files:**
- Create: `arena/app/petrinet.py`
- Test: `arena/tests/test_petrinet.py`

- [ ] **Step 1: Write the failing test**

Create `arena/tests/test_petrinet.py`:

```python
"""OC Petri-net discovery — structural contract over a small OCEL2 JSON log."""

from __future__ import annotations

import json
import tempfile

import pytest

pytest.importorskip("pm4py")

from app import petrinet  # noqa: E402


def _write_toy_ocel2_json() -> str:
    doc = {
        "objectTypes": [{"name": "Encounter", "attributes": []}],
        "eventTypes": [
            {"name": "triage", "attributes": []},
            {"name": "admit", "attributes": []},
            {"name": "discharge", "attributes": []},
        ],
        "objects": [
            {"id": "enc1", "type": "Encounter", "attributes": [], "relationships": []},
            {"id": "enc2", "type": "Encounter", "attributes": [], "relationships": []},
        ],
        "events": [
            {"id": "e1", "type": "triage", "time": "2026-01-01T00:00:00Z", "attributes": [],
             "relationships": [{"objectId": "enc1", "qualifier": "subject"}]},
            {"id": "e2", "type": "admit", "time": "2026-01-01T01:00:00Z", "attributes": [],
             "relationships": [{"objectId": "enc1", "qualifier": "subject"}]},
            {"id": "e3", "type": "discharge", "time": "2026-01-01T02:00:00Z", "attributes": [],
             "relationships": [{"objectId": "enc1", "qualifier": "subject"}]},
            {"id": "e4", "type": "triage", "time": "2026-01-02T00:00:00Z", "attributes": [],
             "relationships": [{"objectId": "enc2", "qualifier": "subject"}]},
            {"id": "e5", "type": "admit", "time": "2026-01-02T01:00:00Z", "attributes": [],
             "relationships": [{"objectId": "enc2", "qualifier": "subject"}]},
        ],
    }
    tmp = tempfile.NamedTemporaryFile("w", suffix=".json", delete=False, encoding="utf-8")
    json.dump(doc, tmp)
    tmp.flush()
    tmp.close()
    return tmp.name


def test_discover_petrinet_returns_per_object_type_subnets():
    result = petrinet.discover(_write_toy_ocel2_json())
    assert "Encounter" in result["object_types"]
    nets = {n["object_type"]: n for n in result["nets"]}
    enc = nets["Encounter"]
    labels = {t["label"] for t in enc["transitions"] if t["label"]}
    assert {"triage", "admit"}.issubset(labels)
    assert len(enc["places"]) >= 1
    assert result["stats"]["object_types"] >= 1
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/smudoshi/Github/Zephyrus/arena && python -m pytest tests/test_petrinet.py -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'app.petrinet'`

- [ ] **Step 3: Write minimal implementation**

Create `arena/app/petrinet.py`:

```python
"""Object-centric Petri-net discovery (Part X, Phase XO.2).

Clean-room (see arena/CLEAN-ROOM.md). pm4py mines the OC Petri net; this module
serializes each per-object-type subnet into a flat JSON contract the React Study
UI renders — places (with initial/final markers), transitions (silent when the
label is null), and arcs (variable arcs mark synchronization across object
counts). Read-only, PHI-free: activity labels + de-identified object types only.
"""

from __future__ import annotations

from typing import Any

from app.ocel_loader import read_ocel

try:
    import pm4py  # type: ignore
except Exception:  # pragma: no cover
    pm4py = None  # type: ignore


def _discover_ocpn(ocel):
    """Call pm4py's OC Petri-net miner, tolerating signature drift across the 2.7 line."""
    try:
        return pm4py.discover_oc_petri_net(ocel, noise_threshold=0.0)  # type: ignore[union-attr]
    except TypeError:
        return pm4py.discover_oc_petri_net(ocel)  # type: ignore[union-attr]


def _serialize_net(net, im, fm, object_type: str, double_arcs: dict[str, Any]) -> dict[str, Any]:
    initial = {p.name for p in im} if im else set()
    final = {p.name for p in fm} if fm else set()

    places = [
        {"id": p.name, "initial": p.name in initial, "final": p.name in final}
        for p in net.places
    ]
    transitions = [{"id": t.name, "label": t.label} for t in net.transitions]

    def _is_variable(node) -> bool:
        label = getattr(node, "label", None)
        if label is None:
            return False
        return bool(double_arcs.get(label, {}).get(object_type, False))

    arcs = [
        {
            "source": a.source.name,
            "target": a.target.name,
            "variable": _is_variable(a.source) or _is_variable(a.target),
            "weight": int(getattr(a, "weight", 1) or 1),
        }
        for a in net.arcs
    ]

    return {
        "object_type": object_type,
        "places": places,
        "transitions": transitions,
        "arcs": arcs,
    }


def discover(path: str, filters: list[Any] | None = None) -> dict[str, Any]:
    """Discover and serialize the object-centric Petri net for the OCEL log."""
    ocel = read_ocel(path)
    if filters:
        from app.filters import apply_filters  # optional XO.1 dependency

        ocel = apply_filters(ocel, filters)

    ocpn = _discover_ocpn(ocel)
    petri_nets: dict[str, Any] = ocpn.get("petri_nets", {}) if isinstance(ocpn, dict) else {}
    double_arcs: dict[str, Any] = ocpn.get("double_arcs_on_activity", {}) if isinstance(ocpn, dict) else {}

    nets = []
    for ot, triple in petri_nets.items():
        try:
            net, im, fm = triple
        except (TypeError, ValueError):
            continue
        nets.append(_serialize_net(net, im, fm, str(ot), double_arcs))

    return {
        "object_types": sorted(petri_nets.keys()),
        "nets": nets,
        "stats": {
            "object_types": len(nets),
            "places": sum(len(n["places"]) for n in nets),
            "transitions": sum(len(n["transitions"]) for n in nets),
            "arcs": sum(len(n["arcs"]) for n in nets),
        },
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /home/smudoshi/Github/Zephyrus/arena && python -m pytest tests/test_petrinet.py -v`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add arena/app/petrinet.py arena/tests/test_petrinet.py
git commit -m "feat(arena): object-centric Petri-net discovery (flat canon contract)"
```

---

### Task 2: Petri-net models + `/discover/petrinet` route

**Files:**
- Modify: `arena/app/models.py` (add `PetriNetRequest`, `PetriNetResponse` + node models)
- Modify: `arena/app/routers/discover.py` (add the route)
- Test: `arena/tests/test_petrinet_route.py`

- [ ] **Step 1: Write the failing test**

Create `arena/tests/test_petrinet_route.py`:

```python
"""The /discover/petrinet route returns the serialized OC Petri net."""

from __future__ import annotations

import pytest

pytest.importorskip("pm4py")

from fastapi.testclient import TestClient  # noqa: E402

from app.main import app  # noqa: E402

client = TestClient(app)


def test_petrinet_route_with_inline_ocel():
    doc = {
        "objectTypes": [{"name": "Encounter", "attributes": []}],
        "eventTypes": [{"name": "triage", "attributes": []}, {"name": "admit", "attributes": []}],
        "objects": [{"id": "enc1", "type": "Encounter", "attributes": [], "relationships": []}],
        "events": [
            {"id": "e1", "type": "triage", "time": "2026-01-01T00:00:00Z", "attributes": [],
             "relationships": [{"objectId": "enc1", "qualifier": "subject"}]},
            {"id": "e2", "type": "admit", "time": "2026-01-01T01:00:00Z", "attributes": [],
             "relationships": [{"objectId": "enc1", "qualifier": "subject"}]},
        ],
    }
    res = client.post("/discover/petrinet", json={"ocel": doc})
    assert res.status_code == 200
    body = res.json()
    assert "nets" in body and "stats" in body
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/smudoshi/Github/Zephyrus/arena && python -m pytest tests/test_petrinet_route.py -v`
Expected: FAIL — 404 Not Found (route not registered)

- [ ] **Step 3: Add the models**

In `arena/app/models.py`, append:

```python
class PetriNetRequest(OcelSource):
    """OC Petri-net discovery request. Inherits the OCEL source + filter pipeline."""


class PetriNetPlace(BaseModel):
    id: str
    initial: bool
    final: bool


class PetriNetTransition(BaseModel):
    id: str
    label: str | None  # null => silent (tau) transition


class PetriNetArc(BaseModel):
    source: str
    target: str
    variable: bool  # variable arc => synchronization across object counts
    weight: int


class PetriNetSubnet(BaseModel):
    object_type: str
    places: list[PetriNetPlace]
    transitions: list[PetriNetTransition]
    arcs: list[PetriNetArc]


class PetriNetResponse(BaseModel):
    object_types: list[str]
    nets: list[PetriNetSubnet]
    stats: dict[str, int]
```

- [ ] **Step 4: Add the route**

In `arena/app/routers/discover.py`, add the import and route:

```python
from app import discovery, petrinet  # extend the existing import
from app.models import DiscoverRequest, DiscoverResponse, OcelSource, PetriNetRequest, PetriNetResponse, SummaryResponse
from app.filters import parse_filters  # present after XO.1; drop if XO.1 not merged

@router.post("/discover/petrinet", response_model=PetriNetResponse)
async def discover_petrinet(req: PetriNetRequest) -> PetriNetResponse:
    _require_engine()
    try:
        filters = parse_filters(req.filters)
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
    try:
        with resolve_ocel_path(req.ocel_path, req.ocel) as path:
            result = petrinet.discover(path, filters=filters)
        return PetriNetResponse(**result)
    except OcelUnavailable as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd /home/smudoshi/Github/Zephyrus/arena && python -m pytest tests/test_petrinet_route.py -v`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add arena/app/models.py arena/app/routers/discover.py arena/tests/test_petrinet_route.py
git commit -m "feat(arena): /discover/petrinet route + response contract"
```

---

### Task 3: Replay-fitness module (`arena/app/replay.py`)

**Files:**
- Create: `arena/app/replay.py`
- Test: `arena/tests/test_replay.py`

- [ ] **Step 1: Write the failing test**

Create `arena/tests/test_replay.py`:

```python
"""Token-based replay fitness per object type — a model-based conformance signal."""

from __future__ import annotations

import json
import tempfile

import pytest

pytest.importorskip("pm4py")

from app import replay  # noqa: E402


def _write_structured_ocel2_json() -> str:
    # Two encounters both follow triage -> admit -> discharge => a highly-fitting process.
    events = []
    for enc, day in (("enc1", "01"), ("enc2", "02")):
        for i, act in enumerate(["triage", "admit", "discharge"]):
            events.append({
                "id": f"{enc}-{act}",
                "type": act,
                "time": f"2026-01-{day}T0{i}:00:00Z",
                "attributes": [],
                "relationships": [{"objectId": enc, "qualifier": "subject"}],
            })
    doc = {
        "objectTypes": [{"name": "Encounter", "attributes": []}],
        "eventTypes": [{"name": a, "attributes": []} for a in ("triage", "admit", "discharge")],
        "objects": [
            {"id": "enc1", "type": "Encounter", "attributes": [], "relationships": []},
            {"id": "enc2", "type": "Encounter", "attributes": [], "relationships": []},
        ],
        "events": events,
    }
    tmp = tempfile.NamedTemporaryFile("w", suffix=".json", delete=False, encoding="utf-8")
    json.dump(doc, tmp)
    tmp.flush()
    tmp.close()
    return tmp.name


def test_replay_fitness_reports_per_object_type():
    result = replay.fitness(_write_structured_ocel2_json())
    by_type = {r["object_type"]: r for r in result["by_object_type"]}
    assert "Encounter" in by_type
    assert 0.0 <= by_type["Encounter"]["fitness"] <= 1.0
    # A perfectly-structured log fits its own mined net near-perfectly.
    assert by_type["Encounter"]["fitness"] >= 0.9
    assert 0.0 <= result["min_fitness"] <= 1.0
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/smudoshi/Github/Zephyrus/arena && python -m pytest tests/test_replay.py -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'app.replay'`

- [ ] **Step 3: Write minimal implementation**

Create `arena/app/replay.py`:

```python
"""Per-object-type token-based replay fitness (Part X, Phase XO.2).

For each object type we flatten the OCEL to that type's traces, mine an inductive
Petri net, and replay the log against it. Token-based replay fitness is the
pragmatic, well-supported cousin of alignment fitness in pm4py; it measures how
well the real behavior fits a structured control-flow model — a stronger signal
than the copilot's DFG edge-set recall, and the honest name for what it computes.

Clean-room (see arena/CLEAN-ROOM.md). Read-only, PHI-free.
"""

from __future__ import annotations

from typing import Any

from app.config import get_settings
from app.ocel_loader import read_ocel

try:
    import pm4py  # type: ignore
except Exception:  # pragma: no cover
    pm4py = None  # type: ignore


def fitness(
    path: str,
    object_types: list[str] | None = None,
    filters: list[Any] | None = None,
) -> dict[str, Any]:
    """Token-based replay fitness per object type + a min/mean aggregate."""
    settings = get_settings()
    ocel = read_ocel(path)
    if filters:
        from app.filters import apply_filters

        ocel = apply_filters(ocel, filters)

    all_ots = list(pm4py.ocel_get_object_types(ocel))  # type: ignore[union-attr]
    ots = [ot for ot in all_ots if object_types is None or ot in object_types]
    ots = ots[: settings.arena_max_object_types]

    rows: list[dict[str, Any]] = []
    for ot in ots:
        try:
            flat = pm4py.ocel_flattening(ocel, ot)  # type: ignore[union-attr]
        except Exception:
            continue
        if flat is None or len(flat) == 0:
            continue
        try:
            net, im, fm = pm4py.discover_petri_net_inductive(flat, noise_threshold=0.0)  # type: ignore[union-attr]
            result = pm4py.fitness_token_based_replay(flat, net, im, fm)  # type: ignore[union-attr]
        except Exception:
            continue
        rows.append({
            "object_type": str(ot),
            "fitness": round(float(result.get("log_fitness", 0.0)), 4),
            "fitting_traces_pct": round(float(result.get("percentage_of_fitting_traces", 0.0)), 2),
        })

    fitnesses = [r["fitness"] for r in rows]
    return {
        "by_object_type": rows,
        "min_fitness": round(min(fitnesses), 4) if fitnesses else None,
        "mean_fitness": round(sum(fitnesses) / len(fitnesses), 4) if fitnesses else None,
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /home/smudoshi/Github/Zephyrus/arena && python -m pytest tests/test_replay.py -v`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add arena/app/replay.py arena/tests/test_replay.py
git commit -m "feat(arena): per-object-type token-based replay fitness"
```

---

### Task 4: Harden the copilot gate with structural-fitness cross-check (additive)

**Files:**
- Modify: `arena/app/copilot.py` (add a structural cross-check helper)
- Modify: `arena/app/models.py` (`ModelFitnessResponse` gains optional fields)
- Modify: `arena/app/routers/copilot.py` (populate the new fields)
- Test: `arena/tests/test_copilot_structural.py`

- [ ] **Step 1: Write the failing test**

Create `arena/tests/test_copilot_structural.py`:

```python
"""The copilot fitness verdict carries an additive structural cross-check: for
each object type the model proposes, the log's replay fitness, and a warning list
for types whose real process fits poorly (the AI claims structure that isn't there)."""

from __future__ import annotations

import json
import tempfile

import pytest

pytest.importorskip("pm4py")

from app import copilot  # noqa: E402


def _toy_path() -> str:
    events = []
    for enc, day in (("enc1", "01"), ("enc2", "02")):
        for i, act in enumerate(["triage", "admit", "discharge"]):
            events.append({
                "id": f"{enc}-{act}", "type": act, "time": f"2026-01-{day}T0{i}:00:00Z",
                "attributes": [], "relationships": [{"objectId": enc, "qualifier": "subject"}],
            })
    doc = {
        "objectTypes": [{"name": "Encounter", "attributes": []}],
        "eventTypes": [{"name": a, "attributes": []} for a in ("triage", "admit", "discharge")],
        "objects": [{"id": e, "type": "Encounter", "attributes": [], "relationships": []} for e in ("enc1", "enc2")],
        "events": events,
    }
    tmp = tempfile.NamedTemporaryFile("w", suffix=".json", delete=False, encoding="utf-8")
    json.dump(doc, tmp); tmp.flush(); tmp.close()
    return tmp.name


def test_structural_fitness_for_proposed_types():
    proposed = [{"object_type": "Encounter", "source": "triage", "target": "admit"}]
    out = copilot.structural_cross_check(_toy_path(), proposed, structural_floor=0.5)
    assert "Encounter" in out["structural_fitness_by_type"]
    assert out["structural_fitness_by_type"]["Encounter"] >= 0.5
    assert out["structural_warnings"] == []  # well-structured => no warning
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/smudoshi/Github/Zephyrus/arena && python -m pytest tests/test_copilot_structural.py -v`
Expected: FAIL — `module 'app.copilot' has no attribute 'structural_cross_check'`

- [ ] **Step 3: Add the cross-check helper**

In `arena/app/copilot.py`, append:

```python
def structural_cross_check(
    path: str,
    proposed_edges: list[dict[str, str]],
    structural_floor: float = 0.5,
) -> dict[str, Any]:
    """Replay-fitness cross-check for the object types a copilot model proposes.

    For each proposed object type, the log's own token-based replay fitness. A type
    whose real process fits below `structural_floor` earns a warning: the model
    asserts structure the data does not exhibit. Additive — this never changes the
    existing DFG-fitness `published` decision; it arms the narrative with a caveat.
    """
    from app.replay import fitness as replay_fitness

    proposed_types = sorted({str(e.get("object_type", "")) for e in proposed_edges if e.get("object_type")})
    if not proposed_types:
        return {"structural_fitness_by_type": {}, "structural_warnings": []}

    scored = replay_fitness(path, object_types=proposed_types)
    by_type = {r["object_type"]: r["fitness"] for r in scored["by_object_type"]}

    warnings = [
        {"object_type": ot, "fitness": by_type[ot], "floor": structural_floor}
        for ot in proposed_types
        if ot in by_type and by_type[ot] < structural_floor
    ]
    return {"structural_fitness_by_type": by_type, "structural_warnings": warnings}
```

- [ ] **Step 4: Extend the response model (additive, safe defaults)**

In `arena/app/models.py`, add these fields to `ModelFitnessResponse` (append after `reason`):

```python
    # --- XO.2 additive structural cross-check (default-empty => non-breaking) ---
    structural_fitness_by_type: dict[str, float] = Field(default_factory=dict)
    structural_warnings: list[dict[str, Any]] = Field(default_factory=list)
```

- [ ] **Step 5: Populate the fields in the copilot route**

In `arena/app/routers/copilot.py`, after computing the existing `model_fitness` verdict and before returning, merge the cross-check. Locate where the verdict dict is built and add:

```python
from app import copilot  # ensure imported

# after `verdict = copilot.model_fitness(path, req.proposed_edges_as_dicts(), floor)` (or equivalent):
    cross = copilot.structural_cross_check(
        path,
        [e.model_dump() if hasattr(e, "model_dump") else dict(e) for e in req.proposed_edges],
        structural_floor=get_settings().arena_ai_structural_floor,
    )
    verdict["structural_fitness_by_type"] = cross["structural_fitness_by_type"]
    verdict["structural_warnings"] = cross["structural_warnings"]
    return ModelFitnessResponse(**verdict)
```

Add the new setting to `arena/app/config.py` `Settings`:

```python
    # XO.2: object types the copilot proposes below this replay fitness get a caveat.
    arena_ai_structural_floor: float = 0.5
```

- [ ] **Step 6: Run test to verify it passes**

Run: `cd /home/smudoshi/Github/Zephyrus/arena && python -m pytest tests/test_copilot_structural.py -v`
Expected: PASS

- [ ] **Step 7: Run the full sidecar suite (no regressions to the existing copilot gate)**

Run: `cd /home/smudoshi/Github/Zephyrus/arena && python -m pytest -q`
Expected: PASS — existing `test_copilot.py` still green (published decision unchanged)

- [ ] **Step 8: Commit**

```bash
git add arena/app/copilot.py arena/app/models.py arena/app/routers/copilot.py arena/app/config.py arena/tests/test_copilot_structural.py
git commit -m "feat(arena): additive structural replay-fitness cross-check on the copilot gate"
```

---

### Task 5: Laravel + frontend surfacing (Petri net + structural warnings)

**Files:**
- Modify: `app/Domain/Arena/ArenaSidecarClient.php` (add `petrinet()` method)
- Modify: `app/Domain/Arena/ArenaService.php` (add `petrinet()` passthrough)
- Modify: `app/Http/Controllers/Api/ArenaController.php` (+ `petrinet` action) and `routes/*` (register `api.arena.petrinet`)
- Create: `resources/js/Components/arena/PetriNetPane.tsx`
- Modify: `resources/js/features/arena/schema.ts` + `hooks.ts` (petri-net schema + hook)
- Modify: `resources/js/Pages/Analytics/Arena.tsx` (render the pane)
- Test: `tests/Feature/Arena/ArenaPetriNetTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Arena/ArenaPetriNetTest.php`:

```php
<?php

namespace Tests\Feature\Arena;

use App\Domain\Arena\ArenaSidecarClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArenaPetriNetTest extends TestCase
{
    public function test_petrinet_calls_the_sidecar_discover_petrinet_endpoint(): void
    {
        config()->set('services.arena.url', 'http://arena:8100');
        Http::fake([
            'arena:8100/discover/petrinet' => Http::response(
                ['object_types' => ['Encounter'], 'nets' => [], 'stats' => []], 200
            ),
        ]);

        $client = new ArenaSidecarClient();
        $out = $client->petrinet(['events' => [], 'objects' => []]);

        $this->assertIsArray($out);
        $this->assertSame(['Encounter'], $out['object_types']);
        Http::assertSent(fn ($r) => $r->url() === 'http://arena:8100/discover/petrinet');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ArenaPetriNetTest`
Expected: FAIL — `Call to undefined method ArenaSidecarClient::petrinet()`

- [ ] **Step 3: Add the client method**

In `app/Domain/Arena/ArenaSidecarClient.php`, add:

```php
    /**
     * Discover the object-centric Petri net for a de-identified OCEL doc (XO.2).
     *
     * @param  array<string, mixed>  $ocel
     * @param  array<int, array<string, mixed>>|null  $filters
     * @return array<string, mixed>|null
     */
    public function petrinet(array $ocel, ?array $filters = null): ?array
    {
        $body = ['ocel' => $ocel];
        if (! empty($filters)) {
            $body['filters'] = array_values($filters);
        }

        return $this->post('/discover/petrinet', $body);
    }
```

- [ ] **Step 4: Add the service passthrough**

In `app/Domain/Arena/ArenaService.php`, add:

```php
    /**
     * Object-centric Petri net for the current OCEL log (§XO.2). Uncached — a
     * Study read.
     *
     * @param  array<int, array<string, mixed>>|null  $filters
     * @return array<string, mixed>
     */
    public function petrinet(?array $filters = null): array
    {
        $doc = $this->exporter->export();
        $result = $this->client->petrinet($doc, $filters);

        if ($result === null) {
            return ['available' => false, 'reason' => 'sidecar_unavailable'];
        }

        return ['available' => true] + $result;
    }
```

- [ ] **Step 5: Add the controller action + route**

In `app/Http/Controllers/Api/ArenaController.php`, add:

```php
    public function petrinet(Request $request): JsonResponse
    {
        return response()->json($this->arena->petrinet($this->filtersFrom($request)));
    }
```

Register the route alongside the other Arena API routes (find where `api.arena.map` is defined — same file/group):

```php
Route::post('arena/petrinet', [ArenaController::class, 'petrinet'])->name('api.arena.petrinet');
```

(If XO.1 was not merged, add the `filtersFrom` helper from that plan's Task 4, Step 5, or pass `null`.)

- [ ] **Step 6: Add the frontend schema + hook**

In `resources/js/features/arena/schema.ts`, add:

```ts
export const arenaPetriNetResponseSchema = z.object({
  available: z.boolean().optional(),
  object_types: z.array(z.string()).default([]),
  nets: z.array(z.object({
    object_type: z.string(),
    places: z.array(z.object({ id: z.string(), initial: z.boolean(), final: z.boolean() })),
    transitions: z.array(z.object({ id: z.string(), label: z.string().nullable() })),
    arcs: z.array(z.object({ source: z.string(), target: z.string(), variable: z.boolean(), weight: z.number() })),
  })).default([]),
  stats: z.record(z.number()).default({}),
});
export type ArenaPetriNet = z.infer<typeof arenaPetriNetResponseSchema>;
```

In `resources/js/features/arena/hooks.ts`, add:

```ts
export function useArenaPetriNet(filters: ArenaFilter[] = []) {
  return useQuery({
    queryKey: ['arena', 'petrinet', filters],
    queryFn: async () => {
      const res = await axios.post(route('api.arena.petrinet'), { filters });
      return arenaPetriNetResponseSchema.parse(res.data);
    },
  });
}
```

- [ ] **Step 7: Create the PetriNetPane (structural summary MVP)**

Create `resources/js/Components/arena/PetriNetPane.tsx`. A full graph layout is an additive follow-up; the MVP renders one canon card per object type with place/transition/silent/variable counts:

```tsx
import type { ArenaPetriNet } from '@/features/arena/schema';

/**
 * Phase XO.2 MVP: per-object-type Petri-net structural summary. Full node-link
 * layout (via the existing OcdfgMap SVG machinery) is an additive follow-up; the
 * numbers here already tell an operator whether a process is linear or branchy.
 */
export function PetriNetPane({ data }: { data: ArenaPetriNet }) {
  if (!data.nets.length) {
    return (
      <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        No object-centric Petri net available for the current selection.
      </p>
    );
  }
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      {data.nets.map((net) => {
        const silent = net.transitions.filter((t) => t.label === null).length;
        const variable = net.arcs.filter((a) => a.variable).length;
        return (
          <div
            key={net.object_type}
            className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:bg-healthcare-surface-dark"
          >
            <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {net.object_type}
            </h3>
            <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              <dt>Places</dt><dd className="tabular-nums text-right">{net.places.length}</dd>
              <dt>Transitions</dt><dd className="tabular-nums text-right">{net.transitions.length}</dd>
              <dt>Silent (τ)</dt><dd className="tabular-nums text-right">{silent}</dd>
              <dt>Variable arcs</dt><dd className="tabular-nums text-right">{variable}</dd>
            </dl>
          </div>
        );
      })}
    </div>
  );
}
```

- [ ] **Step 8: Render the pane in the Arena page**

In `resources/js/Pages/Analytics/Arena.tsx`, import and render:

```tsx
import { PetriNetPane } from '@/Components/arena/PetriNetPane';
import { useArenaPetriNet } from '@/features/arena/hooks';
// ...
const petrinet = useArenaPetriNet(filters);
// in the JSX, in a new section:
{petrinet.data && <PetriNetPane data={petrinet.data} />}
```

- [ ] **Step 9: Verify PHP + frontend**

Run: `php artisan test --filter=ArenaPetriNetTest`
Run: `docker compose exec -T php sh -c "cd /var/www/html && vendor/bin/pint app/Domain/Arena app/Http/Controllers/Api/ArenaController.php"`
Run: `docker compose exec node sh -c "cd /app && npx tsc --noEmit" && docker compose exec node sh -c "cd /app && npx vite build"`
Run: `./scripts/check-ui-canon.sh`
Expected: test green, Pint clean, tsc + vite build succeed, canon passes

- [ ] **Step 10: Commit**

```bash
git add app/Domain/Arena/ app/Http/Controllers/Api/ArenaController.php routes/ resources/js/
git add tests/Feature/Arena/ArenaPetriNetTest.php
git commit -m "feat(arena): surface OC Petri net + structural warnings in the Study UI"
```

---

### Task 6: Final verification

- [ ] **Step 1: Clean-room guard**

Run: `./scripts/check-clean-room.sh`
Expected: `✅ clean-room: no ocelescope dependency or import found`

- [ ] **Step 2: Full suites**

Run: `cd /home/smudoshi/Github/Zephyrus/arena && python -m pytest -q`
Run: `php artisan test --filter=Arena`
Expected: both green

---

## Self-review checklist (run before handoff)

- **Spec coverage:** OC Petri-net discovery (Tasks 1–2) ✓; replay fitness (Task 3) ✓; copilot gate hardening (Task 4, additive/non-breaking) ✓; Laravel + frontend surfacing (Task 5) ✓.
- **Type consistency:** `petrinet.discover` returns `{object_types, nets, stats}` — matched by `PetriNetResponse` (Py), `ArenaPetriNetTest` (PHP), and `arenaPetriNetResponseSchema` (TS). `replay.fitness` returns `{by_object_type, min_fitness, mean_fitness}` — consumed by `structural_cross_check`. `structural_fitness_by_type`/`structural_warnings` names identical across `structural_cross_check`, `ModelFitnessResponse`, and the route.
- **No placeholders:** every code step is complete. "Full node-link layout is a follow-up" (PetriNetPane) and "drop `filters=` if XO.1 not merged" are design notes, not task dependencies. The `published` decision is deliberately unchanged — new fields are additive.
