"""Pydantic v2 request/response models — the canon-shaped contract the Laravel
orchestrator and React Study UI consume. Deliberately flat (nodes/edges/counts)
so the frontend renders SVG directly via STATUS_VAR without post-processing.
"""

from __future__ import annotations

from typing import Any

from pydantic import BaseModel, Field, model_validator


class OcelSource(BaseModel):
    """Where to read the OCEL 2.0 log from. Exactly one of the fields is used;
    `ocel_path` (a shared-volume export) is preferred, `ocel` inlines the doc for
    a fully stateless call, and omitting both falls back to the configured
    default export path."""

    ocel_path: str | None = Field(default=None, description="path to an OCEL 2.0 JSON file readable by the sidecar")
    ocel: dict[str, Any] | None = Field(default=None, description="an inline OCEL 2.0 JSON document")


class DiscoverRequest(OcelSource):
    object_types: list[str] | None = Field(default=None, description="restrict discovery to these object types (default: all)")
    activity_min_freq: int | None = Field(default=None, ge=0, description="drop activities below this occurrence count")

    @model_validator(mode="after")
    def _bound_object_types(self) -> "DiscoverRequest":
        if self.object_types is not None and len(self.object_types) == 0:
            self.object_types = None
        return self


class Node(BaseModel):
    """An activity node in the object-centric DFG."""

    id: str
    activity: str
    frequency: int
    object_types: list[str]


class Edge(BaseModel):
    """A directly-follows arc for a single object type (OC-DFG arcs are per-type;
    the union is the object-centric map). Performance overlays (sync/lag/pool
    times) are OPerA — Part X X2, not X1."""

    source: str
    target: str
    object_type: str
    frequency: int


class DiscoverResponse(BaseModel):
    object_types: list[str]
    nodes: list[Node]
    edges: list[Edge]
    stats: dict[str, int]


class SummaryResponse(BaseModel):
    events: int
    objects: int
    object_types: dict[str, int]
    activities: dict[str, int]


class PerformanceRequest(OcelSource):
    object_types: list[str] | None = Field(default=None, description="restrict to these object types (default: all)")
    top: int = Field(default=25, ge=1, le=200, description="cap the ranked rows returned")


class HandoffDuration(BaseModel):
    object_type: str
    source: str
    target: str
    count: int
    median_sec: float
    p90_sec: float
    mean_sec: float


class SynchronizationWait(BaseModel):
    activity: str
    object_type: str
    count: int
    median_wait_sec: float
    p90_wait_sec: float


class PerformanceResponse(BaseModel):
    handoffs: list[HandoffDuration]
    synchronization: list[SynchronizationWait]


class ConformanceRequest(OcelSource):
    pathway: str | None = Field(default=None, description="restrict to one pathway key (default: all)")


class DeviationCount(BaseModel):
    code: str
    label: str
    count: int


class SampleDeviantCase(BaseModel):
    case_id: str
    deviations: list[str]


class PathwayConformance(BaseModel):
    pathway: str
    label: str
    version: int
    owner: str
    case_type: str
    cases: int
    conformant: int
    deviant: int
    conformance_rate: float | None
    deviations: list[DeviationCount]
    sample_deviant_cases: list[SampleDeviantCase]


# --- X4 governed AI copilot: the conformance-fitness trust gate (§X.8.2) ---


class ProposedEdge(BaseModel):
    """One arc of a copilot-proposed object-centric DFG (same shape as Edge, minus
    the frequency the AI cannot know)."""

    object_type: str
    source: str
    target: str


class ModelFitnessRequest(OcelSource):
    proposed_edges: list[ProposedEdge] = Field(default_factory=list, description="the copilot-proposed OC-DFG arcs to conformance-check")
    fitness_floor: float | None = Field(default=None, ge=0.0, le=1.0, description="override the publish threshold (default: sidecar arena_ai_fitness_floor)")


class FitnessEvidenceEdge(BaseModel):
    object_type: str
    source: str
    target: str
    frequency: int | None = None


class ModelFitnessResponse(BaseModel):
    fitness: float                       # frequency-weighted recall of real behavior [0,1]
    precision: float                     # share of the proposed model grounded in the log [0,1]
    published: bool                      # fitness >= floor AND the model is non-empty over real behavior
    fitness_floor: float
    object_types: list[str]
    covered_freq: int
    total_real_freq: int
    proposed_edges: int
    grounded_edges: int
    invented_edges: list[FitnessEvidenceEdge]  # proposed arcs the log never exhibits
    missing_edges: list[FitnessEvidenceEdge]    # busy real arcs the model omits (top by frequency)
    reason: str | None = None            # why withheld: empty_model | no_reference_behavior | below_fitness_floor
