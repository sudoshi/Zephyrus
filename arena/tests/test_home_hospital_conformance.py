"""Home Hospital pathway conformance (ACUM-PRD-HAH-001 §6.3) against a
hand-built OCEL 2.0 fixture: a conformant episode (activation inside the 24h
SLA, waiver cadence held, escalation resolved in 22 min) and a deviant one
(late activation, cadence below the floor, escalation left open).
"""

from __future__ import annotations

import json

import pytest

from app import conformance


def _ev(eid: str, activity: str, time: str, oid: str, extra: dict | None = None) -> dict:
    attributes = [{"name": key, "value": value} for key, value in (extra or {}).items()]
    return {
        "id": eid,
        "type": activity,
        "time": time,
        "attributes": attributes,
        "relationships": [{"objectId": oid, "qualifier": "subject"}],
    }


FIXTURE = {
    "objectTypes": [
        {"name": "Home Episode", "attributes": []},
    ],
    "eventTypes": [
        {"name": a, "attributes": []}
        for a in [
            "home-refer", "home-activate", "home-visit-complete",
            "home-escalation-open", "home-escalation-resolve", "home-discharge",
        ]
    ],
    "objects": [
        {"id": "home-ep-1", "type": "Home Episode", "attributes": [], "relationships": []},
        {"id": "home-ep-2", "type": "Home Episode", "attributes": [], "relationships": []},
    ],
    "events": [
        # home-ep-1 — conformant: activation 4h after referral; two full ward
        # days each holding the 2-visit waiver floor; escalation resolved 22 min.
        _ev("h1", "home-refer", "2026-06-01T08:00:00Z", "home-ep-1"),
        _ev("h2", "home-activate", "2026-06-01T12:00:00Z", "home-ep-1"),
        _ev("h3", "home-visit-complete", "2026-06-01T15:00:00Z", "home-ep-1", {"waiver_required": "true"}),
        _ev("h4", "home-visit-complete", "2026-06-01T20:00:00Z", "home-ep-1", {"waiver_required": "true"}),
        _ev("h5", "home-visit-complete", "2026-06-02T09:00:00Z", "home-ep-1", {"waiver_required": "true"}),
        _ev("h6", "home-visit-complete", "2026-06-02T18:00:00Z", "home-ep-1", {"waiver_required": "true"}),
        _ev("h7", "home-escalation-open", "2026-06-02T21:00:00Z", "home-ep-1"),
        _ev("h8", "home-escalation-resolve", "2026-06-02T22:00:00Z", "home-ep-1", {"response_minutes": 22}),
        _ev("h9", "home-visit-complete", "2026-06-03T09:00:00Z", "home-ep-1", {"waiver_required": "true"}),
        _ev("h10", "home-visit-complete", "2026-06-03T11:00:00Z", "home-ep-1", {"waiver_required": "true"}),
        _ev("h11", "home-discharge", "2026-06-03T16:00:00Z", "home-ep-1"),
        # home-ep-2 — deviant: activation 30h after referral; one full ward day
        # with only one waiver visit; escalation opened, never resolved.
        _ev("h12", "home-refer", "2026-06-05T08:00:00Z", "home-ep-2"),
        _ev("h13", "home-activate", "2026-06-06T14:00:00Z", "home-ep-2"),
        _ev("h14", "home-visit-complete", "2026-06-06T18:00:00Z", "home-ep-2", {"waiver_required": "true"}),
        _ev("h15", "home-escalation-open", "2026-06-07T10:00:00Z", "home-ep-2"),
        _ev("h16", "home-discharge", "2026-06-07T20:00:00Z", "home-ep-2"),
    ],
}


@pytest.fixture
def ocel_path(tmp_path) -> str:
    p = tmp_path / "home-conformance-fixture.json"
    p.write_text(json.dumps(FIXTURE), encoding="utf-8")
    return str(p)


def test_home_hospital_conformance(ocel_path: str) -> None:
    results = conformance.check(ocel_path, pathway_key="home_hospital")
    assert len(results) == 1
    home = results[0]

    assert home["pathway"] == "home_hospital"
    assert home["cases"] == 2
    assert home["conformant"] == 1
    assert home["deviant"] == 1

    codes = {d["code"]: d["count"] for d in home["deviations"]}
    assert codes.get("activation_beyond_sla") == 1
    assert codes.get("visit_cadence_below_floor") == 1
    assert codes.get("escalation_unresolved") == 1
