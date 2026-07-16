<?php

namespace App\Security\Secrets\Providers;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;
use App\Security\Secrets\ResolvedSecret;
use App\Security\Secrets\SecretProvider;
use App\Security\Secrets\SecretReferenceUri;
use App\Security\Secrets\SecretValueSelector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

final class AzureKeyVaultProvider implements SecretProvider
{
    private ?string $cachedAccessToken = null;

    public function __construct(private readonly SecretValueSelector $selector) {}

    public function scheme(): string
    {
        return 'azure-keyvault';
    }

    public function enabled(): bool
    {
        $tokenConfigured = filled(config('integrations.secret_providers.azure-keyvault.access_token'));
        $servicePrincipalConfigured = filled(config('integrations.secret_providers.azure-keyvault.tenant_id'))
            && filled(config('integrations.secret_providers.azure-keyvault.client_id'))
            && filled(config('integrations.secret_providers.azure-keyvault.client_secret'));

        return (bool) config('integrations.secret_providers.azure-keyvault.enabled', false)
            && ($tokenConfigured || $servicePrincipalConfigured);
    }

    public function validateReference(SecretReferenceUri $reference): void
    {
        if ($reference->host === null || preg_match('/^[a-z][a-z0-9-]{1,22}[a-z0-9]$/', $reference->host) !== 1) {
            throw new IntegrationCredentialException('credential_azure_vault_invalid');
        }
        $vaults = config('integrations.secret_providers.azure-keyvault.allowed_vaults', []);
        if (! is_array($vaults) || ! in_array($reference->host, $vaults, true)) {
            throw new IntegrationCredentialException('credential_azure_vault_not_allowed');
        }
        $segments = explode('/', $reference->pathWithoutLeadingSlash());
        if (count($segments) < 1
            || count($segments) > 2
            || preg_match('/^[A-Za-z0-9-]{1,127}$/', $segments[0]) !== 1
            || (isset($segments[1]) && preg_match('/^[A-Fa-f0-9]{32}$/', $segments[1]) !== 1)) {
            throw new IntegrationCredentialException('credential_azure_secret_path_invalid');
        }
        $this->vaultDnsSuffix();
    }

    public function resolve(SecretReferenceUri $reference): ResolvedSecret
    {
        $this->validateReference($reference);
        if (! $this->enabled()) {
            throw new IntegrationCredentialException('credential_provider_not_configured');
        }
        $segments = explode('/', $reference->pathWithoutLeadingSlash());
        $secret = $segments[0];
        $version = $segments[1] ?? '';
        $url = 'https://'.$reference->host.'.'.$this->vaultDnsSuffix().'/secrets/'
            .rawurlencode($secret).'/'.rawurlencode($version).'?api-version=7.4';
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout((int) config('integrations.health_timeout_seconds', 10))
            ->withOptions(['allow_redirects' => false])
            ->get($url);
        if ($response->redirect()) {
            throw new IntegrationCredentialException('credential_azure_redirect_rejected');
        }
        if (! $response->successful()) {
            throw new IntegrationCredentialException('credential_azure_http_'.$response->status());
        }
        $payload = $response->json();
        $value = is_array($payload) ? ($payload['value'] ?? null) : null;
        if (! is_string($value) || $value === '') {
            throw new IntegrationCredentialException('credential_azure_payload_invalid');
        }
        if (data_get($payload, 'attributes.enabled') === false) {
            throw new IntegrationCredentialException('credential_azure_secret_disabled');
        }
        $notBefore = data_get($payload, 'attributes.nbf');
        if (is_numeric($notBefore) && CarbonImmutable::createFromTimestampUTC((int) $notBefore)->isFuture()) {
            throw new IntegrationCredentialException('credential_azure_secret_not_yet_valid');
        }
        $expires = data_get($payload, 'attributes.exp');
        $expiresAt = is_numeric($expires) ? CarbonImmutable::createFromTimestampUTC((int) $expires) : null;
        if ($expiresAt?->isPast()) {
            throw new IntegrationCredentialException('credential_azure_secret_expired');
        }
        $identifier = is_array($payload) && is_string($payload['id'] ?? null) ? $payload['id'] : null;
        $resolvedVersion = $identifier !== null ? basename($identifier) : ($version !== '' ? $version : null);

        return new ResolvedSecret(
            $this->selector->fromScalarOrJson($value, $reference->selector),
            $this->scheme(),
            $resolvedVersion,
            expiresAt: $expiresAt,
            metadata: [
                'contentTypeConfigured' => is_array($payload) && filled($payload['contentType'] ?? null),
                'managed' => (bool) (data_get($payload, 'attributes.managed') ?? false),
            ],
        );
    }

    private function accessToken(): string
    {
        $configured = (string) config('integrations.secret_providers.azure-keyvault.access_token');
        if ($configured !== '') {
            return $configured;
        }
        if ($this->cachedAccessToken !== null) {
            return $this->cachedAccessToken;
        }

        $tenant = (string) config('integrations.secret_providers.azure-keyvault.tenant_id');
        if (preg_match('/^[A-Za-z0-9.-]{3,120}$/', $tenant) !== 1) {
            throw new IntegrationCredentialException('credential_azure_tenant_invalid');
        }
        $authority = rtrim((string) config(
            'integrations.secret_providers.azure-keyvault.authority_host',
            'https://login.microsoftonline.com',
        ), '/');
        $parts = parse_url($authority);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! filled($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new IntegrationCredentialException('credential_azure_authority_invalid');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout((int) config('integrations.health_timeout_seconds', 10))
            ->withOptions(['allow_redirects' => false])
            ->post($authority.'/'.rawurlencode($tenant).'/oauth2/v2.0/token', [
                'grant_type' => 'client_credentials',
                'client_id' => (string) config('integrations.secret_providers.azure-keyvault.client_id'),
                'client_secret' => (string) config('integrations.secret_providers.azure-keyvault.client_secret'),
                'scope' => 'https://vault.azure.net/.default',
            ]);
        if ($response->redirect()) {
            throw new IntegrationCredentialException('credential_azure_token_redirect_rejected');
        }
        $token = $response->successful() ? $response->json('access_token') : null;
        if (! is_string($token) || $token === '') {
            throw new IntegrationCredentialException('credential_azure_token_unavailable');
        }
        $this->cachedAccessToken = $token;

        return $token;
    }

    private function vaultDnsSuffix(): string
    {
        $suffix = strtolower(trim((string) config(
            'integrations.secret_providers.azure-keyvault.vault_dns_suffix',
            'vault.azure.net',
        ), '.'));
        if (preg_match('/^[a-z0-9.-]{3,190}$/', $suffix) !== 1) {
            throw new IntegrationCredentialException('credential_azure_dns_suffix_invalid');
        }

        return $suffix;
    }
}
