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
