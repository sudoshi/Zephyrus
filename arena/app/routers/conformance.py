"""Conformance surface (Part X §X.7): POST /conformance checks the OCEL log
against the reference care pathways and returns ranked, observed deviations.
Read-only, PHI-free (case ids are the de-identified OCEL object ids).
"""

from __future__ import annotations

from fastapi import APIRouter, HTTPException

from app import conformance
from app.filters import parse_filters
from app.models import ConformanceRequest, PathwayConformance
from app.ocel_loader import PM4PY_AVAILABLE, OcelUnavailable, resolve_ocel_path

router = APIRouter(tags=["arena"])


@router.post("/conformance", response_model=list[PathwayConformance])
async def check_conformance(req: ConformanceRequest) -> list[PathwayConformance]:
    if not PM4PY_AVAILABLE:
        raise HTTPException(status_code=503, detail="OCPM engine (pm4py) unavailable in this sidecar build")
    try:
        filters = parse_filters(req.filters)
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
    try:
        with resolve_ocel_path(req.ocel_path, req.ocel) as path:
            results = conformance.check(path, pathway_key=req.pathway, filters=filters)
        return [PathwayConformance(**result) for result in results]
    except OcelUnavailable as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
