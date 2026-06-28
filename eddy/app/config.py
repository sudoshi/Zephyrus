"""Eddy service settings — read from the environment (the shared Zephyrus .env).

Provider secrets live ONLY here, in the Eddy service env. They are never exposed
to Laravel/Vite. Laravel sends non-secret routing hints (provider/model/mode);
Eddy resolves the actual key from these settings.
"""

from __future__ import annotations

from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="", extra="ignore", case_sensitive=False)

    # --- service ---
    eddy_enabled: bool = False
    eddy_port: int = 8000
    eddy_shared_secret: str = ""           # HMAC for Laravel<->Eddy request bodies
    eddy_callback_token: str = ""          # bearer Eddy uses on telemetry callbacks
    agency_api_base_url: str = "http://nginx:80"  # Eddy -> Laravel callback base
    cors_origins: str = "http://localhost,http://localhost:5173"

    # --- routing posture ---
    eddy_allow_cloud: bool = False
    eddy_default_provider_mode: str = "local_first"

    # --- PHI guards ---
    eddy_phi_detection_enabled: bool = True
    eddy_phi_block_on_detection: bool = True

    # --- local provider (Ollama / MedGemma) ---
    ollama_base_url: str = "http://host.docker.internal:11434"
    eddy_ollama_model: str = "puyangwang/medgemma-27b-it:q4_0"
    eddy_ollama_keep_alive: int = 3600
    eddy_ollama_num_predict: int = 256
    eddy_warmup_on_startup: bool = False

    # --- frontier provider (Anthropic / Claude Agent SDK) ---
    anthropic_api_key: str = ""
    eddy_cloud_chat_model: str = "claude-sonnet-4-6"
    eddy_agent_model: str = "claude-opus-4-8"
    eddy_agent_effort: str = "xhigh"
    eddy_agent_max_turns: int = 24
    eddy_agent_max_budget_usd: float = 5.0
    eddy_agent_approval_timeout_seconds: int = 600

    # --- agent local proxy (Ollama behind an Anthropic-compatible proxy) ---
    eddy_agent_local_base_url: str = "http://claude-router:8787"
    eddy_agent_local_model: str = "qwen2.5-coder:32b"
    eddy_agent_local_actions_enabled: bool = False

    @property
    def cors_origins_list(self) -> list[str]:
        return [o.strip() for o in self.cors_origins.split(",") if o.strip()]


@lru_cache
def get_settings() -> Settings:
    return Settings()
