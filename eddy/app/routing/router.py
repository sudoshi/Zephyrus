"""ChatRouter — decide local vs frontier, enforce the PHI gate, execute, fall back.

Laravel resolves the surface policy and sends a `provider_policy` hint. Eddy:
  1. honors the policy's provider/mode (rule-routes when auto_by_complexity),
  2. force-locals on: cloud disabled, missing key, PHI detected, or cloud error,
  3. returns usage + route metadata for Laravel to persist (Eddy stays stateless).

The local fallback is self-contained from Eddy's own env (OLLAMA_BASE_URL).
"""

from __future__ import annotations

import json
import re
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
    proposed_action: dict | None = None  # advice-not-autopilot: a draft for human approval

    def to_dict(self) -> dict:
        return asdict(self)


_PROPOSE_RE = re.compile(r"<propose_action>\s*(\{.*?\})\s*</propose_action>", re.DOTALL)


def extract_proposed_action(reply: str) -> tuple[str, dict | None]:
    """Pull a <propose_action>{...}</propose_action> block out of the model reply.

    Returns (clean_reply, proposed_action|None). Malformed JSON or a missing
    action_type → no proposal (the raw reply is preserved). Eddy never executes;
    Laravel re-validates the action_type against its catalog at propose time.
    """
    match = _PROPOSE_RE.search(reply or "")
    if not match:
        return reply, None
    clean = _PROPOSE_RE.sub("", reply).strip()
    try:
        action = json.loads(match.group(1))
    except (ValueError, TypeError):
        return reply, None
    if not isinstance(action, dict) or not action.get("action_type"):
        return clean, None
    return clean, action


_PERSONA = (
    "You are Eddy, the operations copilot for Zephyrus, a hospital operations command center "
    "(ED, RTDC bed flow, perioperative, transport, EVS, staffing, process improvement). "
    "You are a NON-DEVICE operational decision-support tool: you produce operational suggestions, "
    "never clinical alerts — clinical alerting stays in the EHR. Follow 'advice, not autopilot': "
    "every prescriptive answer is an explainable suggestion with a runner-up and an override; you "
    "never take an action without explicit human approval. Be concise, defensible, and composed. "
    "Use tabular, glanceable phrasing for operational metrics."
)


def build_system_prompt(
    surface: str,
    page_context: str | None,
    user_profile: dict | None,
    live_context: dict | None = None,
    knowledge: list[dict] | None = None,
    allowed_actions: list[str] | None = None,
) -> str:
    parts = [_PERSONA]
    if allowed_actions:
        parts.append(_format_action_protocol(allowed_actions))
    if surface and surface != "chat":
        parts.append(f"The operator is currently on the '{surface}' surface.")
    if page_context:
        parts.append(f"Current view: {page_context}.")
    roles = (user_profile or {}).get("roles") or []
    if roles:
        parts.append(f"The operator's role(s): {', '.join(map(str, roles))}.")
    preferences = (user_profile or {}).get("preferences") or {}
    if preferences:
        block = _format_preferences(preferences)
        if block:
            parts.append(block)
    if live_context:
        parts.append(_format_live_context(live_context))
    if knowledge:
        parts.append(_format_knowledge(knowledge))
    return "\n\n".join(parts)


def _format_preferences(prefs: dict) -> str:
    """Learned-preference block (Phase 6) — nudges runner-up ordering, never overrides
    clinical/operational judgment or the safety gates."""
    lines: list[str] = []
    preferred = prefs.get("preferred_actions") or []
    discouraged = prefs.get("discouraged_actions") or []
    if preferred:
        lines.append(
            "Historically APPROVED by this operator — weight higher in your ordering: "
            + ", ".join(map(str, preferred))
            + "."
        )
    if discouraged:
        lines.append(
            "Historically REJECTED by this operator — propose only with a clear, specific justification: "
            + ", ".join(map(str, discouraged))
            + "."
        )
    return "LEARNED PREFERENCES:\n" + "\n".join(lines) if lines else ""


def _format_live_context(ctx: dict) -> str:
    """The process-awareness block — current operational truth, queried just now."""
    lines = ["LIVE OPERATIONS DATA (queried just now — treat as the current operational truth):"]

    freshness = ctx.get("source_freshness") or {}
    lag = freshness.get("census_lag_minutes")
    if freshness.get("status") == "warning" or lag is None or (isinstance(lag, int) and lag > 60):
        stale = "has no timestamped census" if lag is None else f"census snapshot is {lag} min old"
        lines.append(f"- DATA TRUST CAUTION: {stale}. Caveat any prescriptive output and recommend confirming feed freshness before acting.")

    cap = ctx.get("capacity") or {}
    if cap:
        lines.append(
            "- Capacity: "
            f"{cap.get('available_beds')} available / {cap.get('occupied_beds')} occupied / {cap.get('blocked_beds')} blocked beds; "
            f"net beds {cap.get('net_beds')} after {cap.get('pending_admits')} pending admits; "
            f"{cap.get('ed_boarders')} ED boarders; {cap.get('transport_at_risk')} transports at SLA risk; "
            f"risk score {cap.get('risk_score')}/100."
        )

    for finding in ctx.get("findings") or []:
        lines.append(f"- [{finding.get('status')}] {finding.get('detail')}")

    if ctx.get("headline"):
        lines.append(f"- House-wide: {ctx['headline']}")
    for sit in ctx.get("situation") or []:
        lines.append(f"- {sit.get('domain')} [{sit.get('status')}]: {sit.get('detail')}")

    gov = ctx.get("governance") or {}
    if gov:
        lines.append(
            f"- Governance: {gov.get('pending_approvals', 0)} pending approvals, "
            f"{gov.get('draft_actions', 0)} draft actions, {gov.get('open_recommendations', 0)} open recommendations."
        )

    lines.append("Ground every operational claim in this data. If it does not answer the question, say so rather than guessing.")
    return "\n".join(lines)


def _format_knowledge(knowledge: list[dict]) -> str:
    lines = ["KNOWLEDGE BASE (institutional doctrine — cite when relevant):"]
    for item in knowledge[:4]:
        title = item.get("title", "")
        source = item.get("source")
        suffix = f" (source: {source})" if source else ""
        lines.append(f"- {title}{suffix}: {item.get('body', '')}")
    return "\n".join(lines)


def _format_action_protocol(allowed_actions: list[str]) -> str:
    allowed = ", ".join(allowed_actions)
    return (
        "ACTION PROPOSALS — REQUIRED OUTPUT FORMAT.\n"
        "You cannot perform actions. When the operator asks you to DO something operational "
        "(flag a barrier, dispatch transport, open a surge plan, etc.), write ONE short sentence "
        "of explanation, then append EXACTLY ONE block on its own line, copying this exact JSON shape:\n"
        '<propose_action>{"action_type": "flag_barrier", "title": "Imaging delay on 3 West", '
        '"params": {"unit": "3W", "barrier": "imaging"}, "rationale": "Two discharges are held on pending CT reads.", '
        '"runner_up": "Escalate to the radiology charge."}</propose_action>\n'
        f"Set action_type to one of EXACTLY these values: {allowed}. Fill params/rationale/runner_up "
        "from the operator's request and the live data. Output the block verbatim in that JSON shape — "
        "do not describe it, do not use code fences. A human reviews and approves; nothing happens "
        "until they do. If the operator is NOT asking you to take an action, omit the block entirely."
    )


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
        live_context: dict | None = None,
        knowledge: list[dict] | None = None,
        allowed_actions: list[str] | None = None,
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

        system_prompt = build_system_prompt(surface, page_context, user_profile, live_context, knowledge, allowed_actions)
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
        clean_reply, proposed_action = extract_proposed_action(resp.reply)
        return ChatResult(
            reply=clean_reply,
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
            proposed_action=proposed_action,
        )
