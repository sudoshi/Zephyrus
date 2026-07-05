"""Eddy subsystem B — LOCAL autonomous agent tool-loop.

Runs a multi-turn tool-calling loop against the Anthropic-compatible local proxy
(LiteLLM -> Ollama, e.g. qwen3:8b), executing each tool call through an injected
`dispatcher`. In production the dispatcher POSTs to the Laravel scoped-token
callback (`/api/eddy/agent/actions/propose`), which DRAFTS an `ops.actions` row —
Eddy proposes only, a human approves. The frontier path stays on the Claude Agent
SDK; this dependency-light loop is the local, PHI-safe, zero-cost path.

The proxy call and the tool dispatch are separated so the loop can be unit-tested
(mock dispatcher, respx-mocked proxy) and integration-tested (live proxy + model).
"""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from typing import Any, Awaitable, Callable

import httpx

from app.agent.tools import AGENT_SYSTEM_PROMPT, ANTHROPIC_TOOLS, TOOL_NAMES

# (action_type, tool_input) -> draft result dict (the Laravel proposal envelope).
Dispatcher = Callable[[str, dict], Awaitable[dict]]

_MAX_TOOL_RESULT_CHARS = 1500


@dataclass
class AgentTurnResult:
    proposals: list[dict] = field(default_factory=list)
    final_text: str = ""
    turns: int = 0
    tool_calls: list[dict] = field(default_factory=list)
    stop_reason: str = "end_turn"

    def as_dict(self) -> dict:
        return {
            "proposals": self.proposals,
            "final_text": self.final_text,
            "turns": self.turns,
            "tool_calls": self.tool_calls,
            "stop_reason": self.stop_reason,
        }


class LocalAgentLoop:
    """A bounded Anthropic-Messages tool-calling loop over the local proxy."""

    def __init__(
        self,
        *,
        proxy_base_url: str,
        model: str,
        client: httpx.AsyncClient,
        dispatcher: Dispatcher,
        max_turns: int = 8,
        max_tokens: int = 900,
        system_prompt: str = AGENT_SYSTEM_PROMPT,
        request_timeout: float = 180.0,
    ) -> None:
        self.url = f"{proxy_base_url.rstrip('/')}/v1/messages"
        self.model = model
        self.client = client
        self.dispatcher = dispatcher
        self.max_turns = max_turns
        self.max_tokens = max_tokens
        self.system_prompt = system_prompt
        self.request_timeout = request_timeout

    async def _messages(self, messages: list[dict]) -> dict:
        body = {
            "model": self.model,
            "max_tokens": self.max_tokens,
            "system": self.system_prompt,
            "tools": ANTHROPIC_TOOLS,
            "messages": messages,
        }
        resp = await self.client.post(
            self.url,
            json=body,
            headers={
                "content-type": "application/json",
                "anthropic-version": "2023-06-01",
                "x-api-key": "sk-local-noauth",  # localhost proxy; no real key
            },
            timeout=self.request_timeout,
        )
        resp.raise_for_status()
        return resp.json()

    async def run(self, prompt: str, context: str | None = None) -> AgentTurnResult:
        first = prompt if not context else f"{context}\n\n---\n\n{prompt}"
        messages: list[dict] = [{"role": "user", "content": first}]
        result = AgentTurnResult()

        for turn in range(1, self.max_turns + 1):
            result.turns = turn
            payload = await self._messages(messages)
            blocks = payload.get("content") or []
            messages.append({"role": "assistant", "content": blocks})

            tool_uses = [b for b in blocks if isinstance(b, dict) and b.get("type") == "tool_use"]
            if not tool_uses:
                result.final_text = "".join(
                    b.get("text", "") for b in blocks if isinstance(b, dict) and b.get("type") == "text"
                ).strip()
                result.stop_reason = payload.get("stop_reason") or "end_turn"
                return result

            tool_results: list[dict] = []
            for tu in tool_uses:
                name = tu.get("name", "")
                tool_id = tu.get("id", "")
                tool_input = tu.get("input") or {}
                result.tool_calls.append({"name": name, "input": tool_input})

                if name not in TOOL_NAMES:
                    outcome: dict = {"error": f"unknown tool '{name}'"}
                else:
                    outcome = await self._safe_dispatch(name, tool_input)
                    result.proposals.append({"action_type": name, "input": tool_input, "result": outcome})

                tool_results.append({
                    "type": "tool_result",
                    "tool_use_id": tool_id,
                    "content": json.dumps(outcome)[:_MAX_TOOL_RESULT_CHARS],
                    **({"is_error": True} if "error" in outcome else {}),
                })

            messages.append({"role": "user", "content": tool_results})

        result.stop_reason = "max_turns"
        return result

    async def _safe_dispatch(self, name: str, tool_input: dict) -> dict:
        try:
            return await self.dispatcher(name, tool_input)
        except httpx.HTTPStatusError as exc:
            return {"error": "dispatch_failed", "status": exc.response.status_code}
        except Exception as exc:  # noqa: BLE001 — surface as a tool error, never crash the loop
            return {"error": "dispatch_failed", "detail": type(exc).__name__}


def make_laravel_dispatcher(
    *, base_url: str, scoped_token: str, client: httpx.AsyncClient, subject: dict | None = None
) -> Dispatcher:
    """Dispatcher that DRAFTS a proposal via the Laravel scoped-token callback.

    Maps a tool call to `POST {base_url}/api/eddy/agent/actions/propose` carrying the
    `ops:draft` bearer token. Never sends `ops:approve` — approval stays human.
    """
    endpoint = f"{base_url.rstrip('/')}/api/eddy/agent/actions/propose"

    async def _dispatch(action_type: str, tool_input: dict) -> dict:
        payload: dict[str, Any] = {
            "action_type": action_type,
            "scope_key": tool_input.get("scope_key"),
            "rationale": tool_input.get("rationale"),
            "params": tool_input.get("params") or {},
            "expected_impact": tool_input.get("expected_impact") or {},
            "runner_up": tool_input.get("runner_up"),
        }
        if subject:
            payload.setdefault("scope_type", subject.get("subject_type"))
        resp = await client.post(
            endpoint,
            json={k: v for k, v in payload.items() if v is not None},
            headers={"Authorization": f"Bearer {scoped_token}", "Accept": "application/json"},
            timeout=30.0,
        )
        resp.raise_for_status()
        return resp.json()

    return _dispatch
