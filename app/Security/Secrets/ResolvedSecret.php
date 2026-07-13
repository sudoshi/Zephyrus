<?php

namespace App\Security\Secrets;

use Carbon\CarbonImmutable;

final readonly class ResolvedSecret
{
    /** @param array<string, bool|int|string|null> $metadata */
    public function __construct(
        private string $value,
        public string $providerScheme,
        public ?string $providerVersion = null,
        public ?CarbonImmutable $leaseExpiresAt = null,
        public ?CarbonImmutable $expiresAt = null,
        public array $metadata = [],
    ) {}

    public function value(): string
    {
        return $this->value;
    }

    /** @return array<string, mixed> */
    public function safeMetadata(): array
    {
        return [
            'providerScheme' => $this->providerScheme,
            'providerVersion' => $this->providerVersion,
            'leaseExpiresAtIso' => $this->leaseExpiresAt?->toIso8601String(),
            'expiresAtIso' => $this->expiresAt?->toIso8601String(),
            'metadata' => $this->metadata,
        ];
    }
}
