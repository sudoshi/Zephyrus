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
