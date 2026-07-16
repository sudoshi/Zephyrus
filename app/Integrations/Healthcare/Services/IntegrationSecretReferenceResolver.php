<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;
use App\Security\Secrets\ResolvedSecret;
use App\Security\Secrets\SecretProviderRegistry;

class IntegrationSecretReferenceResolver
{
    public function __construct(private readonly SecretProviderRegistry $providers) {}

    public function resolvable(?string $reference): bool
    {
        try {
            $this->providers->resolve((string) $reference);

            return true;
        } catch (IntegrationCredentialException) {
            return false;
        }
    }

    public function resolve(string $reference): string
    {
        return $this->resolveWithMetadata($reference)->value();
    }

    public function resolveWithMetadata(string $reference): ResolvedSecret
    {
        return $this->providers->resolve($reference);
    }
}
