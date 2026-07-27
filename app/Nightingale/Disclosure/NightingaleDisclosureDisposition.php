<?php

namespace App\Nightingale\Disclosure;

/**
 * Publicly observable disposition of the route-free disclosure foundation.
 *
 * ContinueToGovernedProjectionEvaluation is not an authorization or release
 * decision. A future operation must still pass source, field-release,
 * freshness, language, correction, audit-before-disclosure, and
 * serialization-boundary recheck gates.
 */
enum NightingaleDisclosureDisposition: string
{
    case WithholdNotFound = 'withhold_not_found';
    case ContinueToGovernedProjectionEvaluation = 'continue_to_governed_projection_evaluation';

    /**
     * The complete public failure tuple. It intentionally has no message,
     * internal reason, identifier, retry hint, redirect, or variable field.
     *
     * @return null|array{status: int, code: string, cache_control: string}
     */
    public function publicFailure(): ?array
    {
        return match ($this) {
            self::WithholdNotFound => [
                'status' => 404,
                'code' => 'not_found',
                'cache_control' => 'private, no-store, max-age=0',
            ],
            self::ContinueToGovernedProjectionEvaluation => null,
        };
    }
}
