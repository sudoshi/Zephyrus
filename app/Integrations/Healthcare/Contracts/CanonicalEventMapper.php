<?php

namespace App\Integrations\Healthcare\Contracts;

use App\Integrations\Healthcare\DTO\NormalizedPayload;

interface CanonicalEventMapper
{
    /** @return list<\App\Integrations\Healthcare\DTO\CanonicalOperationalEvent> */
    public function map(NormalizedPayload $payload): array;
}
