<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;

class IntegrationSecretReferenceResolver
{
    public function resolvable(?string $reference): bool
    {
        try {
            $this->filePath((string) $reference, false);

            return true;
        } catch (IntegrationCredentialException) {
            return false;
        }
    }

    public function resolve(string $reference): string
    {
        $path = $this->filePath($reference, true);
        $value = file_get_contents($path);
        if (! is_string($value) || trim($value) === '') {
            throw new IntegrationCredentialException('credential_file_empty');
        }

        return $value;
    }

    private function filePath(string $reference, bool $read): string
    {
        $parts = parse_url($reference);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'file') {
            throw new IntegrationCredentialException('credential_provider_not_available');
        }
        if (filled($parts['host'] ?? null) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new IntegrationCredentialException('credential_reference_invalid');
        }

        $root = realpath((string) config('integrations.secret_file_root'));
        $path = realpath(rawurldecode((string) ($parts['path'] ?? '')));
        if ($root === false || $path === false || ! str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            throw new IntegrationCredentialException('credential_reference_outside_root');
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new IntegrationCredentialException('credential_file_unreadable');
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > 65_536) {
            throw new IntegrationCredentialException('credential_file_size_invalid');
        }
        $permissions = fileperms($path);
        if ($permissions !== false && ($permissions & 0x0007) !== 0) {
            throw new IntegrationCredentialException('credential_file_permissions_unsafe');
        }
        if ($read && ! is_readable($path)) {
            throw new IntegrationCredentialException('credential_file_unreadable');
        }

        return $path;
    }
}
