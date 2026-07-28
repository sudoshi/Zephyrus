<?php

namespace App\Nightingale\Activation;

/**
 * Patient/cohort pilot-enrollment prerequisite.
 *
 * Enrolled may be emitted only after a future pilot service validates scope,
 * expiry, exclusions, consent, support coverage, and withdrawal state.
 */
enum NightingalePilotEnrollmentState: string
{
    case NotEnrolled = 'not_enrolled';
    case Enrolled = 'enrolled';

    public function permitsFurtherEvaluation(): bool
    {
        return $this === self::Enrolled;
    }
}
