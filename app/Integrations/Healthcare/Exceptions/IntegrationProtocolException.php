<?php

namespace App\Integrations\Healthcare\Exceptions;

use RuntimeException;

class IntegrationProtocolException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
