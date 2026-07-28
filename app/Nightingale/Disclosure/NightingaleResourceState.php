<?php

namespace App\Nightingale\Disclosure;

/**
 * Release-aware resource lookup result supplied only after a future
 * operation-specific policy evaluates existence and eligibility together.
 *
 * Omitted intentionally combines unknown, absent, closed, withheld,
 * unreleased, retracted, and otherwise non-disclosable resources at this
 * public boundary. Internal causes belong only in a separately approved,
 * content-free audit implementation.
 */
enum NightingaleResourceState: string
{
    case Released = 'released';
    case Omitted = 'omitted';

    public function permitsGovernedEvaluation(): bool
    {
        return $this === self::Released;
    }
}
