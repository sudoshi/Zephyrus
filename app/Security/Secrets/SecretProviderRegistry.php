<?php

namespace App\Security\Secrets;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;

final class SecretProviderRegistry
{
    /** @var array<string, SecretProvider> */
    private array $providers = [];

    /** @param iterable<SecretProvider> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $scheme = strtolower($provider->scheme());
            if (isset($this->providers[$scheme])) {
                throw new \LogicException("Duplicate secret provider scheme: {$scheme}");
            }
            $this->providers[$scheme] = $provider;
        }
    }

    public function validate(string $reference): SecretReferenceUri
    {
        $parsed = SecretReferenceUri::parse($reference);
        $provider = $this->provider($parsed->scheme);
        $provider->validateReference($parsed);

        return $parsed;
    }

    public function resolve(string $reference): ResolvedSecret
    {
        $parsed = $this->validate($reference);
        $provider = $this->provider($parsed->scheme);
        if (! $provider->enabled()) {
            throw new IntegrationCredentialException('credential_provider_not_configured');
        }

        return $provider->resolve($parsed);
    }

    /** @return list<array{scheme: string, enabled: bool}> */
    public function capabilities(): array
    {
        return collect($this->providers)
            ->map(fn (SecretProvider $provider, string $scheme): array => [
                'scheme' => $scheme,
                'enabled' => $provider->enabled(),
            ])
            ->sortBy('scheme')
            ->values()
            ->all();
    }

    private function provider(string $scheme): SecretProvider
    {
        if (! isset($this->providers[$scheme])) {
            throw new IntegrationCredentialException('credential_provider_not_supported');
        }

        return $this->providers[$scheme];
    }
}
