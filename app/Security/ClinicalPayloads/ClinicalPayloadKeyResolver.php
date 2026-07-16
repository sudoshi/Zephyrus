<?php

namespace App\Security\ClinicalPayloads;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;
use App\Security\Secrets\SecretProviderRegistry;

final class ClinicalPayloadKeyResolver
{
    public function __construct(private readonly SecretProviderRegistry $secrets) {}

    public function current(): ResolvedPayloadKey
    {
        $reference = trim((string) config('clinical-payloads.key_reference'));
        if ($reference === '') {
            throw new ClinicalPayloadException('clinical_payload_key_reference_required');
        }

        return $this->resolve($reference);
    }

    public function resolve(string $reference, ?string $expectedProviderVersion = null): ResolvedPayloadKey
    {
        try {
            $resolved = $this->secrets->resolve($reference);
        } catch (IntegrationCredentialException) {
            throw new ClinicalPayloadException('clinical_payload_key_unavailable');
        }
        $providerVersion = trim((string) $resolved->providerVersion);
        if ($providerVersion === '') {
            throw new ClinicalPayloadException('clinical_payload_key_version_required');
        }
        if ($expectedProviderVersion !== null && ! hash_equals($expectedProviderVersion, $providerVersion)) {
            throw new ClinicalPayloadException('clinical_payload_key_version_mismatch');
        }
        $value = trim($resolved->value());
        if (! str_starts_with($value, 'base64:')) {
            throw new ClinicalPayloadException('clinical_payload_key_encoding_invalid');
        }
        $key = base64_decode(substr($value, 7), true);
        if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new ClinicalPayloadException('clinical_payload_key_length_invalid');
        }

        return new ResolvedPayloadKey(
            $key,
            $reference,
            $resolved->providerScheme,
            $providerVersion,
        );
    }
}
