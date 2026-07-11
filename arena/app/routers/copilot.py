"""Governed AI copilot surface (Part X §X.8) — the conformance-fitness trust gate.

POST /copilot/model-fitness conformance-checks a copilot-proposed object-centric DFG
against the OCEL log and returns its fitness/precision so the orchestrator can WITHHOLD
any map below the floor. This is the only sidecar copilot endpoint: the LLM generation
itself lives Laravel-side (behind ARENA_AI_ENABLED); the sidecar owns the pm4py
adjudication because that is where the log and the miner are.

Gated by `arena_ai_enabled` INDEPENDENTLY of the deterministic panes — a 404 when off,
so the copilot is invisible until explicitly switched on.
"""

from __future__ import annotations

from fastapi import APIRouter, HTTPException

from app import copilot
from app.config import get_settings
from app.models import ModelFitnessRequest, ModelFitnessResponse
from app.ocel_loader import PM4PY_AVAILABLE, OcelUnavailable, resolve_ocel_path

router = APIRouter(tags=["arena-copilot"])


@router.post("/copilot/model-fitness", response_model=ModelFitnessResponse)
async def model_fitness(req: ModelFitnessRequest) -> ModelFitnessResponse:
    if not get_settings().arena_ai_enabled:
        # Invisible when the AI author is off — the deterministic Arena is unaffected.
        raise HTTPException(status_code=404, detail="Arena AI copilot is disabled")
    if not PM4PY_AVAILABLE:
        raise HTTPException(status_code=503, detail="OCPM engine (pm4py) unavailable in this sidecar build")
    try:
        edges = [edge.model_dump() for edge in req.proposed_edges]
        with resolve_ocel_path(req.ocel_path, req.ocel) as path:
            result = copilot.model_fitness(path, edges, fitness_floor=req.fitness_floor)
            cross = copilot.structural_cross_check(
                path, edges, structural_floor=get_settings().arena_ai_structural_floor
            )
        result["structural_fitness_by_type"] = cross["structural_fitness_by_type"]
        result["structural_warnings"] = cross["structural_warnings"]
        return ModelFitnessResponse(**result)
    except OcelUnavailable as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
