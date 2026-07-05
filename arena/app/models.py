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
