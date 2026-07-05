"""Zephyrus Patient-Flow Arena — OCPM sidecar (FastAPI).

Part X (X1): the stateless, read-only object-centric process-mining engine. It
reads a de-identified OCEL 2.0 export (never prod.*, no PHI, no identifiers) and
returns canon-shaped discovery/summary JSON for the Laravel orchestrator to cache
and the React Study UI to render. pm4py does the mining; this service is the seam.
"""

__version__ = "0.1.0"
