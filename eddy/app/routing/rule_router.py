"""Rule Router — two-stage rule-based model routing for Eddy (ported from Abby).

Stage 1: fast pattern matching on action keywords, greetings, message length.
Stage 2: complexity scoring for the remainder. Err toward cloud when uncertain.

The OHDSI Stage-2 indicators are replaced with hospital-operations vocabulary;
the two-stage shell and scoring math are unchanged.
"""

from __future__ import annotations

import re
from dataclasses import dataclass


@dataclass(frozen=True)
class RoutingDecision:
    model: str        # "claude" (cloud) or "local"
    stage: int
    reason: str
    confidence: float


_CLOUD_ACTION_WORDS = re.compile(
    r"\b(?:create|build|run|modify|delete|construct|generate|execute|schedule|optimi[sz]e|rebalance)\b",
    re.IGNORECASE,
)
_CLAUSE_MARKERS = re.compile(r"[,;]|\b(?:and|but|or)\b", re.IGNORECASE)
_LOCAL_GREETINGS = re.compile(
    r"^(?:hi|hello|hey|thanks?|thank\s+you|ok|okay|sure|got\s+it|sounds\s+good)"
    r"[!.,]?\s*(?:eddy)?[!.,]?"
    r"(?:\s*,?\s*how(?:'s|\s+are|\s+is|\s+do)\s+(?:you|it|things|everything)[\w\s?!.]*)?$",
    re.IGNORECASE,
)
_LOCAL_SIMPLE_LOOKUP = re.compile(
    r"^(?:how\s+many|show\s+me|list|count)\b"
    r"|^what\s+is\s+(?:the\s+count|the\s+number|the\s+census|bed)",
    re.IGNORECASE,
)
_SIMPLE_LOOKUP_MAX_CHARS = 80

# Hospital-operations complexity terms → cloud (replaces OHDSI propensity/hazard-ratio etc.)
_COMPLEXITY_INDICATORS: list[re.Pattern[str]] = [
    re.compile(r"\b(?:interpret|analyz|analys|critique|methodology|trade.?off|counterfactual)\b", re.IGNORECASE),
    re.compile(r"\b(?:scenario|what\s+if|rebalance|surge|diversion|boarding|throughput|bottleneck)\b", re.IGNORECASE),
    re.compile(r"\b(?:discharge\s+barrier|staffing\s+ratio|skill\s+mix|transfer\s+chain|downstream|cascade)\b", re.IGNORECASE),
    re.compile(r"\b(?:root\s+cause|spc|control\s+chart|pdsa|variation|constraint)\b", re.IGNORECASE),
    re.compile(r"\b(?:explain|compare|contrast|evaluate|assess|recommend|propose|plan)\b", re.IGNORECASE),
]
_SIMPLICITY_INDICATORS: list[re.Pattern[str]] = [
    re.compile(r"^(?:yes|no|ok|okay)[.!]?\s*$", re.IGNORECASE),
    re.compile(r"^what\s+is\s+\w+\??\s*$", re.IGNORECASE),
    re.compile(r"^(?:show\s+me|list|count)\b", re.IGNORECASE),
    re.compile(r"^(?:where\s+is|who\s+is|status\s+of)\b", re.IGNORECASE),
]

_CLOUD_SCORE_PER_COMPLEXITY = 0.20
_LOCAL_SCORE_PER_SIMPLICITY = 0.30
_CLOUD_TIEBREAKER = 0.05


class RuleRouter:
    def route(self, message: str, *, budget_exhausted: bool = False) -> RoutingDecision:
        if budget_exhausted:
            return RoutingDecision("local", 1, "budget_exhausted", 1.0)

        stripped = message.strip()

        if _LOCAL_GREETINGS.match(stripped):
            return RoutingDecision("local", 1, "stage1_greeting", 0.95)
        if _CLOUD_ACTION_WORDS.search(stripped):
            return RoutingDecision("claude", 1, "stage1_action_word", 0.90)
        if len(stripped) > 200 and len(_CLAUSE_MARKERS.findall(stripped)) >= 2:
            return RoutingDecision("claude", 1, "stage1_complex_message", 0.85)
        if _LOCAL_SIMPLE_LOOKUP.match(stripped) and len(stripped) <= _SIMPLE_LOOKUP_MAX_CHARS:
            return RoutingDecision("local", 1, "stage1_simple_lookup", 0.90)

        cloud_score = 0.0 + _CLOUD_TIEBREAKER
        local_score = 0.0
        for pattern in _COMPLEXITY_INDICATORS:
            if pattern.search(stripped):
                cloud_score += _CLOUD_SCORE_PER_COMPLEXITY
        for pattern in _SIMPLICITY_INDICATORS:
            if pattern.search(stripped):
                local_score += _LOCAL_SCORE_PER_SIMPLICITY

        if cloud_score >= local_score:
            confidence = min(1.0, cloud_score / (cloud_score + local_score + 0.001) + 0.3)
            return RoutingDecision("claude", 2, "stage2_complexity_score", round(confidence, 3))
        confidence = min(1.0, local_score / (cloud_score + local_score + 0.001) + 0.2)
        return RoutingDecision("local", 2, "stage2_simplicity_score", round(confidence, 3))
