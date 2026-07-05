"""Resolve an OcelSource to a concrete file path pm4py can read, and centralise
pm4py availability so the routers can degrade gracefully when the mining engine
is absent (the doc's 'never white-screen; fall back to last-good' discipline).
"""

from __future__ import annotations

import json
import os
import tempfile
from contextlib import contextmanager
from typing import Any, Iterator

from app.config import get_settings

try:  # pm4py is heavy; import lazily so /health works even if it is missing.
    import pm4py  # type: ignore

    PM4PY_AVAILABLE = True
    PM4PY_VERSION = getattr(pm4py, "__version__", "unknown")
except Exception as exc:  # pragma: no cover - only hit in a broken image
    pm4py = None  # type: ignore
    PM4PY_AVAILABLE = False
    PM4PY_VERSION = f"unavailable: {exc}"


class OcelUnavailable(RuntimeError):
    """No readable OCEL source could be resolved from the request/config."""


@contextmanager
def resolve_ocel_path(ocel_path: str | None, ocel: dict[str, Any] | None) -> Iterator[str]:
    """Yield a filesystem path to an OCEL 2.0 JSON doc for the duration of the
    call. An inline doc is written to a private temp file (cleaned up on exit);
    an explicit or configured path is used in place."""

    if ocel is not None:
        tmp = tempfile.NamedTemporaryFile("w", suffix=".json", delete=False, encoding="utf-8")
        try:
            json.dump(ocel, tmp)
            tmp.flush()
            tmp.close()
            yield tmp.name
        finally:
            try:
                os.unlink(tmp.name)
            except OSError:
                pass
        return

    path = ocel_path or get_settings().arena_ocel_export_path
    if not path or not os.path.isfile(path):
        raise OcelUnavailable(f"no OCEL source: path '{path}' does not exist and no inline doc was provided")
    yield path


def read_ocel(path: str):
    """Read an OCEL 2.0 JSON export into a pm4py OCEL object."""
    if not PM4PY_AVAILABLE:
        raise OcelUnavailable(f"pm4py is not importable in this service ({PM4PY_VERSION})")
    return pm4py.read_ocel2_json(path)  # type: ignore[union-attr]
