"""Health + readiness. Always 200 (liveness); reports pm4py availability and
whether the configured OCEL export is present as info, so the Laravel admin
surface can show 'sidecar up but no log yet' without the endpoint failing.
"""

from __future__ import annotations

import os

from fastapi import APIRouter

from app import __version__
from app.config import get_settings
from app.ocel_loader import PM4PY_AVAILABLE, PM4PY_VERSION

router = APIRouter(tags=["health"])


@router.get("/health")
async def health() -> dict:
    settings = get_settings()
    return {
        "status": "ok",
        "service": "zephyrus-arena",
        "version": __version__,
        "enabled": settings.arena_enabled,
        "engine": {
            "pm4py_available": PM4PY_AVAILABLE,
            "pm4py_version": PM4PY_VERSION,
        },
        "ocel_export_present": os.path.isfile(settings.arena_ocel_export_path),
    }
