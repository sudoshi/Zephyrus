import json

import httpx
import respx
from fastapi.testclient import TestClient

from app.main import app

OLLAMA = "http://test-ollama"


def _settings_env(monkeypatch):
    monkeypatch.setenv("OLLAMA_BASE_URL", OLLAMA)
    monkeypatch.setenv("EDDY_OLLAMA_MODEL", "medgemma")
    monkeypatch.setenv("EDDY_ALLOW_CLOUD", "false")
    monkeypatch.setenv("EDDY_WARMUP_ON_STARTUP", "false")
    from app.config import get_settings

    get_settings.cache_clear()


def _ndjson(*objs) -> bytes:
    return ("\n".join(json.dumps(o) for o in objs)).encode()


@respx.mock
def test_stream_emits_tokens_then_a_complete_event_with_the_proposal(monkeypatch):
    _settings_env(monkeypatch)
    respx.post(f"{OLLAMA}/api/chat").mock(
        return_value=httpx.Response(
            200,
            content=_ndjson(
                {"message": {"content": "Flagging the imaging barrier. "}},
                {"message": {"content": '<propose_action>{"action_type":"flag_barrier","title":"Imaging delay","params":{"unit":"4E"},"rationale":"held discharges","runner_up":"escalate"}</propose_action>'}},
                {"done": True},
            ),
        )
    )

    payload = {
        "message": "Flag the imaging barrier on 4 East.",
        "surface": "rtdc",
        "provider_policy": {"provider_type": "ollama", "mode": "local_only", "model": "medgemma"},
        "allowed_actions": ["flag_barrier", "propose_surge_plan"],
    }

    tokens = 0
    final = None
    with TestClient(app) as client:
        with client.stream("POST", "/eddy/chat/stream", json=payload) as resp:
            assert resp.status_code == 200
            for line in resp.iter_lines():
                if not line.startswith("data: "):
                    continue
                data = line[6:]
                if data == "[DONE]":
                    break
                obj = json.loads(data)
                if "token" in obj:
                    tokens += 1
                if obj.get("complete"):
                    final = obj

    assert tokens >= 1
    assert final is not None
    # The <propose_action> block is stripped from the clean reply and parsed out.
    assert "propose_action" not in final["clean_reply"]
    assert final["proposed_action"]["action_type"] == "flag_barrier"
    assert final["proposed_action"]["params"]["unit"] == "4E"
