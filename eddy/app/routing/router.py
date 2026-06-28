"""ChatRouter — decide local vs frontier, enforce the PHI gate, execute, fall back.

Laravel resolves the surface policy and sends a `provider_policy` hint. Eddy:
  1. honors the policy's provider/mode (rule-routes when auto_by_complexity),
  2. force-locals on: cloud disabled, missing key, PHI detected, or cloud error,
  3. returns usage + route metadata for Laravel to persist (Eddy stays stateless).

The local fallback is self-contained from Eddy's own env (OLLAMA_BASE_URL).
"""

from __future__ import annotations

import json
from dataclasses import asdict, dataclass

import httpx

from app.config import Settings
from app.routing.chat_adapters import (
    AnthropicMessagesAdapter,
    ChatAdapterError,
    ChatAdapterRequest,
    OllamaChatAdapter,
)
from app.routing.profiles import ProviderProfile
from app.routing.rule_router import RuleRouter
from app.sanitizer import scan


@dataclass
class ChatResult:
    reply: str
    provider: str
    transport: str
    model: str
    surface: str
    tokens_in: int = 0
    tokens_out: int = 0
    cost_usd: float = 0.0
    latency_ms: float = 0.0
    request_hash: str = ""
    route_reason: str = ""
    fallback_reason: str | None = None
    sanitizer_redaction_count: int = 0
    status: str = "success"
    error_class: str | None = None

    def to_dict(self) -> dict:
        return asdict(self)


_PERSONA = (
    "You are Eddy, the operations copilot for Zephyrus, a hospital operations command center "
    "(ED, RTDC bed flow, perioperative, transport, EVS, staffing, process improvement). "
    "You are a NON-DEVICE operational decision-support tool: you produce operational suggestions, "
    "never clinical alerts — clinical alerting stays in the EHR. Follow 'advice, not autopilot': "
    "every prescriptive answer is an explainable suggestion with a runner-up and an override; you "
    "never take an action without explicit human approval. Be concise, defensible, and composed. "
    "Use tabular, glanceable phrasing for operational metrics."
)


def build_system_prompt(surface: str, page_context: str | None, user_profile: dict | None) -> str:
    parts = [_PERSONA]
    if surface and surface != "chat":
        parts.append(f"The operator is currently on the '{surface}' surface.")
    if page_context:
        parts.append(f"Current view: {page_context}.")
    roles = (user_profile or {}).get("roles") or []
    if roles:
        parts.append(f"The operator's role(s): {', '.join(map(str, roles))}.")
    return " ".join(parts)


def _normalize_history(history: list[dict] | None) -> list[dict[str, str]]:
    out: list[dict[str, str]] = []
    for turn in (history or [])[-10:]:
        role = turn.get("role")
        content = turn.get("content")
        if role in {"user", "assistant"} and content:
            out.append({"role": role, "content": str(content)})
    return out


class ChatRouter:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings

    async def run(
        self,
        *,
        message: str,
        surface: str = "chat",
        page_context: str | None = None,
        page_data: dict | None = None,
        history: list[dict] | None = None,
        user_profile: dict | None = None,
        provider_policy: dict | None = None,
    ) -> ChatResult:
        s = self.settings
        policy = provider_policy or {}
        mode = policy.get("mode") or s.eddy_default_provider_mode
        provider_type = (policy.get("provider_type") or "ollama").lower()
        cloud_capable = provider_type not in {"ollama", ""}

        wants_cloud = cloud_capable
        route_reason = mode
        fallback_reason: str | None = None

        if mode == "auto_by_complexity" and cloud_capable:
            decision = RuleRouter().route(message)
            wants_cloud = decision.model == "claude"
            route_reason = decision.reason

        if wants_cloud and not s.eddy_allow_cloud:
            wants_cloud, fallback_reason = False, "cloud_disabled"

        redactions = 0
        if wants_cloud and s.eddy_phi_detection_enabled:
            redactions = scan(message, json.dumps(page_data or {}), *[str(t.get("content", "")) for t in (history or [])])
            if redactions > 0 and s.eddy_phi_block_on_detection:
                wants_cloud, fallback_reason = False, "phi_detected"

        if wants_cloud and not s.anthropic_api_key:
            wants_cloud, fallback_reason = False, "cloud_key_missing"

        system_prompt = build_system_prompt(surface, page_context, user_profile)
        adapter_req = ChatAdapterRequest(system_prompt=system_prompt, message=message, history=_normalize_history(history))

        # ── Frontier path ──────────────────────────────────────────────
        if wants_cloud:
            cloud_profile = ProviderProfile(
                provider="anthropic",
                transport="anthropic_messages",
                model=policy.get("model") or s.eddy_cloud_chat_model,
                entitlement=policy.get("entitlement", "org_api_key"),
                profile_id=policy.get("profile_id", ""),
            )
            try:
                resp = await AnthropicMessagesAdapter(profile=cloud_profile, api_key=s.anthropic_api_key).chat(adapter_req)
                return self._result(resp, surface, route_reason, None, redactions)
            except ChatAdapterError as exc:
                fallback_reason = exc.error_class  # fall through to local

        # ── Local path (also the fallback) ─────────────────────────────
        local_profile = ProviderProfile(
            provider="ollama",
            transport="ollama_chat",
            model=s.eddy_ollama_model,
            base_url=s.ollama_base_url,
            entitlement="local",
            profile_id="local-medgemma",
        )
        async with httpx.AsyncClient() as client:
            try:
                resp = await OllamaChatAdapter(
                    profile=local_profile,
                    client=client,
                    default_num_predict=s.eddy_ollama_num_predict,
                    keep_alive_seconds=s.eddy_ollama_keep_alive,
                    timeout_seconds=180,
                ).chat(adapter_req)
            except ChatAdapterError as exc:
                return ChatResult(
                    reply="Eddy can't reach a model right now. Please check the local model service and try again.",
                    provider="ollama",
                    transport="ollama_chat",
                    model=s.eddy_ollama_model,
                    surface=surface,
                    route_reason=route_reason,
                    fallback_reason=fallback_reason,
                    sanitizer_redaction_count=redactions,
                    status="error",
                    error_class=exc.error_class,
                )
        return self._result(resp, surface, route_reason, fallback_reason, redactions)

    def _result(self, resp, surface, route_reason, fallback_reason, redactions) -> ChatResult:
        return ChatResult(
            reply=resp.reply,
            provider=resp.provider,
            transport=resp.transport,
            model=resp.model,
            surface=surface,
            tokens_in=resp.tokens_in,
            tokens_out=resp.tokens_out,
            cost_usd=resp.cost_usd,
            latency_ms=resp.latency_ms,
            request_hash=resp.request_hash,
            route_reason=route_reason,
            fallback_reason=fallback_reason,
            sanitizer_redaction_count=redactions,
            status="success",
        )
