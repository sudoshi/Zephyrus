"""PHI sanitizer — the cloud-egress guard.

Scans text for common patient identifiers before any frontier (cloud) call.
Returns (redacted_text, redaction_count). When detection is enabled and the
count is > 0, the router force-locals (never "best-effort redacts and sends").

This is a defense-in-depth net, not the primary control: Eddy's operational
context is PHI-free aggregates by construction. The redaction_count is recorded
on eddy_cloud_usage as a compliance signal.
"""

from __future__ import annotations

import re
from dataclasses import dataclass

_PATTERNS: list[tuple[str, re.Pattern[str]]] = [
    ("ssn", re.compile(r"\b\d{3}-\d{2}-\d{4}\b")),
    ("mrn", re.compile(r"\b(?:mrn|medical\s+record(?:\s+number)?)\s*[:#]?\s*\d{5,12}\b", re.IGNORECASE)),
    ("dob", re.compile(r"\b(?:dob|date\s+of\s+birth)\s*[:#]?\s*\d{1,2}[/-]\d{1,2}[/-]\d{2,4}\b", re.IGNORECASE)),
    ("phone", re.compile(r"\b(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]\d{3}[-.\s]\d{4}\b")),
    ("email", re.compile(r"\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b")),
    ("encounter_ref", re.compile(r"\b(?:encounter|enc|csn|fin)\s*[:#]?\s*[A-Z0-9]{6,}\b", re.IGNORECASE)),
]


@dataclass(frozen=True)
class SanitizeResult:
    text: str
    redaction_count: int


def sanitize(text: str) -> SanitizeResult:
    if not text:
        return SanitizeResult(text="", redaction_count=0)
    count = 0
    redacted = text
    for label, pattern in _PATTERNS:
        redacted, n = pattern.subn(f"[REDACTED:{label}]", redacted)
        count += n
    return SanitizeResult(text=redacted, redaction_count=count)


def scan(*parts: str) -> int:
    """Count PHI identifiers across several text fragments (e.g. message + page_data)."""
    return sum(sanitize(p).redaction_count for p in parts if p)
