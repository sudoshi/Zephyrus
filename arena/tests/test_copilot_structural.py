"""The copilot fitness verdict carries an additive structural cross-check: for
each object type the model proposes, the log's replay fitness, and a warning list
for types whose real process fits poorly (the AI claims structure the data doesn't exhibit)."""

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


def test_structural_warning_for_object_type_with_no_events():
    # The copilot proposes structure for an object type that has zero events in the
    # log — the strongest "structure that isn't there" case. It must earn a warning,
    # not be silently dropped.
    proposed = [{"object_type": "Ghost", "source": "triage", "target": "admit"}]
    out = copilot.structural_cross_check(_toy_path(), proposed, structural_floor=0.5)
    assert "Ghost" not in out["structural_fitness_by_type"]
    warnings = {w["object_type"]: w for w in out["structural_warnings"]}
    assert "Ghost" in warnings
    assert warnings["Ghost"]["reason"] == "no_events_in_log"
    assert warnings["Ghost"]["fitness"] is None
