<?php

namespace App\Integrations\Healthcare\Exceptions;

use RuntimeException;

class IntegrationCredentialException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
