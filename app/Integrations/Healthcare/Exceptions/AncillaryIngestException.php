<?php

namespace App\Integrations\Healthcare\Exceptions;

use RuntimeException;
use Throwable;

class AncillaryIngestException extends RuntimeException
{
    /** @param array<string, scalar|null> $context */
    public function __construct(
        public readonly string $reasonCode,
        string $message,
        public readonly string $failureStage = 'ancillary_ingress',
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
