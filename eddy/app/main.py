"""Zephyrus Eddy Service — FastAPI entrypoint.

Phase 0 mounts: /health, /eddy/chat (stub), /agent/sessions (stub). Subsequent
phases add the real provider router, the Claude Agent SDK loop, live-context, and
Reverb streaming behind these same routes.
"""

from __future__ import annotations

from contextlib import asynccontextmanager

import httpx
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app import __version__
from app.config import get_settings
from app.routers import agent, eddy, embed, health

settings = get_settings()


@asynccontextmanager
async def lifespan(_: FastAPI):
    # Pre-load the local model at boot so the first user request hits a warm model.
    # A truly-cold 27B q4_0 load can exceed the per-request adapter timeout; warming
    # here (best-effort) keeps live latency at steady-state. Enable in prod via
    # EDDY_WARMUP_ON_STARTUP=true.
    if settings.eddy_warmup_on_startup:
        try:
            async with httpx.AsyncClient(timeout=600) as client:
                await client.post(
                    f"{settings.ollama_base_url.rstrip('/')}/api/chat",
                    json={
                        "model": settings.eddy_ollama_model,
                        "messages": [{"role": "user", "content": "ok"}],
                        "stream": False,
                        "think": False,
                        "keep_alive": settings.eddy_ollama_keep_alive,
                        "options": {"num_predict": 1},
                    },
                )
        except Exception:
            pass  # warming is best-effort; never block startup
    yield


app = FastAPI(title="Zephyrus Eddy Service", version=__version__, lifespan=lifespan)

app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.cors_origins_list,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

app.include_router(health.router)
app.include_router(eddy.router)
app.include_router(embed.router)
app.include_router(agent.router)


@app.get("/")
async def root() -> dict:
    return {"service": "zephyrus-eddy", "version": __version__, "docs": "/docs"}
