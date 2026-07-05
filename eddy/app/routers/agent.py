"""Subsystem B — Eddy agent (local autonomous tool-loop).

Phase 0 was a stub. This wires the real LOCAL agent turn: a bounded Anthropic-
Messages tool-calling loop (`app.agent.local_loop`) over the Anthropic-compatible
proxy (LiteLLM -> Ollama), dispatching tool calls to the Laravel scoped-token
callback that DRAFTS `ops.actions` (Eddy proposes, a human approves). Gated by
`EDDY_AGENT_LOCAL_ACTIONS_ENABLED`; when off, the turn stays an inert stub. The
frontier path (Claude Agent SDK) + Reverb streaming + a queue land in later phases.
"""

from __future__ import annotations

import httpx
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from app.agent.local_loop import LocalAgentLoop, make_laravel_dispatcher
from app.config import get_settings

router = APIRouter(prefix="/agent", tags=["eddy-agent"])

# In-memory registry placeholder (a durable registry / `ops.agent_runs` lands with the queue).
_SESSIONS: dict[str, dict] = {}


class CreateSessionRequest(BaseModel):
    profile: str = "eddy"
    agent_session_id: str
    subject_type: str = "global"
    subject_id: int | None = None
    channel: str | None = None
    ingest_path: str | None = None
    scoped_token: str | None = None
    provider: str = "local"
    context: dict = Field(default_factory=dict)


class CreateSessionResponse(BaseModel):
    agent_session_id: str
    channel: str | None
    provider: str
    actions_enabled: bool


class RunTurnRequest(BaseModel):
    prompt: str
    context: str | None = None


@router.post("/sessions", response_model=CreateSessionResponse)
async def create_session(req: CreateSessionRequest) -> CreateSessionResponse:
    settings = get_settings()
    _SESSIONS[req.agent_session_id] = req.model_dump()
    return CreateSessionResponse(
        agent_session_id=req.agent_session_id,
        channel=req.channel,
        provider=req.provider,
        # Reflects the gate: tools only fire when local actions are enabled.
        actions_enabled=settings.eddy_agent_local_actions_enabled,
    )


@router.post("/sessions/{session_id}/turn")
async def run_turn(session_id: str, body: RunTurnRequest) -> dict:
    settings = get_settings()

    # Gate closed → inert stub (unchanged Phase-0 behavior; no tools fire).
    if not settings.eddy_agent_local_actions_enabled:
        return {"accepted": True, "session_id": session_id, "stub": True, "reason": "actions_disabled"}

    session = _SESSIONS.get(session_id)
    if session is None:
        raise HTTPException(status_code=404, detail="unknown agent session")

    scoped_token = session.get("scoped_token")
    if not scoped_token:
        raise HTTPException(status_code=422, detail="session has no scoped token; cannot draft proposals")

    async with httpx.AsyncClient() as client:
        dispatcher = make_laravel_dispatcher(
            base_url=settings.agency_api_base_url,
            scoped_token=scoped_token,
            client=client,
            subject={"subject_type": session.get("subject_type"), "subject_id": session.get("subject_id")},
        )
        loop = LocalAgentLoop(
            proxy_base_url=settings.eddy_agent_local_base_url,
            model=settings.eddy_agent_local_model,
            client=client,
            dispatcher=dispatcher,
            max_turns=min(settings.eddy_agent_max_turns, 8),
        )
        result = await loop.run(body.prompt, body.context)

    return {"session_id": session_id, "stub": False, **result.as_dict()}


@router.post("/sessions/{session_id}/approve")
async def approve(session_id: str, body: dict) -> dict:
    # Eddy never self-approves — approval is a human act through the Laravel ops ledger.
    return {"ok": True, "session_id": session_id, "note": "approval is human-only via the ops ledger"}
