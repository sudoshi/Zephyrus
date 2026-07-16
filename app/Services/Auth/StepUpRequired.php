<?php

namespace App\Services\Auth;

use RuntimeException;

final class StepUpRequired extends RuntimeException
{
    public function __construct(public readonly string $reason = 'step_up_required')
    {
        parent::__construct('Recent step-up authentication is required for this action.');
    }
}
