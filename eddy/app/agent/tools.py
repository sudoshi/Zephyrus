"""Eddy agent tool catalog in the Anthropic `/v1/messages` `tools` shape.

Mirrors `app/Services/Eddy/EddyActionService::CATALOG` (PHP). Each tool DRAFTS a
governance proposal through the Laravel scoped-token callback; Eddy can never
approve (the scoped token carries `ops:draft`, never `ops:approve`). Keep the
names in lockstep with the PHP CATALOG — Laravel is authoritative on tier/risk.
"""

from __future__ import annotations

_SHARED_PROPS: dict[str, dict] = {
    "scope_key": {
        "type": "string",
        "description": "Operational scope, e.g. 'ED', 'HOSP1', or a unit/dept/service-line key.",
    },
    "rationale": {
        "type": "string",
        "description": "1-2 sentences justifying the proposal, citing the driving metric.",
    },
    "expected_impact": {
        "type": "object",
        "description": 'Optional metric -> expected delta, e.g. {"nedocs": -25}.',
    },
    "params": {
        "type": "object",
        "description": "Optional action-specific parameters (unit, barrier, target, ...).",
    },
    "runner_up": {
        "type": "string",
        "description": "Optional alternative action considered but not chosen.",
    },
}


def _tool(name: str, description: str) -> dict:
    return {
        "name": name,
        "description": description,
        "input_schema": {
            "type": "object",
            "properties": dict(_SHARED_PROPS),
            "required": ["scope_key", "rationale"],
        },
    }


# Names + order mirror EddyActionService::CATALOG (tier/risk noted for the model).
ANTHROPIC_TOOLS: list[dict] = [
    _tool("flag_barrier", "Flag a throughput/discharge barrier (T1, low risk)."),
    _tool("propose_huddle_action", "Propose a huddle action item (T1, low). Staffing/huddle follow-ups."),
    _tool("propose_transport_dispatch", "Propose a transport dispatch (T2, medium). Patient-movement / flow delays."),
    _tool("propose_bed_placement", "Propose a bed placement (T3, high). A patient needs an assigned bed."),
    _tool("propose_surge_plan", "Propose a surge / red-stretch plan (T3, critical). ED NEDOCS severe / capacity breach."),
    _tool("flag_pathway_deviation", "Flag a care-pathway conformance deviation (T2, medium). Sepsis/SEP-1 / protocol nonconformance."),
]

TOOL_NAMES: frozenset[str] = frozenset(t["name"] for t in ANTHROPIC_TOOLS)

AGENT_SYSTEM_PROMPT = (
    "You are Eddy, a hospital operations agent for Zephyrus. You read the live operational "
    "picture and, when action is warranted, CALL exactly one tool to DRAFT a proposal with a "
    "scope_key and a rationale that cites the driving metric. You may only PROPOSE drafts — a "
    "human always reviews and approves; you never execute or approve anything. After a tool "
    "returns its draft, briefly confirm what you proposed. If no action is warranted, answer "
    "concisely in prose without calling a tool."
)
