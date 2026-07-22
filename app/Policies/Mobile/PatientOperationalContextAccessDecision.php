<?php

namespace App\Policies\Mobile;

/**
 * A patient-context authorization result safe to persist in the staff audit
 * ledger. Neither this object nor its reason codes may contain a source
 * patient identifier, free text, or clinical content.
 */
final readonly class PatientOperationalContextAccessDecision
{
    private function __construct(
        public bool $allowed,
        public string $policyKey,
        public string $reasonCode,
    ) {}

    public static function allow(string $policyKey, string $reasonCode): self
    {
        return new self(true, $policyKey, $reasonCode);
    }

    public static function deny(string $policyKey, string $reasonCode): self
    {
        return new self(false, $policyKey, $reasonCode);
    }
}
