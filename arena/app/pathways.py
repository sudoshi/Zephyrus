"""Reference care-pathway models (Part X §X.7). Patient safety AS conformance:
each safety-critical pathway is a *standard of care expressed as process*, and a
deviation is a real, observed departure from that standard — never a prediction.

For X3 these are clinically-interpretable rule sets (derived from the event
sequence + timing, NOT from any seeded label), evaluated per case. The engine
groups the OCEL log by the pathway's case object and applies `evaluate`, which
returns a list of deviation codes. Models are versioned and owner-attributable —
the fields a governed clinical review needs.
"""

from __future__ import annotations

from typing import Any, Callable

import pandas as pd

# SEP-3 antibiotic target: broad-spectrum antibiotics within 3 hours of
# sepsis recognition (SSC bundle).
SEPSIS_ABX_TARGET_MIN = 180


def _minutes_between(a: Any, b: Any) -> float | None:
    if a is None or b is None or pd.isna(a) or pd.isna(b):
        return None
    return (pd.Timestamp(b) - pd.Timestamp(a)).total_seconds() / 60.0


def evaluate_sepsis(timeline: dict[str, Any], counts: dict[str, int], group: pd.DataFrame) -> list[str]:
    """SEP-3 sepsis bundle: lactate + cultures-before-antibiotics + antibiotics
    within the 3h window + a repeat lactate."""
    deviations: list[str] = []
    recognition = timeline.get("sepsis_recognition")
    antibiotic = timeline.get("antibiotic_administration")
    culture = timeline.get("blood_culture_order")

    if "lactate_order" not in timeline:
        deviations.append("no_lactate")

    if antibiotic is None:
        deviations.append("no_antibiotic")
    else:
        minutes = _minutes_between(recognition, antibiotic)
        if minutes is not None and minutes > SEPSIS_ABX_TARGET_MIN:
            deviations.append("antibiotic_late")

    if culture is not None and antibiotic is not None and pd.Timestamp(culture) > pd.Timestamp(antibiotic):
        deviations.append("culture_after_antibiotic")

    if "repeat_lactate_result" not in timeline:
        deviations.append("no_repeat_lactate")

    return deviations


def evaluate_surgical_safety(timeline: dict[str, Any], counts: dict[str, int], group: pd.DataFrame) -> list[str]:
    """WHO Surgical Safety Checklist: Sign-In / Time-Out / Sign-Out all present
    and none flagged."""
    deviations: list[str] = []
    safety_checks = group[group["ocel:activity"] == "Safety_Check"]

    if len(safety_checks) < 3:
        deviations.append("safety_step_missing")

    if "status" in group.columns:
        conformant_status = {"complete", "completed", "verified", "done", "ok", "pass", "passed"}
        flagged = safety_checks[~safety_checks["status"].astype(str).str.lower().isin(conformant_status)]
        if len(flagged) > 0:
            deviations.append("safety_check_flagged")

    return deviations


# The versioned pathway registry. `case_type` is the OCEL object the pathway is
# grouped by; `trigger` is the activity whose presence marks a case as being on
# the pathway; `activities` bounds the sub-log the engine extracts.
PATHWAYS: dict[str, dict[str, Any]] = {
    "sepsis": {
        "label": "Sepsis bundle (SEP-3)",
        "version": 1,
        "owner": "clinical:critical-care",
        "case_type": "Encounter",
        "trigger": "sepsis_recognition",
        "activities": [
            "sepsis_recognition",
            "vitals_sirs",
            "lactate_order",
            "lactate_result",
            "blood_culture_order",
            "antibiotic_administration",
            "fluid_bolus_30mlkg",
            "vasopressor_start",
            "repeat_lactate_order",
            "repeat_lactate_result",
        ],
        "evaluate": evaluate_sepsis,
        "deviation_labels": {
            "no_lactate": "Lactate not ordered",
            "no_antibiotic": "No antibiotic administered",
            "antibiotic_late": "Antibiotic beyond the 3-hour target",
            "culture_after_antibiotic": "Blood cultures drawn after antibiotics",
            "no_repeat_lactate": "Repeat lactate not documented",
        },
    },
    "surgical_safety": {
        "label": "WHO Surgical Safety Checklist",
        "version": 1,
        "owner": "clinical:perioperative",
        "case_type": "OR Case",
        "trigger": "Safety_Check",
        "activities": ["Safety_Check"],
        "evaluate": evaluate_surgical_safety,
        "deviation_labels": {
            "safety_step_missing": "A checklist step (Sign-In / Time-Out / Sign-Out) is missing",
            "safety_check_flagged": "A checklist step was flagged incomplete",
        },
    },
}

EvaluatorFn = Callable[[dict[str, Any], dict[str, int], pd.DataFrame], list[str]]
