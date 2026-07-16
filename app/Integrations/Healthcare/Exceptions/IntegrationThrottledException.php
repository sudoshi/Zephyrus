<?php

namespace App\Integrations\Healthcare\Exceptions;

final class IntegrationThrottledException extends IntegrationProtocolException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
        string $errorCode = 'partner_rate_limited',
    ) {
        parent::__construct($errorCode);
    }
}
