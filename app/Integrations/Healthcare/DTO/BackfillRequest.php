<?php

namespace App\Integrations\Healthcare\DTO;

final readonly class BackfillRequest
{
    public function __construct(
        public array $messages = [],
        public array $scope = [],
        public ?string $cursor = null,
    ) {}
}
