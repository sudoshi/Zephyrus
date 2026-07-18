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

# Home Hospital (ACUM-PRD-HAH-001 §6.3) — the designed pathway's operating
# numbers: referral→activation within 24h, the CMS AHCAH waiver floor of two
# in-person visits per full ward day, and the 30-minute emergency-response
# requirement (MedPAC 2024).
HOME_ACTIVATION_SLA_MIN = 24 * 60
HOME_WAIVER_VISITS_PER_DAY = 2
HOME_RESPONSE_FLOOR_MIN = 30


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


def evaluate_home_hospital(timeline: dict[str, Any], counts: dict[str, int], group: pd.DataFrame) -> list[str]:
    """Home Hospital virtual-ward pathway: referral→activation SLA, the
    two-visits-per-full-day waiver cadence, and the escalation protocol
    (every open resolved; response inside the 30-minute floor)."""
    deviations: list[str] = []

    referral = timeline.get("home-refer")
    activation = timeline.get("home-activate")
    if referral is not None and activation is not None:
        minutes = _minutes_between(referral, activation)
        if minutes is not None and minutes > HOME_ACTIVATION_SLA_MIN:
            deviations.append("activation_beyond_sla")

    # Waiver cadence over FULL elapsed ward days (partial admission/discharge
    # days are not judged). Waiver visits preferred when the attr is present;
    # otherwise every completed visit counts toward the floor.
    end = timeline.get("home-discharge") or group["ocel:timestamp"].max()
    full_days = 0
    if activation is not None:
        minutes = _minutes_between(activation, end)
        full_days = int(minutes // (24 * 60)) if minutes is not None else 0
    if full_days >= 1:
        visits = group[group["ocel:activity"] == "home-visit-complete"]
        if "waiver_required" in group.columns:
            flagged = visits["waiver_required"].astype(str).str.lower().isin({"true", "1", "yes"})
            waiver_visits = int(flagged.sum()) if flagged.any() else len(visits)
        else:
            waiver_visits = len(visits)
        if waiver_visits < HOME_WAIVER_VISITS_PER_DAY * full_days:
            deviations.append("visit_cadence_below_floor")

    if counts.get("home-escalation-open", 0) > counts.get("home-escalation-resolve", 0):
        deviations.append("escalation_unresolved")

    if "response_minutes" in group.columns:
        response = pd.to_numeric(
            group[group["ocel:activity"] == "home-escalation-resolve"]["response_minutes"],
            errors="coerce",
        ).dropna()
        if len(response) > 0 and float(response.max()) > HOME_RESPONSE_FLOOR_MIN:
            deviations.append("escalation_response_late")

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
    "home_hospital": {
        "label": "Home Hospital virtual-ward pathway (AHCAH)",
        "version": 1,
        "owner": "clinical:home-hospital",
        "case_type": "Home Episode",
        "trigger": "home-activate",
        "activities": [
            "home-refer",
            "home-activate",
            "home-visit-complete",
            "home-escalation-open",
            "home-escalation-resolve",
            "home-discharge",
        ],
        "evaluate": evaluate_home_hospital,
        "deviation_labels": {
            "activation_beyond_sla": "Referral-to-activation beyond the 24-hour SLA",
            "visit_cadence_below_floor": "In-person visits below the 2-per-day waiver floor",
            "escalation_unresolved": "An escalation was opened but never resolved",
            "escalation_response_late": "Escalation response beyond the 30-minute floor",
        },
    },
}

EvaluatorFn = Callable[[dict[str, Any], dict[str, int], pd.DataFrame], list[str]]
