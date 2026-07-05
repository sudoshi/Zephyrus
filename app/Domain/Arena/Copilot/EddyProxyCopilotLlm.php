<?php

namespace App\Domain\Arena\Copilot;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Part X (X4) — the real LLM driver, off by default. Proxies to the existing Eddy
 * Python service (`services.eddy.url` /eddy/chat), reusing its local-first provider
 * routing (MedGemma via Ollama) rather than standing up a second model stack. The
 * browser never reaches this; only the server-side ArenaCopilotService does.
 *
 * Belt-and-suspenders PHI guard: the Arena is PHI-free by construction (the copilot
 * only ever builds prompts from de-identified aggregates and activity labels), but
 * we still scan the outbound prompt and REFUSE to send if anything PHI-shaped slips
 * in — failing closed to the deterministic path (§X.8.2, "no PHI in AI prompts").
 *
 * Degrades to null on any error (disabled, unreachable, timeout, non-2xx) so the
 * caller falls back to deterministic assembly — the copilot never hard-fails on the
 * model being down.
 */
class EddyProxyCopilotLlm implements CopilotLlm
{
    /** Obvious PHI shapes that must never leave for an LLM. Additive to the Arena's structural PHI-freeness. */
    private const PHI_PATTERNS = [
        '/\b\d{3}-\d{2}-\d{4}\b/',                       // SSN
        '/\bMRN[:#]?\s*\d+/i',                           // medical record number
        '/\b\d{4}-\d{2}-\d{2}\b/',                       // ISO date (DOB-shaped)
        '/\b[\w.+-]+@[\w-]+\.[\w.-]+\b/',                // email
        '/\b\(?\d{3}\)?[-.\s]\d{3}[-.\s]\d{4}\b/',       // phone
    ];

    public function isLive(): bool
    {
        return (bool) config('services.eddy.enabled') && (bool) config('services.arena.ai_enabled');
    }

    public function generate(string $system, string $user, array $options = []): ?string
    {
        if (! $this->isLive()) {
            return null;
        }

        if ($this->looksLikePhi($system) || $this->looksLikePhi($user)) {
            Log::warning('arena.copilot.phi_guard_tripped', ['surface' => 'arena_copilot']);

            return null; // fail closed → deterministic path
        }

        try {
            $response = Http::timeout((int) config('services.eddy.timeout', 30))
                ->acceptJson()
                ->post(rtrim((string) config('services.eddy.url'), '/').'/eddy/chat', [
                    'message' => $user,
                    'system_prompt' => $system,
                    'surface' => 'arena_copilot',
                    'temperature' => $options['temperature'] ?? 0.1,
                    'max_output_tokens' => $options['max_tokens'] ?? 700,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $reply = $response->json('reply');

            return is_string($reply) && trim($reply) !== '' ? $reply : null;
        } catch (\Throwable $e) {
            Log::warning('arena.copilot.llm_unreachable', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function looksLikePhi(string $text): bool
    {
        foreach (self::PHI_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
