<?php

namespace App\Domain\Arena\Copilot;

/**
 * Part X (X4) — the copilot's LLM seam. Deliberately tiny and OPTIONAL: every
 * copilot capability has a deterministic path, and the LLM only ENHANCES it
 * (polishes a narrative, maps a fuzzy question onto the allow-listed query set).
 *
 * `generate()` NEVER throws and returns null when no model is available, so the
 * caller falls back to deterministic assembly — this is what makes the copilot
 * "ship disabled" (ARENA_AI_ENABLED off, or no model wired) yet still function.
 * The prompt is built ONLY from de-identified aggregates and activity labels — the
 * copilot never reasons over PHI (§X.8.2).
 */
interface CopilotLlm
{
    /**
     * @param  array<string, mixed>  $options  e.g. ['temperature' => 0.1, 'max_tokens' => 700, 'json' => true]
     * @return string|null the completion, or null when the LLM is unavailable/disabled
     */
    public function generate(string $system, string $user, array $options = []): ?string;

    /** Whether a real model backs this driver (false = deterministic-only). */
    public function isLive(): bool;
}
