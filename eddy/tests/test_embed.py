import httpx
import respx
from fastapi.testclient import TestClient

from app.main import app

OLLAMA = "http://test-ollama"


def _settings_env(monkeypatch):
    monkeypatch.setenv("OLLAMA_BASE_URL", OLLAMA)
    monkeypatch.setenv("EDDY_EMBEDDING_MODEL", "nomic-embed-text")
    monkeypatch.setenv("EDDY_WARMUP_ON_STARTUP", "false")
    from app.config import get_settings

    get_settings.cache_clear()


@respx.mock
def test_embed_returns_vector_and_dimensions(monkeypatch):
    _settings_env(monkeypatch)
    respx.post(f"{OLLAMA}/api/embed").mock(
        return_value=httpx.Response(200, json={"embeddings": [[0.1, 0.2, 0.3, 0.4]]})
    )

    with TestClient(app) as client:
        resp = client.post("/eddy/embed", json={"text": "imaging delay on 3 west"})

    assert resp.status_code == 200
    body = resp.json()
    assert body["embedding"] == [0.1, 0.2, 0.3, 0.4]
    assert body["dimensions"] == 4
    assert body["model"] == "nomic-embed-text"


@respx.mock
def test_embed_accepts_legacy_embedding_shape(monkeypatch):
    _settings_env(monkeypatch)
    respx.post(f"{OLLAMA}/api/embed").mock(
        return_value=httpx.Response(200, json={"embedding": [1.0, 0.0]})
    )

    with TestClient(app) as client:
        resp = client.post("/eddy/embed", json={"text": "x"})

    assert resp.status_code == 200
    assert resp.json()["embedding"] == [1.0, 0.0]


@respx.mock
def test_embed_surfaces_backend_failure_as_502(monkeypatch):
    _settings_env(monkeypatch)
    respx.post(f"{OLLAMA}/api/embed").mock(return_value=httpx.Response(500, text="boom"))

    with TestClient(app) as client:
        resp = client.post("/eddy/embed", json={"text": "x"})

    assert resp.status_code == 502
