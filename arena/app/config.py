"""Arena OCPM sidecar settings — read from the environment (shared Zephyrus .env).

The sidecar holds NO secrets and NO PHI. It reads a de-identified OCEL 2.0 export
(a path on a shared volume, or the doc inlined in a request body) and returns
aggregate process structure. `arena_enabled` mirrors the Laravel `ARENA_ENABLED`
flag so the whole subsystem ships disabled by default (§X.4).
"""

from __future__ import annotations

from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="", extra="ignore", case_sensitive=False)

    # --- service ---
    arena_enabled: bool = False
    arena_port: int = 8100
    cors_origins: str = "http://localhost,http://localhost:5173"

    # --- OCEL source ---
    # Default path the Laravel `ocel:export` writer targets on the shared volume;
    # a request may override with an inline doc or an explicit path.
    arena_ocel_export_path: str = "/data/ocel/ocel-export.json"

    # --- mining bounds (§X.4 risk: object-centric discovery can be slow) ---
    arena_max_object_types: int = 12
    arena_default_activity_min_freq: int = 1

    @property
    def cors_origins_list(self) -> list[str]:
        return [o.strip() for o in self.cors_origins.split(",") if o.strip()]


@lru_cache
def get_settings() -> Settings:
    return Settings()
