"""Capacity series — cumulative occupancy from a QEL payload (no pm4py needed)."""

from __future__ import annotations

from app import capacity


def test_series_reconstructs_cumulative_occupancy():
    payload = {
        "initial": [{"object_id": "Unit:5N", "item_type": "occupied_beds", "quantity": 2}],
        "operations": [
            {"object_id": "Unit:5N", "item_type": "occupied_beds", "delta": 1, "event_time": "2026-01-01T00:00:00Z"},
            {"object_id": "Unit:5N", "item_type": "occupied_beds", "delta": 1, "event_time": "2026-01-01T01:00:00Z"},
            {"object_id": "Unit:5N", "item_type": "occupied_beds", "delta": -1, "event_time": "2026-01-01T02:00:00Z"},
        ],
    }
    out = capacity.series(payload)
    unit = out["objects"][0]
    assert unit["object_id"] == "Unit:5N"
    assert [p["value"] for p in unit["series"]] == [3, 4, 3]  # 2 +1 +1 -1
    assert unit["peak"] == 4
    assert unit["nadir"] == 2  # the baseline
    assert unit["current"] == 3


def test_series_handles_empty_operations():
    assert capacity.series({"initial": [], "operations": []})["objects"] == []
