"""Subsystem A — Eddy chat (provider-neutral). Phase 1: real routing.

Routes a turn to the local MedGemma (default) or the frontier Claude per the
provider_policy Laravel sends, enforces the PHI gate, and returns the reply with
usage + route metadata. Eddy stays stateless — Laravel persists messages and
writes the cloud-usage ledger from this response.
"""

from __future__ import annotations

import json

from fastapi import APIRouter
from fastapi.responses import StreamingResponse
from pydantic import BaseModel, Field

from app.config import get_settings
from app.routing.chat_adapters import (
    AnthropicMessagesAdapter,
    ChatAdapterError,
    ChatAdapterRequest,
    OllamaChatAdapter,
)
from app.routing.profiles import ProviderProfile
from app.routing.router import ChatRouter, build_system_prompt, _normalize_history
import httpx

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


@router.post("/chat")
async def chat(req: ChatRequest) -> dict:
    result = await ChatRouter(get_settings()).run(
        message=req.message,
        surface=req.surface,
        page_context=req.page_context or req.page_component,
        page_data=req.page_data,
        history=req.history,
        user_profile=req.user_profile,
        provider_policy=req.provider_policy,
    )
    return result.to_dict()


@router.post("/chat/stream")
async def chat_stream(req: ChatRequest) -> StreamingResponse:
    """SSE token stream. Phase 1 streams the LOCAL path (and the frontier path
    when the policy + gates select it); fallback/PHI logic mirrors /chat but a
    stream commits to one provider up front for simplicity."""
    settings = get_settings()
    policy = req.provider_policy or {}
    provider_type = (policy.get("provider_type") or "ollama").lower()
    wants_cloud = provider_type not in {"ollama", ""} and settings.eddy_allow_cloud and bool(settings.anthropic_api_key)

    system_prompt = build_system_prompt(req.surface, req.page_context or req.page_component, req.user_profile)
    adapter_req = ChatAdapterRequest(system_prompt=system_prompt, message=req.message, history=_normalize_history(req.history))

    async def event_stream():
        try:
            if wants_cloud:
                profile = ProviderProfile(provider="anthropic", transport="anthropic_messages", model=policy.get("model") or settings.eddy_cloud_chat_model, entitlement="org_api_key")
                async for ev in AnthropicMessagesAdapter(profile=profile, api_key=settings.anthropic_api_key).stream(adapter_req):
                    yield _sse(ev)
            else:
                profile = ProviderProfile(provider="ollama", transport="ollama_chat", model=settings.eddy_ollama_model, base_url=settings.ollama_base_url, entitlement="local")
                async with httpx.AsyncClient() as client:
                    adapter = OllamaChatAdapter(profile=profile, client=client, default_num_predict=settings.eddy_ollama_num_predict, keep_alive_seconds=settings.eddy_ollama_keep_alive, timeout_seconds=180)
                    async for ev in adapter.stream(adapter_req):
                        yield _sse(ev)
        except ChatAdapterError as exc:
            yield f"data: {json.dumps({'error': str(exc), 'error_class': exc.error_class})}\n\n"
        yield "data: [DONE]\n\n"

    return StreamingResponse(
        event_stream(),
        media_type="text/event-stream",
        headers={"Cache-Control": "no-cache", "Connection": "keep-alive", "X-Accel-Buffering": "no"},
    )


def _sse(ev) -> str:
    if ev.kind == "token":
        return f"data: {json.dumps({'token': ev.token})}\n\n"
    if ev.kind == "error":
        return f"data: {json.dumps({'error': ev.payload.get('message'), 'error_class': ev.payload.get('error_class')})}\n\n"
    return f"data: {json.dumps({'complete': True, 'model': ev.payload.get('model')})}\n\n"
