<?php

namespace App\Integrations\Healthcare\DTO;

final readonly class ReplayRequest
{
    /** @param list<int> $canonicalEventIds */
    public function __construct(
        public array $canonicalEventIds = [],
        public array $scope = [],
        public bool $force = false,
    ) {}
}
