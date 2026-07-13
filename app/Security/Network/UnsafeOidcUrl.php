<?php

namespace App\Security\Network;

use InvalidArgumentException;

class UnsafeOidcUrl extends InvalidArgumentException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
