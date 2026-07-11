"""Object-centric performance analytics (Part X §X.6, OPerA-style). Classic
performance analysis assumes one case and so mis-measures any metric that depends
on object interaction — most importantly waiting time at a hand-off. Computing
per object type (never flattening to one universal case) keeps those measures
honest: no convergence inflation, and both sides of a hand-off stay visible.

Two views:
- hand-off durations: per object type, the elapsed time between consecutive
  activities in that object's lifecycle (flow / waiting time), median + p90.
- synchronization: at events that touch several object types, how long each
  object type waited (since its own previous event) for the shared step — the
  signal that says whether a bottleneck is (e.g.) patient-side or bed-side.
"""

from __future__ import annotations

from typing import Any

from app.config import get_settings
from app.ocel_loader import read_ocel

try:
    import pm4py  # type: ignore
except Exception:  # pragma: no cover
    pm4py = None  # type: ignore

OCEL_EID = "ocel:eid"
OCEL_ACTIVITY = "ocel:activity"
OCEL_TIME = "ocel:timestamp"
OCEL_OID = "ocel:oid"
OCEL_TYPE = "ocel:type"


def analyze(
    path: str,
    object_types: list[str] | None = None,
    top: int = 25,
    filters: "list[BaseFilter] | None" = None,
) -> dict[str, Any]:
    from app.filters import BaseFilter, apply_filters  # noqa: F401

    ocel = read_ocel(path)
    if filters:
        ocel = apply_filters(ocel, filters)
    ceiling = get_settings().arena_max_handoff_hours * 3600
    return {
        "handoffs": _handoff_durations(ocel, object_types, top, ceiling),
        "synchronization": _synchronization(ocel, object_types, top, ceiling),
    }


def _handoff_durations(ocel, object_types: list[str] | None, top: int, ceiling: int) -> list[dict[str, Any]]:
    ots = [ot for ot in pm4py.ocel_get_object_types(ocel) if object_types is None or ot in object_types]  # type: ignore[union-attr]
    rows: list[dict[str, Any]] = []

    for ot in ots:
        try:
            flat = pm4py.ocel_flattening(ocel, ot)  # type: ignore[union-attr]
        except Exception:
            continue
        if flat is None or len(flat) == 0:
            continue

        flat = flat.sort_values(["case:concept:name", "time:timestamp"])
        grouped = flat.groupby("case:concept:name")
        flat = flat.assign(
            next_act=grouped["concept:name"].shift(-1),
            next_time=grouped["time:timestamp"].shift(-1),
        )
        flat["delta"] = (flat["next_time"] - flat["time:timestamp"]).dt.total_seconds()
        pairs = flat.dropna(subset=["next_act", "delta"])
        pairs = pairs[(pairs["delta"] >= 0) & (pairs["delta"] <= ceiling)]
        if len(pairs) == 0:
            continue

        for (source, target), series in pairs.groupby(["concept:name", "next_act"])["delta"]:
            if len(series) == 0:
                continue
            rows.append({
                "object_type": ot,
                "source": str(source),
                "target": str(target),
                "count": int(series.count()),
                "median_sec": round(float(series.median()), 1),
                "p90_sec": round(float(series.quantile(0.9)), 1),
                "mean_sec": round(float(series.mean()), 1),
            })

    rows.sort(key=lambda r: -r["median_sec"])
    return rows[:top]


def _synchronization(ocel, object_types: list[str] | None, top: int, ceiling: int) -> list[dict[str, Any]]:
    events = ocel.events[[OCEL_EID, OCEL_ACTIVITY, OCEL_TIME]]
    relations = ocel.relations[[OCEL_EID, OCEL_OID, OCEL_TYPE]]

    # events that genuinely synchronise ≥2 object types
    per_event_types = relations.groupby(OCEL_EID)[OCEL_TYPE].nunique()
    multi = set(per_event_types[per_event_types >= 2].index)
    if not multi:
        return []

    joined = relations.merge(events, on=OCEL_EID)
    joined = joined.sort_values([OCEL_OID, OCEL_TIME])
    joined["prev_time"] = joined.groupby(OCEL_OID)[OCEL_TIME].shift(1)
    joined["wait"] = (joined[OCEL_TIME] - joined["prev_time"]).dt.total_seconds()

    joined = joined[joined[OCEL_EID].isin(multi)].dropna(subset=["wait"])
    joined = joined[(joined["wait"] >= 0) & (joined["wait"] <= ceiling)]
    if object_types is not None:
        joined = joined[joined[OCEL_TYPE].isin(object_types)]
    if len(joined) == 0:
        return []

    rows: list[dict[str, Any]] = []
    for (activity, ot), series in joined.groupby([OCEL_ACTIVITY, OCEL_TYPE])["wait"]:
        if len(series) == 0:
            continue
        rows.append({
            "activity": str(activity),
            "object_type": str(ot),
            "count": int(series.count()),
            "median_wait_sec": round(float(series.median()), 1),
            "p90_wait_sec": round(float(series.quantile(0.9)), 1),
        })

    rows.sort(key=lambda r: -r["median_wait_sec"])
    return rows[:top]
