"""The X1 discovery surface: /ocel/summary and /discover. Both read a
de-identified OCEL 2.0 log (inline doc, explicit path, or the configured export)
and return canon-shaped JSON. Stateless and read-only — no PHI, no prod.* access.
Errors degrade to a clean 503/422, never a 500 stack trace.
"""

from __future__ import annotations

from fastapi import APIRouter, HTTPException

from app import discovery
from app.config import get_settings
from app.filters import parse_filters
from app.models import DiscoverRequest, DiscoverResponse, OcelSource, SummaryResponse
from app.ocel_loader import PM4PY_AVAILABLE, OcelUnavailable, resolve_ocel_path

router = APIRouter(tags=["arena"])


def _require_engine() -> None:
    if not PM4PY_AVAILABLE:
        raise HTTPException(status_code=503, detail="OCPM engine (pm4py) unavailable in this sidecar build")


@router.post("/ocel/summary", response_model=SummaryResponse)
async def ocel_summary(src: OcelSource) -> SummaryResponse:
    _require_engine()
    try:
        with resolve_ocel_path(src.ocel_path, src.ocel) as path:
            return SummaryResponse(**discovery.summarize(path))
    except OcelUnavailable as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc


@router.post("/discover", response_model=DiscoverResponse)
async def discover_map(req: DiscoverRequest) -> DiscoverResponse:
    _require_engine()
    settings = get_settings()
    object_types = req.object_types
    if object_types is not None:
        object_types = object_types[: settings.arena_max_object_types]
    try:
        filters = parse_filters(req.filters)
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
    try:
        with resolve_ocel_path(req.ocel_path, req.ocel) as path:
            result = discovery.discover(
                path,
                object_types=object_types,
                activity_min_freq=req.activity_min_freq,
                filters=filters,
            )
        return DiscoverResponse(**result)
    except OcelUnavailable as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
