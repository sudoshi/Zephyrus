<?php

namespace App\Rules;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;
use App\Security\Secrets\SecretProviderRegistry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SecretReference implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        try {
            app(SecretProviderRegistry::class)->validate($value);
        } catch (IntegrationCredentialException) {
            $fail('Credential values must be references to an approved secret provider.');
        }
    }
}
