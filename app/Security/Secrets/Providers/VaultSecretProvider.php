<?php

namespace App\Security\Secrets\Providers;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;
use App\Security\Secrets\ResolvedSecret;
use App\Security\Secrets\SecretProvider;
use App\Security\Secrets\SecretReferenceUri;
use App\Security\Secrets\SecretValueSelector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

final class VaultSecretProvider implements SecretProvider
{
    public function __construct(private readonly SecretValueSelector $selector) {}

    public function scheme(): string
    {
        return 'vault';
    }

    public function enabled(): bool
    {
        return (bool) config('integrations.secret_providers.vault.enabled', false)
            && filled(config('integrations.secret_providers.vault.base_url'))
            && filled(config('integrations.secret_providers.vault.token'));
    }

    public function validateReference(SecretReferenceUri $reference): void
    {
        if ($reference->host === null || preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $reference->host) !== 1) {
            throw new IntegrationCredentialException('credential_vault_mount_invalid');
        }
        $mounts = config('integrations.secret_providers.vault.allowed_mounts', []);
        if (! is_array($mounts) || ! in_array($reference->host, $mounts, true)) {
            throw new IntegrationCredentialException('credential_vault_mount_not_allowed');
        }
        if (preg_match('#^[A-Za-z0-9_.@/-]{1,190}$#', $reference->pathWithoutLeadingSlash()) !== 1) {
            throw new IntegrationCredentialException('credential_vault_path_invalid');
        }
        $this->baseUrl();
    }

    public function resolve(SecretReferenceUri $reference): ResolvedSecret
    {
        $this->validateReference($reference);
        if (! $this->enabled()) {
            throw new IntegrationCredentialException('credential_provider_not_configured');
        }

        $mount = $reference->host;
        $path = $reference->pathWithoutLeadingSlash();
        $kvVersion = (int) config('integrations.secret_providers.vault.kv_version', 2);
        $apiPath = $kvVersion === 2 ? "{$mount}/data/{$path}" : "{$mount}/{$path}";
        $headers = ['X-Vault-Token' => (string) config('integrations.secret_providers.vault.token')];
        if (filled(config('integrations.secret_providers.vault.namespace'))) {
            $headers['X-Vault-Namespace'] = (string) config('integrations.secret_providers.vault.namespace');
        }

        $response = Http::withHeaders($headers)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout((int) config('integrations.health_timeout_seconds', 10))
            ->withOptions(['allow_redirects' => false])
            ->get($this->baseUrl().'/v1/'.$apiPath);
        if ($response->redirect()) {
            throw new IntegrationCredentialException('credential_vault_redirect_rejected');
        }
        if (! $response->successful()) {
            throw new IntegrationCredentialException('credential_vault_http_'.$response->status());
        }
        $payload = $response->json();
        $secretMap = $kvVersion === 2 ? data_get($payload, 'data.data') : data_get($payload, 'data');
        if (! is_array($secretMap)) {
            throw new IntegrationCredentialException('credential_vault_payload_invalid');
        }
        $metadata = $kvVersion === 2 && is_array(data_get($payload, 'data.metadata'))
            ? data_get($payload, 'data.metadata')
            : [];
        if (($metadata['destroyed'] ?? false) === true || filled($metadata['deletion_time'] ?? null)) {
            throw new IntegrationCredentialException('credential_vault_version_unavailable');
        }
        $leaseDuration = max(0, (int) ($payload['lease_duration'] ?? 0));

        return new ResolvedSecret(
            $this->selector->fromMap($secretMap, $reference->selector),
            $this->scheme(),
            isset($metadata['version']) ? (string) $metadata['version'] : null,
            $leaseDuration > 0 ? CarbonImmutable::now()->addSeconds($leaseDuration) : null,
            metadata: ['renewable' => (bool) ($payload['renewable'] ?? false)],
        );
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('integrations.secret_providers.vault.base_url'), '/');
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! filled($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new IntegrationCredentialException('credential_vault_endpoint_invalid');
        }

        return $url;
    }
}
