<?php

namespace App\Integrations\Healthcare\Exceptions;

final class IntegrationCircuitOpenException extends IntegrationProtocolException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('source_circuit_open');
    }
}
