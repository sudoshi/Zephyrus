"""Governed AI copilot — the trust gate (Part X §X.8.2).

The copilot's central safeguard: *a generated map is not asserted until it conforms
to the data.* When the copilot proposes a process model (an object-centric DFG — a
set of directly-follows edges per object type), this module conformance-checks it
against the actual OCEL log with pm4py and returns a fitness/precision score. The
Laravel orchestrator withholds any map below the fitness floor. The AI may PROPOSE;
the data ADJUDICATES.

Fitness is measured at the OC-DFG granularity the Arena already renders (Berti & van
der Aalst), frequency-weighted so admitting the COMMON real paths is what counts:

    fitness   = Σ freq(real edges the model admits)  / Σ freq(all real edges)   # recall of real behavior
    precision = |proposed ∩ real|                     / |proposed|              # share of the model grounded in reality

A hallucinated model (invented edges, missing the busy paths) scores low on both and
is withheld. Read-only, PHI-free (activity labels + de-identified object types only).
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


def model_fitness(
    path: str,
    proposed_edges: list[dict[str, str]],
    fitness_floor: float | None = None,
) -> dict[str, Any]:
    """Conformance-check a copilot-proposed OC-DFG against the log.

    `proposed_edges` is a flat list of ``{object_type, source, target}`` arcs (the
    same shape the discovery endpoint emits). Returns the fitness/precision verdict
    plus the evidence a narrative cites: the busy real paths the model MISSES and the
    edges it INVENTED.
    """
    settings = get_settings()
    floor = settings.arena_ai_fitness_floor if fitness_floor is None else float(fitness_floor)

    ocel = read_ocel(path)
    all_ots = set(pm4py.ocel_get_object_types(ocel))  # type: ignore[union-attr]

    proposed_by_ot: dict[str, set[tuple[str, str]]] = defaultdict(set)
    for edge in proposed_edges:
        ot = str(edge.get("object_type", ""))
        src = str(edge.get("source", ""))
        dst = str(edge.get("target", ""))
        if ot and src and dst:
            proposed_by_ot[ot].add((src, dst))

    # Only conformance-check object types that actually exist in the log; a proposed
    # type the log has never seen is pure invention (counted against precision below).
    checked_ots = [ot for ot in proposed_by_ot if ot in all_ots][: settings.arena_max_object_types]

    real_total_freq = 0
    covered_freq = 0
    proposed_total = 0
    grounded = 0
    invented: list[dict[str, Any]] = []
    missing: list[dict[str, Any]] = []

    for ot in checked_ots:
        try:
            flat = pm4py.ocel_flattening(ocel, ot)  # type: ignore[union-attr]
        except Exception:
            continue
        if flat is None or len(flat) == 0:
            continue

        dfg, _sa, _ea = pm4py.discover_dfg(flat)  # type: ignore[union-attr]
        real = {(str(a), str(b)): int(freq) for (a, b), freq in dfg.items()}
        proposed = proposed_by_ot[ot]

        for edge, freq in real.items():
            real_total_freq += freq
            if edge in proposed:
                covered_freq += freq
            else:
                missing.append({"object_type": ot, "source": edge[0], "target": edge[1], "frequency": freq})

        proposed_total += len(proposed)
        for edge in proposed:
            if edge in real:
                grounded += 1
            else:
                invented.append({"object_type": ot, "source": edge[0], "target": edge[1]})

    # Proposed edges for object types absent from the log are invented wholesale.
    for ot, edges in proposed_by_ot.items():
        if ot not in all_ots:
            proposed_total += len(edges)
            invented.extend({"object_type": ot, "source": a, "target": b} for (a, b) in edges)

    fitness = (covered_freq / real_total_freq) if real_total_freq > 0 else 0.0
    precision = (grounded / proposed_total) if proposed_total > 0 else 0.0
    published = real_total_freq > 0 and proposed_total > 0 and fitness >= floor

    reason: str | None = None
    if not published:
        if proposed_total == 0:
            reason = "empty_model"
        elif real_total_freq == 0:
            reason = "no_reference_behavior"
        else:
            reason = "below_fitness_floor"

    missing.sort(key=lambda m: -m["frequency"])

    return {
        "fitness": round(fitness, 4),
        "precision": round(precision, 4),
        "published": published,
        "fitness_floor": round(floor, 4),
        "object_types": checked_ots,
        "covered_freq": covered_freq,
        "total_real_freq": real_total_freq,
        "proposed_edges": proposed_total,
        "grounded_edges": grounded,
        "invented_edges": invented[:20],
        "missing_edges": missing[:10],
        "reason": reason,
    }
