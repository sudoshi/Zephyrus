<?php

namespace App\Integrations\Healthcare\DTO;

final readonly class PollRequest
{
    public function __construct(
        public array $messages = [],
        public ?string $cursor = null,
        public array $scope = [],
    ) {}
}
