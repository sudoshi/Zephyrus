"""Conformance engine (Part X §X.7). Measures adherence of the live OCEL log to
the reference care pathways — the established process-mining method for guideline
compliance. For X3 this is a batch check over the projected log (the online
prefix-alignment tail is a later refinement); every deviation it reports is an
*observed* departure from the standard, computed from the event sequence and
timing, so it earns a signal rather than manufacturing a prediction.
"""

from __future__ import annotations

from typing import Any

import pandas as pd

from app.ocel_loader import read_ocel
from app.pathways import PATHWAYS

OCEL_EID = "ocel:eid"
OCEL_ACTIVITY = "ocel:activity"
OCEL_TIME = "ocel:timestamp"
OCEL_OID = "ocel:oid"
OCEL_TYPE = "ocel:type"


def check(
    path: str,
    pathway_key: str | None = None,
    sample_limit: int = 8,
    filters: "list[BaseFilter] | None" = None,
    per_case: bool = False,
    case_ids: "list[str] | None" = None,
) -> list[dict[str, Any]]:
    """Run conformance for one pathway (or all) over the OCEL log at `path`.

    `per_case=True` additionally returns a verdict for every evaluated case
    (`case_results`) — the seam the 4D Navigator's per-patient adherence
    surface reads (FLOW-4D plan §8 Phase A2, finding CF-2). `case_ids`
    restricts WHICH verdicts are returned; the aggregate counts always cover
    the full log so rates never silently change meaning.
    """
    from app.filters import BaseFilter, apply_filters  # noqa: F401

    ocel = read_ocel(path)
    if filters:
        ocel = apply_filters(ocel, filters)
    keys = [pathway_key] if pathway_key else list(PATHWAYS.keys())
    return [
        _check_one(ocel.events, ocel.relations, PATHWAYS[key], key, sample_limit, per_case, case_ids)
        for key in keys
        if key in PATHWAYS
    ]


def _check_one(
    events,
    relations,
    spec: dict[str, Any],
    key: str,
    sample_limit: int,
    per_case: bool = False,
    case_ids: "list[str] | None" = None,
) -> dict[str, Any]:
    activities = spec["activities"]
    trigger = spec["trigger"]
    evaluate = spec["evaluate"]
    wanted = set(case_ids) if case_ids is not None else None

    sub = events[events[OCEL_ACTIVITY].isin(activities)]
    rel = relations[relations[OCEL_TYPE] == spec["case_type"]][[OCEL_EID, OCEL_OID]]
    merged = sub.merge(rel, on=OCEL_EID, how="inner")

    cases = 0
    conformant = 0
    deviation_counts: dict[str, int] = {}
    samples: list[dict[str, Any]] = []
    case_results: list[dict[str, Any]] = []

    for oid, group in merged.groupby(OCEL_OID):
        ordered = group.sort_values(OCEL_TIME)
        timeline: dict[str, Any] = {}
        for _, row in ordered.iterrows():
            activity = row[OCEL_ACTIVITY]
            if activity not in timeline:
                timeline[activity] = row[OCEL_TIME]

        if trigger not in timeline:
            continue

        cases += 1
        counts = ordered[OCEL_ACTIVITY].value_counts().to_dict()
        deviations = evaluate(timeline, counts, ordered)

        if deviations:
            for code in deviations:
                deviation_counts[code] = deviation_counts.get(code, 0) + 1
            if len(samples) < sample_limit:
                samples.append({"case_id": str(oid), "deviations": deviations})
        else:
            conformant += 1

        if per_case and (wanted is None or str(oid) in wanted):
            case_results.append({
                "case_id": str(oid),
                "conformant": not deviations,
                "deviations": list(deviations),
                # First observed occurrence per activity — the expected-vs-
                # observed lane the adherence panel renders.
                "activity_timeline": {
                    activity: pd.Timestamp(ts).isoformat()
                    for activity, ts in timeline.items()
                },
            })

    deviant = cases - conformant
    ranked = sorted(
        (
            {"code": code, "label": spec["deviation_labels"].get(code, code), "count": count}
            for code, count in deviation_counts.items()
        ),
        key=lambda item: -item["count"],
    )

    return {
        "pathway": key,
        "label": spec["label"],
        "version": spec["version"],
        "owner": spec["owner"],
        "case_type": spec["case_type"],
        "cases": cases,
        "conformant": conformant,
        "deviant": deviant,
        "conformance_rate": round(conformant / cases, 4) if cases else None,
        "deviations": ranked,
        "sample_deviant_cases": samples,
        "case_results": case_results,
    }
