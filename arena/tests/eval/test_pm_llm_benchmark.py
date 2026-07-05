"""PM-LLM-Benchmark eval harness for the Arena copilot (Part X §X.8.4).

Process-mining LLM quality is now measurable, so the copilot is held to it. This
harness scores the copilot's MODEL-DISCOVERY dimension via conformance-fitness
against a fixture OCEL log and fails if a reference-quality model regresses below
the gate. It is the "prove it didn't regress" discipline the master plan applies to
the canon and the route smoke test, brought to the AI author.

(The narrative-faithfulness and query-correctness dimensions are Laravel-side —
provenance and the allow-list router — and are covered by the PHPUnit copilot tests.)
"""

from __future__ import annotations

import json

import pytest

from app import copilot

# A copilot release whose reference model discovers below this fitness does not ship.
REGRESSION_FLOOR = 0.90


def _ev(eid: str, activity: str, time: str, objects: list[tuple[str, str]]) -> dict:
    return {
        "id": eid,
        "type": activity,
        "time": time,
        "attributes": [],
        "relationships": [{"objectId": oid, "qualifier": qual} for oid, qual in objects],
    }


# A slightly richer benchmark log: a mostly-linear pathway with one branch, so a
# reference model can be perfect and a lazy/hallucinated one is visibly worse.
BENCHMARK = {
    "objectTypes": [{"name": "Case", "attributes": []}],
    "eventTypes": [{"name": n, "attributes": []} for n in ["triage", "assess", "treat", "admit", "discharge"]],
    "objects": [{"id": f"case-{i}", "type": "Case", "attributes": [], "relationships": []} for i in range(1, 5)],
    "events": [
        # case-1..3: triage → assess → treat → discharge ; case-4 branches to admit
        _ev("a1", "triage", "2026-06-01T08:00:00Z", [("case-1", "subject")]),
        _ev("a2", "assess", "2026-06-01T08:30:00Z", [("case-1", "subject")]),
        _ev("a3", "treat", "2026-06-01T09:00:00Z", [("case-1", "subject")]),
        _ev("a4", "discharge", "2026-06-01T10:00:00Z", [("case-1", "subject")]),
        _ev("b1", "triage", "2026-06-02T08:00:00Z", [("case-2", "subject")]),
        _ev("b2", "assess", "2026-06-02T08:30:00Z", [("case-2", "subject")]),
        _ev("b3", "treat", "2026-06-02T09:00:00Z", [("case-2", "subject")]),
        _ev("b4", "discharge", "2026-06-02T10:00:00Z", [("case-2", "subject")]),
        _ev("c1", "triage", "2026-06-03T08:00:00Z", [("case-3", "subject")]),
        _ev("c2", "assess", "2026-06-03T08:30:00Z", [("case-3", "subject")]),
        _ev("c3", "treat", "2026-06-03T09:00:00Z", [("case-3", "subject")]),
        _ev("c4", "discharge", "2026-06-03T10:00:00Z", [("case-3", "subject")]),
        _ev("d1", "triage", "2026-06-04T08:00:00Z", [("case-4", "subject")]),
        _ev("d2", "assess", "2026-06-04T08:30:00Z", [("case-4", "subject")]),
        _ev("d3", "admit", "2026-06-04T09:00:00Z", [("case-4", "subject")]),
    ],
}

# The reference (ground-truth) model — every real directly-follows relation.
REFERENCE_MODEL = [
    {"object_type": "Case", "source": "triage", "target": "assess"},
    {"object_type": "Case", "source": "assess", "target": "treat"},
    {"object_type": "Case", "source": "treat", "target": "discharge"},
    {"object_type": "Case", "source": "assess", "target": "admit"},
]

# A hallucinated model: invented shortcuts, misses the busy backbone.
HALLUCINATED_MODEL = [
    {"object_type": "Case", "source": "triage", "target": "discharge"},
    {"object_type": "Case", "source": "triage", "target": "admit"},
]


@pytest.fixture
def benchmark_path(tmp_path) -> str:
    p = tmp_path / "pm-llm-benchmark.json"
    p.write_text(json.dumps(BENCHMARK), encoding="utf-8")
    return str(p)


def test_reference_model_clears_regression_floor(benchmark_path: str) -> None:
    """A copilot that discovers the reference model must score at/above the floor."""
    r = copilot.model_fitness(benchmark_path, REFERENCE_MODEL, fitness_floor=REGRESSION_FLOOR)
    assert r["fitness"] >= REGRESSION_FLOOR, f"model-discovery fitness regressed: {r['fitness']} < {REGRESSION_FLOOR}"
    assert r["published"] is True
    assert r["precision"] == 1.0


def test_hallucinated_model_is_caught(benchmark_path: str) -> None:
    """The gate must catch a hallucinated model — the benchmark's negative case."""
    r = copilot.model_fitness(benchmark_path, HALLUCINATED_MODEL, fitness_floor=REGRESSION_FLOOR)
    assert r["published"] is False
    assert r["fitness"] < 0.5
    assert len(r["invented_edges"]) >= 1


def test_precision_penalizes_padding(benchmark_path: str) -> None:
    """Padding the reference model with invented edges must lower precision."""
    padded = REFERENCE_MODEL + [{"object_type": "Case", "source": "discharge", "target": "triage"}]
    r = copilot.model_fitness(benchmark_path, padded, fitness_floor=REGRESSION_FLOOR)
    assert r["fitness"] >= REGRESSION_FLOOR   # recall still high (all real edges present)
    assert r["precision"] < 1.0               # but precision drops for the invented arc
