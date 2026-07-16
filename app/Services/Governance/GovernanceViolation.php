<?php

namespace App\Services\Governance;

use RuntimeException;

final class GovernanceViolation extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
