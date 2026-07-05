"""Subsystem B — local agent tool-loop tests.

Unit tests mock the proxy (`respx`) for deterministic CI. The live integration
test (opt-in via EDDY_LIVE_PROXY_TEST=1) drives the loop through the real LiteLLM
proxy + Ollama model — that is the end-to-end proof the model tool-calls the Eddy
catalog. Style mirrors the existing eddy tests: plain functions + asyncio.run.
"""

import asyncio
import json
import os

import httpx
import pytest
import respx

from app.agent.local_loop import LocalAgentLoop, make_laravel_dispatcher

PROXY = "http://test-proxy"
MESSAGES_URL = f"{PROXY}/v1/messages"


async def _mock_dispatch(name: str, tool_input: dict) -> dict:
    return {
        "action_uuid": "uuid-1", "action_type": name, "tier": "T3",
        "risk": "critical", "status": "draft", "approved": False,
    }


def _loop(client: httpx.AsyncClient, dispatcher=_mock_dispatch, **kw) -> LocalAgentLoop:
    return LocalAgentLoop(
        proxy_base_url=PROXY, model="qwen3:8b", client=client, dispatcher=dispatcher, **kw
    )


def _tool_use(tool="propose_surge_plan", scope="ED"):
    return {
        "content": [{
            "type": "tool_use", "id": "tu_1", "name": tool,
            "input": {"scope_key": scope, "rationale": "NEDOCS 175, severe overcrowding"},
        }],
        "stop_reason": "tool_use",
    }


def _text(text="Proposed a surge plan for the ED."):
    return {"content": [{"type": "text", "text": text}], "stop_reason": "end_turn"}


def _run(coro):
    return asyncio.run(coro)


@respx.mock
def test_single_tool_then_finalizes():
    respx.post(MESSAGES_URL).mock(side_effect=[
        httpx.Response(200, json=_tool_use()),
        httpx.Response(200, json=_text()),
    ])

    async def go():
        async with httpx.AsyncClient() as c:
            return await _loop(c).run("ED NEDOCS is 175 (severe).")

    res = _run(go())
    assert res.turns == 2
    assert len(res.tool_calls) == 1
    assert len(res.proposals) == 1
    assert res.proposals[0]["action_type"] == "propose_surge_plan"
    assert res.proposals[0]["result"]["status"] == "draft"
    assert res.final_text == "Proposed a surge plan for the ED."
    assert res.stop_reason == "end_turn"


@respx.mock
def test_no_tool_returns_prose():
    respx.post(MESSAGES_URL).mock(return_value=httpx.Response(200, json=_text("All units nominal.")))

    async def go():
        async with httpx.AsyncClient() as c:
            return await _loop(c).run("How's the house?")

    res = _run(go())
    assert res.turns == 1
    assert res.proposals == []
    assert res.final_text == "All units nominal."


@respx.mock
def test_unknown_tool_is_rejected_not_proposed():
    respx.post(MESSAGES_URL).mock(side_effect=[
        httpx.Response(200, json=_tool_use(tool="delete_everything")),
        httpx.Response(200, json=_text("Understood.")),
    ])

    async def go():
        async with httpx.AsyncClient() as c:
            return await _loop(c).run("do something dangerous")

    res = _run(go())
    # The call is logged, but an unknown tool never becomes a proposal.
    assert res.tool_calls and res.tool_calls[0]["name"] == "delete_everything"
    assert res.proposals == []


@respx.mock
def test_max_turns_caps_a_runaway_loop():
    # Model never stops calling tools → loop must bail at max_turns.
    respx.post(MESSAGES_URL).mock(return_value=httpx.Response(200, json=_tool_use()))

    async def go():
        async with httpx.AsyncClient() as c:
            return await _loop(c, max_turns=3).run("loop forever")

    res = _run(go())
    assert res.turns == 3
    assert res.stop_reason == "max_turns"
    assert len(res.proposals) == 3


@respx.mock
def test_dispatch_failure_is_surfaced_not_crashing():
    async def _boom(name, tool_input):
        raise httpx.ConnectError("laravel down")

    respx.post(MESSAGES_URL).mock(side_effect=[
        httpx.Response(200, json=_tool_use()),
        httpx.Response(200, json=_text("Noted the failure.")),
    ])

    async def go():
        async with httpx.AsyncClient() as c:
            return await _loop(c, dispatcher=_boom).run("propose a surge plan")

    res = _run(go())
    assert res.proposals[0]["result"]["error"] == "dispatch_failed"
    assert res.final_text == "Noted the failure."


@respx.mock
def test_laravel_dispatcher_posts_scoped_token_and_maps_payload():
    captured = {}

    def _capture(request: httpx.Request) -> httpx.Response:
        captured["auth"] = request.headers.get("Authorization")
        captured["body"] = json.loads(request.content)
        return httpx.Response(200, json={"action_uuid": "x", "status": "draft"})

    route = respx.post("http://laravel/api/eddy/agent/actions/propose").mock(side_effect=_capture)

    async def go():
        async with httpx.AsyncClient() as c:
            dispatch = make_laravel_dispatcher(base_url="http://laravel", scoped_token="tok-123", client=c)
            return await dispatch("propose_surge_plan", {"scope_key": "ED", "rationale": "severe"})

    out = _run(go())
    assert route.called
    assert captured["auth"] == "Bearer tok-123"
    assert captured["body"]["action_type"] == "propose_surge_plan"
    assert captured["body"]["scope_key"] == "ED"
    assert out["status"] == "draft"


# --- opt-in live integration: real proxy (LiteLLM) + real model (qwen3:8b) ------
_LIVE = os.environ.get("EDDY_LIVE_PROXY_TEST") == "1"


@pytest.mark.skipif(not _LIVE, reason="set EDDY_LIVE_PROXY_TEST=1 with the proxy+model up")
def test_live_proxy_drives_the_loop_end_to_end():
    async def _canned(name, tool_input):
        return {"action_uuid": "live-1", "action_type": name, "status": "draft", "approved": False}

    async def go():
        async with httpx.AsyncClient() as c:
            loop = LocalAgentLoop(
                proxy_base_url="http://127.0.0.1:8788", model="qwen3:8b",
                client=c, dispatcher=_canned, max_turns=6,
            )
            return await loop.run("ED NEDOCS is 175 (severe). 34 waiting, 14 boarding >4h, 2 diversions.")

    res = _run(go())
    assert res.tool_calls, "model made no tool call"
    assert any(p["action_type"] == "propose_surge_plan" for p in res.proposals), res.tool_calls
    assert res.stop_reason in ("end_turn", "max_turns")
