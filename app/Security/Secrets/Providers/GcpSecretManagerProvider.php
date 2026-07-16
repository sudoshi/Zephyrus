<?php

namespace App\Security\Secrets\Providers;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;
use App\Security\Secrets\ResolvedSecret;
use App\Security\Secrets\SecretProvider;
use App\Security\Secrets\SecretReferenceUri;
use App\Security\Secrets\SecretValueSelector;
use Illuminate\Support\Facades\Http;

final class GcpSecretManagerProvider implements SecretProvider
{
    private ?string $cachedAccessToken = null;

    public function __construct(private readonly SecretValueSelector $selector) {}

    public function scheme(): string
    {
        return 'gcp-secretmanager';
    }

    public function enabled(): bool
    {
        return (bool) config('integrations.secret_providers.gcp-secretmanager.enabled', false)
            && (filled(config('integrations.secret_providers.gcp-secretmanager.access_token'))
                || filled(config('integrations.secret_providers.gcp-secretmanager.credentials_file')));
    }

    public function validateReference(SecretReferenceUri $reference): void
    {
        if ($reference->host === null || preg_match('/^[a-z][a-z0-9-]{4,62}$/', $reference->host) !== 1) {
            throw new IntegrationCredentialException('credential_gcp_project_invalid');
        }
        $projects = config('integrations.secret_providers.gcp-secretmanager.allowed_projects', []);
        if (! is_array($projects) || ! in_array($reference->host, $projects, true)) {
            throw new IntegrationCredentialException('credential_gcp_project_not_allowed');
        }
        $segments = explode('/', $reference->pathWithoutLeadingSlash());
        if (count($segments) < 1
            || count($segments) > 2
            || preg_match('/^[A-Za-z0-9_-]{1,255}$/', $segments[0]) !== 1
            || (isset($segments[1]) && preg_match('/^(?:latest|[1-9][0-9]{0,18})$/', $segments[1]) !== 1)) {
            throw new IntegrationCredentialException('credential_gcp_secret_path_invalid');
        }
        $this->apiBaseUrl();
    }

    public function resolve(SecretReferenceUri $reference): ResolvedSecret
    {
        $this->validateReference($reference);
        if (! $this->enabled()) {
            throw new IntegrationCredentialException('credential_provider_not_configured');
        }
        $segments = explode('/', $reference->pathWithoutLeadingSlash());
        $secret = $segments[0];
        $version = $segments[1] ?? 'latest';
        $url = $this->apiBaseUrl().'/v1/projects/'.rawurlencode((string) $reference->host)
            .'/secrets/'.rawurlencode($secret).'/versions/'.rawurlencode($version).':access';
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout((int) config('integrations.health_timeout_seconds', 10))
            ->withOptions(['allow_redirects' => false])
            ->get($url);
        if ($response->redirect()) {
            throw new IntegrationCredentialException('credential_gcp_redirect_rejected');
        }
        if (! $response->successful()) {
            throw new IntegrationCredentialException('credential_gcp_http_'.$response->status());
        }
        $payload = $response->json();
        $encoded = is_array($payload) ? data_get($payload, 'payload.data') : null;
        $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (! is_string($decoded) || $decoded === '') {
            throw new IntegrationCredentialException('credential_gcp_payload_invalid');
        }
        $expectedCrc32c = is_array($payload) ? data_get($payload, 'payload.dataCrc32c') : null;
        if ($expectedCrc32c !== null
            && (! is_numeric($expectedCrc32c)
                || ! hash_equals((string) $expectedCrc32c, (string) hexdec(hash('crc32c', $decoded))))) {
            throw new IntegrationCredentialException('credential_gcp_crc32c_mismatch');
        }
        $name = is_array($payload) && is_string($payload['name'] ?? null) ? $payload['name'] : null;
        $resolvedVersion = $name !== null ? basename($name) : $version;

        return new ResolvedSecret(
            $this->selector->fromScalarOrJson($decoded, $reference->selector),
            $this->scheme(),
            $resolvedVersion,
            metadata: [
                'crc32cPresent' => is_array($payload) && filled(data_get($payload, 'payload.dataCrc32c')),
            ],
        );
    }

    private function accessToken(): string
    {
        $configured = (string) config('integrations.secret_providers.gcp-secretmanager.access_token');
        if ($configured !== '') {
            return $configured;
        }
        if ($this->cachedAccessToken !== null) {
            return $this->cachedAccessToken;
        }

        $credentials = $this->serviceAccountCredentials();
        $now = time();
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/cloud-platform',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 300,
        ], JSON_THROW_ON_ERROR));
        $signingInput = $header.'.'.$claims;
        $key = openssl_pkey_get_private($credentials['private_key']);
        if ($key === false) {
            throw new IntegrationCredentialException('credential_gcp_private_key_invalid');
        }
        $signature = '';
        if (! openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new IntegrationCredentialException('credential_gcp_assertion_signing_failed');
        }
        $assertion = $signingInput.'.'.$this->base64Url($signature);
        $response = Http::asForm()
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout((int) config('integrations.health_timeout_seconds', 10))
            ->withOptions(['allow_redirects' => false])
            ->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);
        if ($response->redirect()) {
            throw new IntegrationCredentialException('credential_gcp_token_redirect_rejected');
        }
        $token = $response->successful() ? $response->json('access_token') : null;
        if (! is_string($token) || $token === '') {
            throw new IntegrationCredentialException('credential_gcp_token_unavailable');
        }
        $this->cachedAccessToken = $token;

        return $token;
    }

    /** @return array{client_email: string, private_key: string} */
    private function serviceAccountCredentials(): array
    {
        $configuredPath = (string) config('integrations.secret_providers.gcp-secretmanager.credentials_file');
        $root = realpath((string) config('integrations.secret_file_root'));
        $path = realpath($configuredPath);
        if ($root === false
            || $path === false
            || ! str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
            || ! is_file($path)
            || ! is_readable($path)
            || is_link($configuredPath)) {
            throw new IntegrationCredentialException('credential_gcp_bootstrap_file_invalid');
        }
        $stat = stat($path);
        if (! is_array($stat)) {
            throw new IntegrationCredentialException('credential_gcp_bootstrap_file_invalid');
        }
        $mode = ((int) $stat['mode']) & 0777;
        if (($mode & 0007) !== 0 || ($mode & 0030) !== 0) {
            throw new IntegrationCredentialException('credential_gcp_bootstrap_permissions_unsafe');
        }
        if (function_exists('posix_geteuid')
            && ! in_array((int) $stat['uid'], [0, posix_geteuid()], true)) {
            throw new IntegrationCredentialException('credential_gcp_bootstrap_owner_unsafe');
        }
        if (function_exists('posix_getegid')
            && ($mode & 0040) !== 0
            && (int) $stat['gid'] !== posix_getegid()) {
            throw new IntegrationCredentialException('credential_gcp_bootstrap_group_unsafe');
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > (int) config('integrations.secret_max_bytes', 65_536)) {
            throw new IntegrationCredentialException('credential_gcp_bootstrap_size_invalid');
        }
        $contents = file_get_contents($path);
        $credentials = is_string($contents) ? json_decode($contents, true) : null;
        if (! is_array($credentials)
            || ($credentials['type'] ?? null) !== 'service_account'
            || ! is_string($credentials['client_email'] ?? null)
            || ! is_string($credentials['private_key'] ?? null)) {
            throw new IntegrationCredentialException('credential_gcp_bootstrap_payload_invalid');
        }

        return [
            'client_email' => $credentials['client_email'],
            'private_key' => $credentials['private_key'],
        ];
    }

    private function apiBaseUrl(): string
    {
        $url = rtrim((string) config(
            'integrations.secret_providers.gcp-secretmanager.api_base_url',
            'https://secretmanager.googleapis.com',
        ), '/');
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! filled($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new IntegrationCredentialException('credential_gcp_endpoint_invalid');
        }

        return $url;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
