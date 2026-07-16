<?php

namespace App\Services\Authorization;

use RuntimeException;

final class AdminScopeViolation extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
