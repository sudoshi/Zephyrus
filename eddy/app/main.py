"""Zephyrus Eddy Service — FastAPI entrypoint.

Phase 0 mounts: /health, /eddy/chat (stub), /agent/sessions (stub). Subsequent
phases add the real provider router, the Claude Agent SDK loop, live-context, and
Reverb streaming behind these same routes.
"""

from __future__ import annotations

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app import __version__
from app.config import get_settings
from app.routers import agent, eddy, health

settings = get_settings()

app = FastAPI(title="Zephyrus Eddy Service", version=__version__)

app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.cors_origins_list,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

app.include_router(health.router)
app.include_router(eddy.router)
app.include_router(agent.router)


@app.get("/")
async def root() -> dict:
    return {"service": "zephyrus-eddy", "version": __version__, "docs": "/docs"}
