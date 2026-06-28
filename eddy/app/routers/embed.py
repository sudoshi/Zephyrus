"""Subsystem A — embeddings for institutional-knowledge RAG (Phase 6).

A thin, stateless pass-through to the local embedding model (Ollama). Laravel owns
the vector store (`eddy.eddy_knowledge.embedding`); this endpoint only turns text
into a vector. Kept local-only: knowledge text is institutional, but embedding it
stays on-prem regardless of the chat provider policy.
"""

from __future__ import annotations

import httpx
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel

from app.config import get_settings

router = APIRouter(prefix="/eddy", tags=["eddy-embed"])


class EmbedRequest(BaseModel):
    text: str
    model: str | None = None


@router.post("/embed")
async def embed(req: EmbedRequest) -> dict:
    settings = get_settings()
    model = req.model or settings.eddy_embedding_model
    base = settings.ollama_base_url.rstrip("/")

    try:
        async with httpx.AsyncClient(timeout=30) as client:
            resp = await client.post(f"{base}/api/embed", json={"model": model, "input": req.text})
            resp.raise_for_status()
            data = resp.json()
    except httpx.HTTPError as exc:
        # Fail loud here (502) so Laravel's embedding service can fail OPEN to the
        # keyword path rather than silently persist a bad/empty vector.
        raise HTTPException(status_code=502, detail=f"embedding backend error: {exc}") from exc

    # /api/embed → {"embeddings": [[...]]}; older /api/embeddings → {"embedding": [...]}.
    embeddings = data.get("embeddings")
    vector = embeddings[0] if embeddings else data.get("embedding")

    if not vector:
        raise HTTPException(status_code=502, detail="embedding backend returned no vector")

    return {"embedding": vector, "model": model, "dimensions": len(vector)}
