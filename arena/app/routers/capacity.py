"""Capacity surface (Part X §XO.3): POST /capacity turns a QEL payload into per-unit
occupancy curves. Pandas-only — no mining engine required. PHI-free."""

from __future__ import annotations

from fastapi import APIRouter

from app import capacity
from app.models import CapacityRequest, CapacityResponse

router = APIRouter(tags=["arena"])


@router.post("/capacity", response_model=CapacityResponse)
async def compute_capacity(req: CapacityRequest) -> CapacityResponse:
    result = capacity.series(req.quantities, item_type=req.item_type, threshold=req.threshold)
    return CapacityResponse(**result)
