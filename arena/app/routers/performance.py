"""Object-centric performance surface (Part X §X.6): POST /performance returns
the slowest object-lifecycle hand-offs and the synchronization waits at object
intersections. Read-only, PHI-free (timings and de-identified object ids only).
"""

from __future__ import annotations

from fastapi import APIRouter, HTTPException

from app import performance
from app.config import get_settings
from app.filters import parse_filters
from app.models import PerformanceRequest, PerformanceResponse
from app.ocel_loader import PM4PY_AVAILABLE, OcelUnavailable, resolve_ocel_path

router = APIRouter(tags=["arena"])


@router.post("/performance", response_model=PerformanceResponse)
async def analyze_performance(req: PerformanceRequest) -> PerformanceResponse:
    if not PM4PY_AVAILABLE:
        raise HTTPException(status_code=503, detail="OCPM engine (pm4py) unavailable in this sidecar build")

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
            result = performance.analyze(path, object_types=object_types, top=req.top, filters=filters)
        return PerformanceResponse(**result)
    except OcelUnavailable as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
