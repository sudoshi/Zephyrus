"""Discovery smoke tests against a hand-built OCEL 2.0 fixture. Runs in the
container/CI where pm4py is installed (like eddy/tests). Asserts the object
-centric DFG is the union of per-object-type directly-follows relations.
"""

from __future__ import annotations

import json

import pytest

from app import discovery

# Minimal OCEL 2.0 doc: two encounters through admit→place→depart(partial),
# sharing one bed. Encounter DFG: admit→place (2), place→depart (1).
# Bed DFG: place→place (1) across the two occupancies.
FIXTURE = {
    "objectTypes": [
        {"name": "Encounter", "attributes": []},
        {"name": "Bed", "attributes": []},
    ],
    "eventTypes": [
        {"name": "admit", "attributes": []},
        {"name": "place", "attributes": []},
        {"name": "depart", "attributes": []},
    ],
    "objects": [
        {"id": "enc-1", "type": "Encounter", "attributes": [], "relationships": []},
        {"id": "enc-2", "type": "Encounter", "attributes": [], "relationships": []},
        {"id": "bed-1", "type": "Bed", "attributes": [], "relationships": []},
    ],
    "events": [
        {"id": "e1", "type": "admit", "time": "2026-06-01T08:00:00Z", "attributes": [], "relationships": [{"objectId": "enc-1", "qualifier": "subject"}]},
        {"id": "e2", "type": "place", "time": "2026-06-01T09:00:00Z", "attributes": [], "relationships": [{"objectId": "enc-1", "qualifier": "subject"}, {"objectId": "bed-1", "qualifier": "resource"}]},
        {"id": "e3", "type": "depart", "time": "2026-06-01T10:00:00Z", "attributes": [], "relationships": [{"objectId": "enc-1", "qualifier": "subject"}]},
        {"id": "e4", "type": "admit", "time": "2026-06-02T08:00:00Z", "attributes": [], "relationships": [{"objectId": "enc-2", "qualifier": "subject"}]},
        {"id": "e5", "type": "place", "time": "2026-06-02T09:00:00Z", "attributes": [], "relationships": [{"objectId": "enc-2", "qualifier": "subject"}, {"objectId": "bed-1", "qualifier": "resource"}]},
    ],
}


@pytest.fixture
def ocel_path(tmp_path) -> str:
    p = tmp_path / "fixture-ocel.json"
    p.write_text(json.dumps(FIXTURE), encoding="utf-8")
    return str(p)


def test_summarize_counts(ocel_path: str) -> None:
    s = discovery.summarize(ocel_path)
    assert s["events"] == 5
    assert s["objects"] == 3
    assert s["object_types"] == {"Encounter": 2, "Bed": 1}
    assert s["activities"]["admit"] == 2
    assert s["activities"]["place"] == 2
    assert s["activities"]["depart"] == 1


def test_discover_is_object_centric(ocel_path: str) -> None:
    d = discovery.discover(ocel_path)
    assert set(d["object_types"]) == {"Encounter", "Bed"}

    activities = {n["activity"] for n in d["nodes"]}
    assert {"admit", "place", "depart"} <= activities

    # 'place' is touched by BOTH Encounter and Bed lifecycles.
    place = next(n for n in d["nodes"] if n["activity"] == "place")
    assert set(place["object_types"]) == {"Encounter", "Bed"}

    # The Encounter admit→place arc carries both encounters.
    enc_edges = {(e["source"], e["target"]): e["frequency"] for e in d["edges"] if e["object_type"] == "Encounter"}
    assert enc_edges.get(("admit", "place")) == 2
    assert enc_edges.get(("place", "depart")) == 1


def test_object_type_filter(ocel_path: str) -> None:
    d = discovery.discover(ocel_path, object_types=["Encounter"])
    assert d["object_types"] == ["Encounter"]
    assert all(e["object_type"] == "Encounter" for e in d["edges"])
