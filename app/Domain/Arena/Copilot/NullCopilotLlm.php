<?php

namespace App\Domain\Arena\Copilot;

/**
 * Part X (X4) — the deterministic default. No model is called; every capability
 * falls back to its deterministic assembly (template narrative, keyword query
 * routing). This is the binding used in tests, in CI, and whenever the Eddy LLM
 * service is not enabled — so the copilot's GUARDRAILS (fitness gate, allow-list,
 * pending approval) are all exercised without any live model dependency.
 */
class NullCopilotLlm implements CopilotLlm
{
    public function generate(string $system, string $user, array $options = []): ?string
    {
        return null;
    }

    public function isLive(): bool
    {
        return false;
    }
}
