<?php

namespace Tests\Support;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;
use App\Security\Secrets\ResolvedSecret;
use App\Security\Secrets\SecretProvider;
use App\Security\Secrets\SecretReferenceUri;

final readonly class InMemorySecretProvider implements SecretProvider
{
    public function __construct(private string $providerScheme) {}

    public function scheme(): string
    {
        return $this->providerScheme;
    }

    public function enabled(): bool
    {
        return true;
    }

    public function validateReference(SecretReferenceUri $reference): void
    {
        if ($reference->host === null || $reference->pathWithoutLeadingSlash() === '') {
            throw new IntegrationCredentialException('test_credential_reference_invalid');
        }
    }

    public function resolve(SecretReferenceUri $reference): ResolvedSecret
    {
        $this->validateReference($reference);

        $value = $reference->pathWithoutLeadingSlash() === 'clinical-payload-kek'
            ? 'base64:'.base64_encode(hash('sha256', 'zephyrus-test-clinical-payload-kek', true))
            : 'test-secret-value';

        return new ResolvedSecret(
            $value,
            $this->providerScheme,
            'test-version-1',
            metadata: ['testDouble' => true],
        );
    }
}
