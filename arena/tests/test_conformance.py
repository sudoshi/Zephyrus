"""Conformance smoke tests against a hand-built OCEL 2.0 fixture (runs in the
container/CI where pm4py is installed). Asserts the engine derives deviations
from the event sequence + timing — a conformant sepsis case, a late-antibiotic
one, a full checklist, and one with a missing step.
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
        {"name": "Encounter", "attributes": []},
        {"name": "OR Case", "attributes": []},
    ],
    "eventTypes": [
        {"name": a, "attributes": []}
        for a in [
            "sepsis_recognition", "lactate_order", "blood_culture_order",
            "antibiotic_administration", "repeat_lactate_result", "Safety_Check",
        ]
    ],
    "objects": [
        {"id": "enc-1", "type": "Encounter", "attributes": [], "relationships": []},
        {"id": "enc-2", "type": "Encounter", "attributes": [], "relationships": []},
        {"id": "orcase-1", "type": "OR Case", "attributes": [], "relationships": []},
        {"id": "orcase-2", "type": "OR Case", "attributes": [], "relationships": []},
    ],
    "events": [
        # enc-1 — conformant sepsis bundle (abx at 60 min, cultures first, repeat lactate)
        _ev("e1", "sepsis_recognition", "2026-06-01T08:00:00Z", "enc-1"),
        _ev("e2", "lactate_order", "2026-06-01T08:05:00Z", "enc-1"),
        _ev("e3", "blood_culture_order", "2026-06-01T08:10:00Z", "enc-1"),
        _ev("e4", "antibiotic_administration", "2026-06-01T09:00:00Z", "enc-1"),
        _ev("e5", "repeat_lactate_result", "2026-06-01T14:00:00Z", "enc-1"),
        # enc-2 — deviant: antibiotic at 240 min (late) + no repeat lactate
        _ev("e6", "sepsis_recognition", "2026-06-02T08:00:00Z", "enc-2"),
        _ev("e7", "lactate_order", "2026-06-02T08:05:00Z", "enc-2"),
        _ev("e8", "antibiotic_administration", "2026-06-02T12:00:00Z", "enc-2"),
        # orcase-1 — full WHO checklist (3 Safety_Check, complete)
        _ev("e9", "Safety_Check", "2026-06-03T07:00:00Z", "orcase-1", {"status": "Complete"}),
        _ev("e10", "Safety_Check", "2026-06-03T07:20:00Z", "orcase-1", {"status": "Complete"}),
        _ev("e11", "Safety_Check", "2026-06-03T09:00:00Z", "orcase-1", {"status": "Complete"}),
        # orcase-2 — a checklist step missing (only 2 Safety_Check)
        _ev("e12", "Safety_Check", "2026-06-04T07:00:00Z", "orcase-2", {"status": "Complete"}),
        _ev("e13", "Safety_Check", "2026-06-04T07:20:00Z", "orcase-2", {"status": "Complete"}),
    ],
}


@pytest.fixture
def ocel_path(tmp_path) -> str:
    p = tmp_path / "conformance-fixture.json"
    p.write_text(json.dumps(FIXTURE), encoding="utf-8")
    return str(p)


def _by_key(results: list[dict], key: str) -> dict:
    return next(r for r in results if r["pathway"] == key)


def test_sepsis_conformance(ocel_path: str) -> None:
    sepsis = _by_key(conformance.check(ocel_path), "sepsis")
    assert sepsis["cases"] == 2
    assert sepsis["conformant"] == 1
    assert sepsis["deviant"] == 1
    codes = {d["code"]: d["count"] for d in sepsis["deviations"]}
    assert codes.get("antibiotic_late") == 1
    assert codes.get("no_repeat_lactate") == 1


def test_surgical_safety_conformance(ocel_path: str) -> None:
    surgical = _by_key(conformance.check(ocel_path), "surgical_safety")
    assert surgical["cases"] == 2
    assert surgical["conformant"] == 1
    assert surgical["deviant"] == 1
    codes = {d["code"] for d in surgical["deviations"]}
    assert "safety_step_missing" in codes


def test_single_pathway_filter(ocel_path: str) -> None:
    results = conformance.check(ocel_path, pathway_key="sepsis")
    assert len(results) == 1
    assert results[0]["pathway"] == "sepsis"
