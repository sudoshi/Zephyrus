<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SecretReference implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $parts = parse_url($value);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $allowed = config('integrations.credential_reference_schemes', []);
        $hasLocation = is_array($parts)
            && (filled($parts['host'] ?? null) || filled($parts['path'] ?? null));

        if (! in_array($scheme, $allowed, true) || ! $hasLocation) {
            $fail('Credential values must be references to an approved secret provider.');

            return;
        }

        if ($scheme === 'file') {
            $path = (string) ($parts['path'] ?? '');
            $root = rtrim((string) config('integrations.secret_file_root'), '/');
            if (filled($parts['host'] ?? null)
                || $path === ''
                || ! str_starts_with($path, $root.'/')
                || str_contains($path, '/../')
                || str_ends_with($path, '/..')) {
                $fail('File credential references must point beneath the configured integration secret root.');

                return;
            }
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            $fail('Credential references cannot contain embedded credentials, query strings, or fragments.');
        }
    }
}
