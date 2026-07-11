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
    assert 0.0 <= by_type["Encounter"]["fitting_traces_pct"] <= 100.0
    assert 0.0 <= result["min_fitness"] <= 1.0


def test_replay_fitness_empty_when_no_object_type_matches():
    # Restricting to an object type that isn't in the log yields no rows, and the
    # aggregates degrade to None (not a min([])/ZeroDivision crash).
    result = replay.fitness(_write_structured_ocel2_json(), object_types=["Nonexistent"])
    assert result["by_object_type"] == []
    assert result["min_fitness"] is None
    assert result["mean_fitness"] is None
