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
