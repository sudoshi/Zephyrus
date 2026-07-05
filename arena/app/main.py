"""Zephyrus Patient-Flow Arena — OCPM sidecar FastAPI entrypoint (Part X, X1).

Mounts /health, /ocel/summary, /discover. Stateless and read-only over a
de-identified OCEL 2.0 export; the Laravel orchestrator caches results and the
React Study UI renders them. Ships disabled with the rest of the Arena
(`ARENA_ENABLED`); this service simply refuses no requests — the flag gates the
Laravel routes that call it.
"""

from __future__ import annotations

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app import __version__
from app.config import get_settings
from app.routers import conformance, discover, health, performance

settings = get_settings()

app = FastAPI(title="Zephyrus Patient-Flow Arena (OCPM sidecar)", version=__version__)

app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.cors_origins_list,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

app.include_router(health.router)
app.include_router(discover.router)
app.include_router(conformance.router)
app.include_router(performance.router)


@app.get("/")
async def root() -> dict:
    return {"service": "zephyrus-arena", "version": __version__, "docs": "/docs"}
