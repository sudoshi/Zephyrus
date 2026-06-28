"""Subsystem A — Eddy chat (provider-neutral). PHASE 0 STUB.

The real capability-driven router (Ollama local-first vs Claude frontier),
the PHI sanitizer, and cloud-usage writeback land in Phase 1. For now this
echoes a deterministic placeholder and reflects the provider_policy it received
so the Laravel proxy + dock can be wired and tested against a live endpoint.
"""

from __future__ import annotations

from fastapi import APIRouter
from pydantic import BaseModel, Field

router = APIRouter(prefix="/eddy", tags=["eddy-chat"])


class ChatRequest(BaseModel):
    message: str
    surface: str = "chat"
    page_context: str | None = None
    page_component: str | None = None
    page_data: dict = Field(default_factory=dict)
    history: list[dict] = Field(default_factory=list)
    user_profile: dict = Field(default_factory=dict)
    user_id: int | None = None
    conversation_id: str | None = None
    provider_policy: dict | None = None


class ChatResponse(BaseModel):
    reply: str
    provider: str
    model: str
    surface: str
    stub: bool = True


@router.post("/chat", response_model=ChatResponse)
async def chat(req: ChatRequest) -> ChatResponse:
    policy = req.provider_policy or {}
    provider = policy.get("provider_type", "ollama")
    model = policy.get("model", "")
    return ChatResponse(
        reply=(
            "Eddy is scaffolded but not yet wired to a model (Phase 1). "
            f"I received your message on the '{req.surface}' surface and would route it to "
            f"{provider or 'local'} ({model or 'default'})."
        ),
        provider=provider or "ollama",
        model=model,
        surface=req.surface,
    )
