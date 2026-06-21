<?php

namespace App\Auth\Drivers;

use App\Models\User;

final readonly class AuthDriverResult
{
    /** @param array<string, mixed> $providerClaims */
    public function __construct(
        public User $user,
        public string $driverName,
        public bool $mustChangePassword = false,
        public ?string $providerSubject = null,
        public array $providerClaims = [],
    ) {}
}
