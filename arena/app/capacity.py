"""Capacity / occupancy series from a QEL payload (Part X, Phase XO.3).

Reconstructs the absolute occupancy curve per object as initial + cumulative sum
of the timestamped operations. Pure pandas — no pm4py — so /capacity works even
in a mining-less sidecar build. Read-only, PHI-free: unit-level counts only.
"""

from __future__ import annotations

from typing import Any

import pandas as pd


def series(payload: dict[str, Any], item_type: str | None = None, threshold: int | None = None) -> dict[str, Any]:
    initial_rows = payload.get("initial", []) or []
    op_rows = payload.get("operations", []) or []

    init_map = {(r["object_id"], r["item_type"]): int(r.get("quantity", 0)) for r in initial_rows}

    if not op_rows:
        return {"objects": [], "stats": {"objects": 0}}

    ops = pd.DataFrame(op_rows)
    if item_type:
        ops = ops[ops["item_type"] == item_type]
    if ops.empty:
        return {"objects": [], "stats": {"objects": 0}}

    ops["event_time"] = pd.to_datetime(ops["event_time"], utc=True)

    objects: list[dict[str, Any]] = []
    for (oid, itype), grp in ops.groupby(["object_id", "item_type"]):
        grp = grp.sort_values("event_time")
        base = init_map.get((oid, itype), 0)
        running = base + grp["delta"].cumsum()
        points = [{"time": t.isoformat(), "value": int(v)} for t, v in zip(grp["event_time"], running)]
        values = [base] + [p["value"] for p in points]

        obj: dict[str, Any] = {
            "object_id": str(oid),
            "item_type": str(itype),
            "series": points,
            "peak": int(max(values)),
            "nadir": int(min(values)),
            "current": int(values[-1]),
        }
        if threshold is not None:
            obj["periods_above_threshold"] = int(sum(1 for p in points if p["value"] > threshold))
        objects.append(obj)

    objects.sort(key=lambda o: -o["peak"])
    return {"objects": objects, "stats": {"objects": len(objects)}}
