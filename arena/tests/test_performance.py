"""Object-centric performance smoke tests against a hand-built OCEL 2.0 fixture
(runs where pm4py is installed). Asserts per-object-type hand-off durations and
synchronization waits at a multi-object event.
"""

from __future__ import annotations

import json

import pytest

from app import performance


def _ev(eid: str, activity: str, time: str, objects: list[tuple[str, str]]) -> dict:
    return {
        "id": eid,
        "type": activity,
        "time": time,
        "attributes": [],
        "relationships": [{"objectId": oid, "qualifier": qual} for oid, qual in objects],
    }


FIXTURE = {
    "objectTypes": [
        {"name": "Encounter", "attributes": []},
        {"name": "Bed", "attributes": []},
    ],
    "eventTypes": [{"name": n, "attributes": []} for n in ["admit", "place", "depart"]],
    "objects": [
        {"id": "enc-1", "type": "Encounter", "attributes": [], "relationships": []},
        {"id": "enc-2", "type": "Encounter", "attributes": [], "relationships": []},
        {"id": "bed-1", "type": "Bed", "attributes": [], "relationships": []},
    ],
    "events": [
        _ev("e1", "admit", "2026-06-01T08:00:00Z", [("enc-1", "subject")]),
        _ev("e2", "place", "2026-06-01T09:00:00Z", [("enc-1", "subject"), ("bed-1", "resource")]),  # +3600s
        _ev("e3", "depart", "2026-06-01T11:00:00Z", [("enc-1", "subject")]),  # +7200s
        _ev("e4", "admit", "2026-06-02T08:00:00Z", [("enc-2", "subject")]),
        _ev("e5", "place", "2026-06-02T08:30:00Z", [("enc-2", "subject"), ("bed-1", "resource")]),  # +1800s
        _ev("e6", "depart", "2026-06-02T10:30:00Z", [("enc-2", "subject")]),  # +7200s
    ],
}


@pytest.fixture
def ocel_path(tmp_path) -> str:
    p = tmp_path / "performance-fixture.json"
    p.write_text(json.dumps(FIXTURE), encoding="utf-8")
    return str(p)


def test_handoff_durations_are_object_centric(ocel_path: str) -> None:
    result = performance.analyze(ocel_path)
    handoffs = {(h["object_type"], h["source"], h["target"]): h for h in result["handoffs"]}

    admit_place = handoffs[("Encounter", "admit", "place")]
    assert admit_place["count"] == 2
    assert admit_place["median_sec"] == 2700.0  # median of 3600 and 1800

    place_depart = handoffs[("Encounter", "place", "depart")]
    assert place_depart["median_sec"] == 7200.0


def test_synchronization_reports_wait_at_shared_event(ocel_path: str) -> None:
    result = performance.analyze(ocel_path)
    sync = {(s["activity"], s["object_type"]): s for s in result["synchronization"]}

    # 'place' synchronises Encounter + Bed; the Encounter side waited since admit.
    enc_place = sync[("place", "Encounter")]
    assert enc_place["count"] == 2
    assert enc_place["median_wait_sec"] == 2700.0


def test_object_type_filter(ocel_path: str) -> None:
    result = performance.analyze(ocel_path, object_types=["Encounter"])
    assert all(h["object_type"] == "Encounter" for h in result["handoffs"])
