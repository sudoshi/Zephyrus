<?php

namespace App\Security\Secrets;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;

final class SecretValueSelector
{
    /** @param array<string, mixed> $payload */
    public function fromMap(array $payload, ?string $selector): string
    {
        if ($selector !== null) {
            $value = data_get($payload, $selector);
            if (! is_string($value) || $value === '') {
                throw new IntegrationCredentialException('credential_secret_field_missing');
            }

            return $value;
        }

        foreach (['value', 'secret', 'private_key', 'certificate'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && $payload[$key] !== '') {
                return $payload[$key];
            }
        }

        $strings = array_values(array_filter($payload, fn (mixed $value): bool => is_string($value) && $value !== ''));
        if (count($strings) !== 1) {
            throw new IntegrationCredentialException('credential_secret_field_ambiguous');
        }

        return $strings[0];
    }

    public function fromScalarOrJson(string $value, ?string $selector): string
    {
        if ($selector === null) {
            if ($value === '') {
                throw new IntegrationCredentialException('credential_secret_empty');
            }

            return $value;
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            throw new IntegrationCredentialException('credential_secret_not_json');
        }

        return $this->fromMap($decoded, $selector);
    }
}
