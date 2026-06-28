import asyncio

import httpx
import respx

from app.config import Settings
from app.routing.chat_adapters import ChatAdapterResponse
from app.routing.router import ChatRouter

OLLAMA = "http://test-ollama"


def _settings(**kw) -> Settings:
    base = dict(
        ollama_base_url=OLLAMA,
        eddy_ollama_model="medgemma",
        anthropic_api_key="",
        eddy_allow_cloud=False,
        eddy_phi_detection_enabled=True,
        eddy_phi_block_on_detection=True,
        eddy_default_provider_mode="local_first",
    )
    base.update(kw)
    return Settings(**base)


def _mock_ollama(content="Net bed need is 12."):
    return respx.post(f"{OLLAMA}/api/chat").mock(
        return_value=httpx.Response(200, json={"message": {"content": content}, "prompt_eval_count": 50, "eval_count": 8})
    )


def _run(router, **kw):
    return asyncio.run(router.run(**kw))


@respx.mock
def test_local_policy_routes_to_ollama():
    _mock_ollama()
    res = _run(
        ChatRouter(_settings()),
        message="what is the net bed need?",
        provider_policy={"provider_type": "ollama", "mode": "local_only", "model": "medgemma"},
    )
    assert res.status == "success"
    assert res.provider == "ollama"
    assert "bed need" in res.reply.lower()
    assert res.fallback_reason is None


@respx.mock
def test_cloud_policy_falls_local_when_cloud_disabled():
    _mock_ollama()
    res = _run(
        ChatRouter(_settings(eddy_allow_cloud=False)),
        message="propose a surge plan",
        provider_policy={"provider_type": "anthropic", "mode": "cloud_first", "model": "claude-sonnet-4-6"},
    )
    assert res.provider == "ollama"
    assert res.fallback_reason == "cloud_disabled"


@respx.mock
def test_phi_in_message_blocks_cloud_and_forces_local():
    _mock_ollama()
    res = _run(
        ChatRouter(_settings(eddy_allow_cloud=True, anthropic_api_key="sk-test")),
        message="patient ssn 123-45-6789 needs a bed assignment",
        provider_policy={"provider_type": "anthropic", "mode": "cloud_first", "model": "claude-sonnet-4-6"},
    )
    assert res.provider == "ollama"
    assert res.fallback_reason == "phi_detected"
    assert res.sanitizer_redaction_count >= 1


@respx.mock
def test_cloud_falls_local_when_key_missing():
    _mock_ollama()
    res = _run(
        ChatRouter(_settings(eddy_allow_cloud=True, anthropic_api_key="", eddy_phi_detection_enabled=False)),
        message="propose a surge plan",
        provider_policy={"provider_type": "anthropic", "mode": "cloud_first", "model": "claude-sonnet-4-6"},
    )
    assert res.provider == "ollama"
    assert res.fallback_reason == "cloud_key_missing"


def test_cloud_success_path(monkeypatch):
    class FakeAnthropic:
        def __init__(self, **kwargs):
            pass

        async def chat(self, req):
            return ChatAdapterResponse(
                reply="Here is a surge plan with a runner-up.",
                provider="anthropic",
                transport="anthropic_messages",
                model="claude-sonnet-4-6",
                tokens_in=120,
                tokens_out=60,
                cost_usd=0.0012,
            )

    monkeypatch.setattr("app.routing.router.AnthropicMessagesAdapter", FakeAnthropic)
    res = _run(
        ChatRouter(_settings(eddy_allow_cloud=True, anthropic_api_key="sk-test", eddy_phi_detection_enabled=False)),
        message="propose a surge plan",
        provider_policy={"provider_type": "anthropic", "mode": "cloud_first", "model": "claude-sonnet-4-6"},
    )
    assert res.provider == "anthropic"
    assert res.reply.startswith("Here is a surge plan")
    assert res.cost_usd > 0


@respx.mock
def test_local_error_when_ollama_unreachable():
    respx.post(f"{OLLAMA}/api/chat").mock(return_value=httpx.Response(500))
    res = _run(
        ChatRouter(_settings()),
        message="hi",
        provider_policy={"provider_type": "ollama", "mode": "local_only"},
    )
    assert res.status == "error"
    assert res.error_class is not None
