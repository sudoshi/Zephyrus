"""Per-case conformance verdicts (FLOW-4D plan §8 Phase A2, finding CF-2):
`per_case=True` surfaces the verdict the engine already computes for every
case instead of discarding all but 8 samples. Aggregates must stay identical
— the flag only changes what is RETURNED, never what is counted.
"""

from __future__ import annotations

import json

import pytest

from app import conformance
from tests.test_conformance import FIXTURE


@pytest.fixture
def ocel_path(tmp_path) -> str:
    p = tmp_path / "per-case-fixture.json"
    p.write_text(json.dumps(FIXTURE), encoding="utf-8")
    return str(p)


def _sepsis(results: list[dict]) -> dict:
    return next(r for r in results if r["pathway"] == "sepsis")


def test_per_case_returns_a_verdict_for_every_evaluated_case(ocel_path: str) -> None:
    sepsis = _sepsis(conformance.check(ocel_path, pathway_key="sepsis", per_case=True))

    verdicts = {c["case_id"]: c for c in sepsis["case_results"]}
    assert set(verdicts) == {"enc-1", "enc-2"}

    assert verdicts["enc-1"]["conformant"] is True
    assert verdicts["enc-1"]["deviations"] == []

    assert verdicts["enc-2"]["conformant"] is False
    assert set(verdicts["enc-2"]["deviations"]) == {"antibiotic_late", "no_repeat_lactate"}


def test_per_case_timeline_carries_first_occurrences_as_iso(ocel_path: str) -> None:
    sepsis = _sepsis(conformance.check(ocel_path, pathway_key="sepsis", per_case=True))
    timeline = next(c for c in sepsis["case_results"] if c["case_id"] == "enc-1")["activity_timeline"]

    assert timeline["sepsis_recognition"].startswith("2026-06-01T08:00:00")
    assert timeline["antibiotic_administration"].startswith("2026-06-01T09:00:00")
    assert set(timeline) == {
        "sepsis_recognition",
        "lactate_order",
        "blood_culture_order",
        "antibiotic_administration",
        "repeat_lactate_result",
    }


def test_case_ids_narrows_verdicts_but_never_the_aggregates(ocel_path: str) -> None:
    sepsis = _sepsis(conformance.check(ocel_path, pathway_key="sepsis", per_case=True, case_ids=["enc-2"]))

    assert [c["case_id"] for c in sepsis["case_results"]] == ["enc-2"]
    # Aggregates still cover the full log — rates never silently change meaning.
    assert sepsis["cases"] == 2
    assert sepsis["conformant"] == 1
    assert sepsis["deviant"] == 1


def test_aggregates_are_byte_identical_with_and_without_per_case(ocel_path: str) -> None:
    plain = _sepsis(conformance.check(ocel_path, pathway_key="sepsis"))
    per_case = _sepsis(conformance.check(ocel_path, pathway_key="sepsis", per_case=True))

    assert plain["case_results"] == []
    for field in ("cases", "conformant", "deviant", "conformance_rate", "deviations", "sample_deviant_cases"):
        assert plain[field] == per_case[field]
