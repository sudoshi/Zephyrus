<?php

namespace App\Security\ClinicalPayloads;

final readonly class ResolvedPayloadKey
{
    public function __construct(
        private string $key,
        public string $reference,
        public string $providerScheme,
        public string $providerVersion,
    ) {}

    public function value(): string
    {
        return $this->key;
    }
}
