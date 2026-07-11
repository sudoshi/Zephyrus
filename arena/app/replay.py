"""Per-object-type token-based replay fitness (Part X, Phase XO.2).

For each object type we flatten the OCEL to that type's traces, mine an inductive
Petri net, and replay the log against it. Token-based replay fitness is the
pragmatic, well-supported cousin of alignment fitness in pm4py; it measures how
well the real behavior fits a structured control-flow model — a stronger signal
than the copilot's DFG edge-set recall, and the honest name for what it computes.

Clean-room (see arena/CLEAN-ROOM.md). Read-only, PHI-free.
"""

from __future__ import annotations

from typing import Any

from app.config import get_settings
from app.ocel_loader import read_ocel

try:
    import pm4py  # type: ignore
except Exception:  # pragma: no cover
    pm4py = None  # type: ignore


def fitness(
    path: str,
    object_types: list[str] | None = None,
    filters: list[Any] | None = None,
) -> dict[str, Any]:
    """Token-based replay fitness per object type + a min/mean aggregate.

    Returns a dict with:
      - by_object_type: list of dicts with object_type, fitness (0.0–1.0),
        and fitting_traces_pct (0.0–100.0).
      - min_fitness: minimum fitness across all object types (None if no rows).
      - mean_fitness: mean fitness across all object types (None if no rows).

    Empirically verified against pm4py 2.7.23.1:
      - ocel_get_object_types(ocel) → list[str]
      - ocel_flattening(ocel, ot) → DataFrame with concept:name / case:concept:name /
        time:timestamp columns (auto-detected by discover_petri_net_inductive)
      - discover_petri_net_inductive(flat, noise_threshold=0.0) → (net, im, fm)
      - fitness_token_based_replay(flat, net, im, fm) → dict with log_fitness +
        percentage_of_fitting_traces keys
    """
    settings = get_settings()
    ocel = read_ocel(path)
    if filters:
        from app.filters import apply_filters

        ocel = apply_filters(ocel, filters)

    all_ots = list(pm4py.ocel_get_object_types(ocel))  # type: ignore[union-attr]
    ots = [ot for ot in all_ots if object_types is None or ot in object_types]
    ots = ots[: settings.arena_max_object_types]

    rows: list[dict[str, Any]] = []
    for ot in ots:
        try:
            flat = pm4py.ocel_flattening(ocel, ot)  # type: ignore[union-attr]
        except Exception:
            continue
        if flat is None or len(flat) == 0:
            continue
        try:
            net, im, fm = pm4py.discover_petri_net_inductive(flat, noise_threshold=0.0)  # type: ignore[union-attr]
            result = pm4py.fitness_token_based_replay(flat, net, im, fm)  # type: ignore[union-attr]
        except Exception:
            continue
        rows.append({
            "object_type": str(ot),
            "fitness": round(float(result.get("log_fitness", 0.0)), 4),
            "fitting_traces_pct": round(float(result.get("percentage_of_fitting_traces", 0.0)), 2),
        })

    fitnesses = [r["fitness"] for r in rows]
    return {
        "by_object_type": rows,
        "min_fitness": round(min(fitnesses), 4) if fitnesses else None,
        "mean_fitness": round(sum(fitnesses) / len(fitnesses), 4) if fitnesses else None,
    }
