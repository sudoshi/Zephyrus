<?php

namespace App\Contracts;

use App\Auth\Drivers\AuthDriverResult;

interface AuthDriverInterface
{
    public function name(): string;

    /** @param array<string, mixed> $credentials */
    public function authenticate(array $credentials): AuthDriverResult;

    public function isAvailable(): bool;
}
