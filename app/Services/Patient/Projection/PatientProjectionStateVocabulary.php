<?php

namespace App\Services\Patient\Projection;

use InvalidArgumentException;

/**
 * Validates code-owned patient projection states against a versioned,
 * locale-addressable vocabulary. It deliberately governs state codes only;
 * released projection free text remains under the clinical content boundary.
 */
final class PatientProjectionStateVocabulary
{
    /** @var array<string, array<string, string>> */
    private array $definitions;

    /**
     * @param  array<string, mixed>|null  $registry
     */
    public function __construct(?array $registry = null)
    {
        $registry ??= (array) config('hummingbird-patient-content.state_vocabulary', []);
        $locale = $registry['default_locale'] ?? null;
        $definitions = is_string($locale)
            ? ($registry['locales'][$locale] ?? null)
            : null;

        if (! is_array($definitions) || $definitions === []) {
            throw new InvalidArgumentException('patient_projection_state_vocabulary_unavailable');
        }

        /** @var array<string, array<string, string>> $definitions */
        $this->definitions = $definitions;
        $this->assertRegistry();
    }

    public function assertCode(string $domain, mixed $code): void
    {
        if (! is_string($code) || ! array_key_exists($code, $this->labels($domain))) {
            throw new InvalidArgumentException('patient_projection_state_code_invalid');
        }
    }

    public function label(string $domain, string $code): string
    {
        $labels = $this->labels($domain);
        if (! array_key_exists($code, $labels)) {
            throw new InvalidArgumentException('patient_projection_state_code_invalid');
        }

        return $labels[$code];
    }

    /** @return array<string, string> */
    public function labels(string $domain): array
    {
        $labels = $this->definitions[$domain] ?? null;
        if (! is_array($labels) || $labels === []) {
            throw new InvalidArgumentException('patient_projection_state_domain_invalid');
        }

        return $labels;
    }

    private function assertRegistry(): void
    {
        foreach ($this->definitions as $domain => $labels) {
            if (! is_string($domain) || preg_match('/^[a-z][a-z0-9_]{2,119}$/', $domain) !== 1
                || ! is_array($labels) || $labels === []) {
                throw new InvalidArgumentException('patient_projection_state_vocabulary_invalid');
            }

            foreach ($labels as $code => $label) {
                if (! is_string($code) || preg_match('/^[a-z][a-z0-9_]{2,119}$/', $code) !== 1
                    || ! is_string($label) || trim($label) === '' || mb_strlen($label) > 160) {
                    throw new InvalidArgumentException('patient_projection_state_vocabulary_invalid');
                }
            }
        }
    }
}
