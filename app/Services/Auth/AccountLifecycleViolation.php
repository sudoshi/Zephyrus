<?php

namespace App\Services\Auth;

use RuntimeException;

class AccountLifecycleViolation extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }
}
