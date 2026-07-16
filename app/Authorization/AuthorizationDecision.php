<?php

namespace App\Authorization;

final readonly class AuthorizationDecision
{
    /**
     * @param  list<string>  $effectiveRoles
     */
    public function __construct(
        public bool $allowed,
        public string $reason,
        public Capability $capability,
        public ?AuthorizationScope $scope,
        public array $effectiveRoles,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reason' => $this->reason,
            'capability' => $this->capability->value,
            'scope' => $this->scope?->toArray(),
            'effective_roles' => $this->effectiveRoles,
        ];
    }
}
