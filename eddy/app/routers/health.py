"""Health + readiness. Always returns 200 (liveness); reports provider reachability
as info so the Laravel admin surface can show degradation without the endpoint failing.
"""

from __future__ import annotations

import httpx
from fastapi import APIRouter

from app import __version__
from app.config import get_settings

router = APIRouter(tags=["health"])


@router.get("/health")
async def health() -> dict:
    settings = get_settings()
    ollama_reachable = await _probe_ollama(settings.ollama_base_url)
    return {
        "status": "ok",
        "service": "zephyrus-eddy",
        "version": __version__,
        "enabled": settings.eddy_enabled,
        "allow_cloud": settings.eddy_allow_cloud,
        "providers": {
            "ollama_reachable": ollama_reachable,
            "anthropic_key_configured": bool(settings.anthropic_api_key),
        },
    }


async def _probe_ollama(base_url: str) -> bool:
    try:
        async with httpx.AsyncClient(timeout=2.0) as client:
            resp = await client.get(f"{base_url.rstrip('/')}/api/version")
            return resp.status_code == 200
    except Exception:
        return False
