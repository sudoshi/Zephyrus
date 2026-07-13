<?php

namespace App\Services\Auth\Oidc;

final readonly class ValidatedClaims
{
    /**
     * @param  list<string>  $groups
     * @param  list<string>  $amr
     */
    public function __construct(
        public string $sub,
        public string $email,
        public string $name,
        public array $groups,
        public ?int $authTime = null,
        public array $amr = [],
        public ?string $acr = null,
    ) {}
}
