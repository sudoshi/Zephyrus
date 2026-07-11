"""Object-centric discovery (Part X §X.5). The OC-DFG is rendered as the union of
per-object-type directly-follows relations — each activity node tagged with the
object types that touch it, each arc tagged with its single object type. This is
the standard object-centric DFG view (Berti & van der Aalst): flattening PER
OBJECT TYPE is legitimate (it is how the OC-DFG is defined), unlike flattening to
one universal case, which is the convergence/divergence pathology X.1 warns of.

pm4py does the mining; this module transforms its output into the flat
nodes/edges contract the React Study UI renders as SVG.
"""

from __future__ import annotations

from collections import defaultdict
from typing import Any

from app.config import get_settings
from app.ocel_loader import read_ocel

try:
    import pm4py  # type: ignore
except Exception:  # pragma: no cover
    pm4py = None  # type: ignore

# pm4py OCEL default column names.
OCEL_ACTIVITY = "ocel:activity"
OCEL_TYPE = "ocel:type"


def summarize(path: str) -> dict[str, Any]:
    """Object/event/activity counts — the cheap 'is this log worth mining' probe."""
    ocel = read_ocel(path)
    events_df = ocel.events
    objects_df = ocel.objects
    activities = events_df[OCEL_ACTIVITY].value_counts().to_dict()
    ot_counts = objects_df[OCEL_TYPE].value_counts().to_dict()
    return {
        "events": int(len(events_df)),
        "objects": int(len(objects_df)),
        "object_types": {str(k): int(v) for k, v in ot_counts.items()},
        "activities": {str(k): int(v) for k, v in activities.items()},
    }


def discover(
    path: str,
    object_types: list[str] | None = None,
    activity_min_freq: int | None = None,
    filters: "list[BaseFilter] | None" = None,
) -> dict[str, Any]:
    """Discover the object-centric DFG for the (optionally filtered) object types."""
    from app.filters import BaseFilter, apply_filters  # noqa: F401

    settings = get_settings()
    ocel = read_ocel(path)
    if filters:
        ocel = apply_filters(ocel, filters)

    all_ots = list(pm4py.ocel_get_object_types(ocel))  # type: ignore[union-attr]
    ots = [ot for ot in all_ots if object_types is None or ot in object_types]
    ots = ots[: settings.arena_max_object_types]

    node_freq: dict[str, int] = defaultdict(int)
    node_ots: dict[str, set[str]] = defaultdict(set)
    edges: list[dict[str, Any]] = []

    for ot in ots:
        try:
            flat = pm4py.ocel_flattening(ocel, ot)  # type: ignore[union-attr]
        except Exception:
            continue
        if flat is None or len(flat) == 0:
            continue

        for act, cnt in flat["concept:name"].value_counts().items():
            node_freq[str(act)] += int(cnt)
            node_ots[str(act)].add(ot)

        dfg, _sa, _ea = pm4py.discover_dfg(flat)  # type: ignore[union-attr]
        for (a, b), freq in dfg.items():
            edges.append({"source": str(a), "target": str(b), "object_type": ot, "frequency": int(freq)})

    min_freq = activity_min_freq if activity_min_freq is not None else settings.arena_default_activity_min_freq
    nodes = [
        {"id": act, "activity": act, "frequency": freq, "object_types": sorted(node_ots[act])}
        for act, freq in node_freq.items()
        if freq >= min_freq
    ]
    kept = {n["id"] for n in nodes}
    edges = [e for e in edges if e["source"] in kept and e["target"] in kept]

    nodes.sort(key=lambda n: -n["frequency"])
    edges.sort(key=lambda e: -e["frequency"])

    return {
        "object_types": ots,
        "nodes": nodes,
        "edges": edges,
        "stats": {"object_types": len(ots), "nodes": len(nodes), "edges": len(edges)},
    }
