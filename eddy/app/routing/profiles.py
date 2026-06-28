"""Lightweight provider profile for the chat adapters.

Laravel resolves WHICH profile a surface uses (EddyProviderPolicyService) and
sends a non-secret routing-hint payload. Eddy turns that into a ProviderProfile
and resolves the actual secret (api_key) from its own env.
"""

from __future__ import annotations

from dataclasses import dataclass

# Current model pricing ($ per 1M tokens) — see config/eddy.php models block.
PRICING: dict[str, tuple[float, float]] = {
    "claude-opus-4-8": (5.0, 25.0),
    "claude-sonnet-4-6": (3.0, 15.0),
    "claude-haiku-4-5": (1.0, 5.0),
}


def estimate_cost(*, tokens_in: int, tokens_out: int, model: str) -> float:
    in_price, out_price = PRICING.get(model, (0.0, 0.0))
    return round((tokens_in / 1_000_000) * in_price + (tokens_out / 1_000_000) * out_price, 6)


@dataclass(frozen=True)
class ProviderProfile:
    provider: str          # ollama | anthropic
    transport: str         # ollama_chat | anthropic_messages
    model: str
    base_url: str | None = None
    profile_id: str = ""
    entitlement: str = "local"

    @property
    def is_cloud(self) -> bool:
        return self.entitlement != "local" and self.transport != "ollama_chat"
