"""Subsystem B — Eddy agent (Claude Agent SDK loop). PHASE 0 STUB.

Registers a session and accepts turn/approve calls so the Laravel scoped-token
handshake can be wired and tested. The real SDK loop, can_use_tool approval-future,
Reverb streaming, and the scoped-token callback into Laravel land in Phase 3/4.
"""

from __future__ import annotations

from fastapi import APIRouter
from pydantic import BaseModel, Field

router = APIRouter(prefix="/agent", tags=["eddy-agent"])

# In-memory registry placeholder (Phase 3 replaces with the real registry).
_SESSIONS: dict[str, dict] = {}


class CreateSessionRequest(BaseModel):
    profile: str = "eddy"
    agent_session_id: str
    subject_type: str = "global"
    subject_id: int | None = None
    channel: str | None = None
    ingest_path: str | None = None
    scoped_token: str | None = None
    provider: str = "anthropic"
    context: dict = Field(default_factory=dict)


class CreateSessionResponse(BaseModel):
    agent_session_id: str
    channel: str | None
    provider: str
    actions_enabled: bool
    stub: bool = True


@router.post("/sessions", response_model=CreateSessionResponse)
async def create_session(req: CreateSessionRequest) -> CreateSessionResponse:
    _SESSIONS[req.agent_session_id] = req.model_dump()
    return CreateSessionResponse(
        agent_session_id=req.agent_session_id,
        channel=req.channel,
        provider=req.provider,
        # Actions remain off in Phase 0 — no tools are registered yet.
        actions_enabled=False,
    )


@router.post("/sessions/{session_id}/turn", status_code=202)
async def run_turn(session_id: str, body: dict) -> dict:
    return {"accepted": True, "session_id": session_id, "stub": True}


@router.post("/sessions/{session_id}/approve")
async def approve(session_id: str, body: dict) -> dict:
    return {"ok": True, "session_id": session_id, "stub": True}
