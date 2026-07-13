<?php

namespace App\Security\Secrets\Providers;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;
use App\Security\Secrets\ResolvedSecret;
use App\Security\Secrets\SecretProvider;
use App\Security\Secrets\SecretReferenceUri;

final class FileSecretProvider implements SecretProvider
{
    public function scheme(): string
    {
        return 'file';
    }

    public function enabled(): bool
    {
        return (bool) config('integrations.secret_providers.file.enabled', true);
    }

    public function validateReference(SecretReferenceUri $reference): void
    {
        if ($reference->host !== null || $reference->selector !== null) {
            throw new IntegrationCredentialException('credential_file_reference_invalid');
        }

        $root = rtrim((string) config('integrations.secret_file_root'), DIRECTORY_SEPARATOR);
        if ($root === '' || ! str_starts_with($reference->path, $root.DIRECTORY_SEPARATOR)) {
            throw new IntegrationCredentialException('credential_reference_outside_root');
        }
    }

    public function resolve(SecretReferenceUri $reference): ResolvedSecret
    {
        $this->validateReference($reference);
        $root = realpath((string) config('integrations.secret_file_root'));
        $path = realpath($reference->path);
        if ($root === false
            || $path === false
            || ! str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            throw new IntegrationCredentialException('credential_reference_outside_root');
        }
        if (! is_file($path) || ! is_readable($path) || is_link($reference->path)) {
            throw new IntegrationCredentialException('credential_file_unreadable');
        }

        $size = filesize($path);
        $maxBytes = (int) config('integrations.secret_max_bytes', 65_536);
        if ($size === false || $size < 1 || $size > $maxBytes) {
            throw new IntegrationCredentialException('credential_file_size_invalid');
        }

        $stat = stat($path);
        if (! is_array($stat)) {
            throw new IntegrationCredentialException('credential_file_metadata_unavailable');
        }
        $mode = ((int) $stat['mode']) & 0777;
        if (($mode & 0007) !== 0 || ($mode & 0030) !== 0) {
            throw new IntegrationCredentialException('credential_file_permissions_unsafe');
        }

        if (function_exists('posix_geteuid')) {
            $allowedOwners = array_unique([0, posix_geteuid()]);
            if (! in_array((int) $stat['uid'], $allowedOwners, true)) {
                throw new IntegrationCredentialException('credential_file_owner_unsafe');
            }
        }
        if (function_exists('posix_getegid')
            && ($mode & 0040) !== 0
            && (int) $stat['gid'] !== posix_getegid()) {
            throw new IntegrationCredentialException('credential_file_group_unsafe');
        }

        $value = file_get_contents($path);
        if (! is_string($value) || $value === '') {
            throw new IntegrationCredentialException('credential_file_empty');
        }

        $version = hash('sha256', implode(':', [
            (string) $stat['dev'],
            (string) $stat['ino'],
            (string) $stat['size'],
            (string) $stat['mtime'],
        ]));

        return new ResolvedSecret(
            $value,
            $this->scheme(),
            $version,
            metadata: ['bytes' => (int) $size],
        );
    }
}
