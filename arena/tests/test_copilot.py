"""Copilot conformance-fitness gate tests (Part X §X.8.2) against a hand-built OCEL
2.0 fixture (runs where pm4py is installed). The central safeguard: the AI may
PROPOSE a map, but the DATA adjudicates — a faithful model publishes with high
fitness; a hallucinated one is withheld. Expected values are hand-computed from the
fixture's directly-follows relations.
"""

from __future__ import annotations

import json

import pytest

from app import copilot


def _ev(eid: str, activity: str, time: str, objects: list[tuple[str, str]]) -> dict:
    return {
        "id": eid,
        "type": activity,
        "time": time,
        "attributes": [],
        "relationships": [{"objectId": oid, "qualifier": qual} for oid, qual in objects],
    }


# Encounter lifecycle: admit → place → depart, twice. The real Encounter DFG is
# {(admit,place): 2, (place,depart): 2}  → total edge-frequency 4.
FIXTURE = {
    "objectTypes": [{"name": "Encounter", "attributes": []}, {"name": "Bed", "attributes": []}],
    "eventTypes": [{"name": n, "attributes": []} for n in ["admit", "place", "depart"]],
    "objects": [
        {"id": "enc-1", "type": "Encounter", "attributes": [], "relationships": []},
        {"id": "enc-2", "type": "Encounter", "attributes": [], "relationships": []},
        {"id": "bed-1", "type": "Bed", "attributes": [], "relationships": []},
    ],
    "events": [
        _ev("e1", "admit", "2026-06-01T08:00:00Z", [("enc-1", "subject")]),
        _ev("e2", "place", "2026-06-01T09:00:00Z", [("enc-1", "subject"), ("bed-1", "resource")]),
        _ev("e3", "depart", "2026-06-01T11:00:00Z", [("enc-1", "subject")]),
        _ev("e4", "admit", "2026-06-02T08:00:00Z", [("enc-2", "subject")]),
        _ev("e5", "place", "2026-06-02T08:30:00Z", [("enc-2", "subject"), ("bed-1", "resource")]),
        _ev("e6", "depart", "2026-06-02T10:30:00Z", [("enc-2", "subject")]),
    ],
}

REAL_EDGES = [
    {"object_type": "Encounter", "source": "admit", "target": "place"},
    {"object_type": "Encounter", "source": "place", "target": "depart"},
]


@pytest.fixture
def ocel_path(tmp_path) -> str:
    p = tmp_path / "copilot-fixture.json"
    p.write_text(json.dumps(FIXTURE), encoding="utf-8")
    return str(p)


def test_faithful_model_publishes(ocel_path: str) -> None:
    r = copilot.model_fitness(ocel_path, REAL_EDGES, fitness_floor=0.8)
    assert r["published"] is True
    assert r["fitness"] == 1.0       # every real edge-instance admitted
    assert r["precision"] == 1.0     # every proposed edge is real
    assert r["reason"] is None
    assert r["invented_edges"] == []


def test_hallucinated_model_is_withheld(ocel_path: str) -> None:
    bad = [
        {"object_type": "Encounter", "source": "admit", "target": "depart"},   # invented shortcut, skips place
        {"object_type": "Encounter", "source": "depart", "target": "triage"},  # invented activity
    ]
    r = copilot.model_fitness(ocel_path, bad, fitness_floor=0.8)
    assert r["published"] is False
    assert r["fitness"] < 0.8
    assert r["reason"] == "below_fitness_floor"
    assert len(r["invented_edges"]) >= 1   # the hallucinated arcs are surfaced


def test_partial_model_scores_between(ocel_path: str) -> None:
    # Only the first hand-off → covers 2 of 4 real edge-instances → fitness 0.5;
    # the one proposed edge IS real → precision 1.0; below the 0.8 floor → withheld.
    partial = [{"object_type": "Encounter", "source": "admit", "target": "place"}]
    r = copilot.model_fitness(ocel_path, partial, fitness_floor=0.8)
    assert r["fitness"] == 0.5
    assert r["precision"] == 1.0
    assert r["published"] is False
    assert len(r["missing_edges"]) >= 1    # the omitted busy edge is surfaced


def test_empty_model_is_withheld(ocel_path: str) -> None:
    r = copilot.model_fitness(ocel_path, [], fitness_floor=0.8)
    assert r["published"] is False
    assert r["reason"] == "empty_model"


def test_floor_governs_publication(ocel_path: str) -> None:
    # The partial (0.5) model publishes only if the floor is dropped below it.
    partial = [{"object_type": "Encounter", "source": "admit", "target": "place"}]
    assert copilot.model_fitness(ocel_path, partial, fitness_floor=0.4)["published"] is True
    assert copilot.model_fitness(ocel_path, partial, fitness_floor=0.6)["published"] is False
