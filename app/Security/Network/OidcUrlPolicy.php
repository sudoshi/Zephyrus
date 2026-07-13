<?php

namespace App\Security\Network;

class OidcUrlPolicy
{
    public function __construct(private readonly DnsResolver $dns) {}

    public function assertSafeOutboundUrl(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            $this->reject('oidc_url_invalid', 'The OIDC URL must include a scheme and host.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ((bool) config('auth-drivers.oidc_network.require_https', true) && $scheme !== 'https') {
            $this->reject('oidc_https_required', 'OIDC endpoints must use HTTPS.');
        }
        if (! in_array($scheme, ['https', 'http'], true)) {
            $this->reject('oidc_scheme_rejected', 'The OIDC URL scheme is not supported.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            $this->reject('oidc_embedded_credentials', 'OIDC URLs cannot contain embedded credentials.');
        }
        if (isset($parts['fragment'])) {
            $this->reject('oidc_fragment_rejected', 'OIDC URLs cannot contain fragments.');
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (! preg_match('/^[a-z0-9.-]+$/', $host)) {
            $this->reject('oidc_host_invalid', 'The OIDC host must be an ASCII DNS name or IP address.');
        }
        if ($this->blockedHostname($host)) {
            $this->reject('oidc_host_blocked', 'The OIDC host is not permitted.');
        }
        if (! $this->hostAllowed($host)) {
            $this->reject('oidc_host_not_allowed', 'The OIDC host is not in the deployment allowlist.');
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port = (int) ($parts['port'] ?? $defaultPort);
        $allowedPorts = array_map('intval', (array) config('auth-drivers.oidc_network.allowed_ports', [443]));
        if (! in_array($port, $allowedPorts, true)) {
            $this->reject('oidc_port_not_allowed', 'The OIDC endpoint port is not permitted.');
        }

        $addresses = $this->dns->resolve($host);
        if ($addresses === [] && (bool) config('auth-drivers.oidc_network.require_dns_resolution', true)) {
            $this->reject('oidc_dns_unresolved', 'The OIDC host could not be resolved.');
        }
        foreach ($addresses as $address) {
            if (! $this->addressAllowed($address)) {
                $this->reject('oidc_address_blocked', 'The OIDC host resolves to a prohibited network address.');
            }
        }
    }

    /** @param array<string, mixed> $metadata */
    public function assertSafeDiscoveryMetadata(string $discoveryUrl, array $metadata): void
    {
        $this->assertSafeOutboundUrl($discoveryUrl);

        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
            $value = $metadata[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                $this->reject('oidc_metadata_malformed', "OIDC discovery is missing {$key}.");
            }
            $this->assertSafeOutboundUrl($value);
        }

        if ($this->origin($discoveryUrl) !== $this->origin((string) $metadata['issuer'])) {
            $this->reject('oidc_issuer_origin_mismatch', 'The OIDC issuer must share the discovery origin.');
        }
    }

    public function assertAllowedRedirectUri(string $uri): void
    {
        $parts = parse_url($uri);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            $this->reject('oidc_redirect_invalid', 'The OIDC redirect URI must be absolute.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            $this->reject('oidc_redirect_invalid', 'The OIDC redirect URI contains prohibited components.');
        }
        if ((bool) config('auth-drivers.oidc_network.require_https', true)
            && strtolower((string) $parts['scheme']) !== 'https') {
            $this->reject('oidc_redirect_https_required', 'The OIDC redirect URI must use HTTPS.');
        }

        $allowed = array_values(array_filter(array_map(
            static fn (mixed $value): string => is_string($value) ? trim($value) : '',
            (array) config('auth-drivers.oidc_network.allowed_redirect_uris', []),
        )));
        if (! in_array($uri, $allowed, true)) {
            $this->reject('oidc_redirect_not_allowed', 'The OIDC redirect URI is not deployment-approved.');
        }
    }

    /** @return list<string> */
    public function allowedHosts(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $host): string => is_string($host) ? trim($host) : '',
            (array) config('auth-drivers.oidc_network.allowed_hosts', []),
        )));
    }

    private function hostAllowed(string $host): bool
    {
        foreach ($this->allowedHosts() as $pattern) {
            $pattern = strtolower(rtrim($pattern, '.'));
            if ($pattern === $host) {
                return true;
            }
            if (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1))) {
                $prefix = substr($host, 0, -strlen(substr($pattern, 1)));
                if ($prefix !== '' && ! str_contains(rtrim($prefix, '.'), '..')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function blockedHostname(string $host): bool
    {
        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || $host === 'metadata.google.internal'
            || $host === 'instance-data.ec2.internal';
    }

    private function addressAllowed(string $address): bool
    {
        if (filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false) {
            return true;
        }

        return (bool) config('auth-drivers.oidc_network.allow_private_networks', false)
            && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function origin(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
        $port = parse_url($url, PHP_URL_PORT);
        $defaultPort = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.$host.':'.($port ?: $defaultPort);
    }

    private function reject(string $reason, string $message): never
    {
        throw new UnsafeOidcUrl($reason, $message);
    }
}
