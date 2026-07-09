<?php

namespace App\Security\Network;

class IntegrationUrlPolicy
{
    public function __construct(private readonly DnsResolver $dns) {}

    public function assertSafe(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new UnsafeIntegrationUrl('The endpoint URL must include a scheme and host.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ((bool) config('integrations.network.require_https', true) && $scheme !== 'https') {
            throw new UnsafeIntegrationUrl('Integration endpoints must use HTTPS.');
        }
        if (! in_array($scheme, ['https', 'http'], true)) {
            throw new UnsafeIntegrationUrl('The endpoint URL scheme is not supported.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeIntegrationUrl('Endpoint URLs cannot contain embedded credentials.');
        }
        if (isset($parts['fragment'])) {
            throw new UnsafeIntegrationUrl('Endpoint URLs cannot contain fragments.');
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (! preg_match('/^[a-z0-9.-]+$/', $host)) {
            throw new UnsafeIntegrationUrl('The endpoint host must use an ASCII DNS name or IP address.');
        }
        if ($this->blockedHostname($host)) {
            throw new UnsafeIntegrationUrl('The endpoint host is not permitted.');
        }
        if (! $this->hostAllowed($host)) {
            throw new UnsafeIntegrationUrl('The endpoint host is not in the integration allowlist.');
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port = (int) ($parts['port'] ?? $defaultPort);
        $allowedPorts = array_map('intval', config('integrations.network.allowed_ports', [443]));
        if (! in_array($port, $allowedPorts, true)) {
            throw new UnsafeIntegrationUrl('The endpoint port is not permitted.');
        }

        $addresses = $this->dns->resolve($host);
        if ($addresses === [] && (bool) config('integrations.network.require_dns_resolution', true)) {
            throw new UnsafeIntegrationUrl('The endpoint host could not be resolved.');
        }
        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                throw new UnsafeIntegrationUrl('The endpoint resolves to a private or reserved network address.');
            }
        }
    }

    /** @param list<string> $redirectUrls */
    public function assertSafeRedirectChain(string $originalUrl, array $redirectUrls): void
    {
        $this->assertSafe($originalUrl);
        if (count($redirectUrls) > (int) config('integrations.network.max_redirects', 3)) {
            throw new UnsafeIntegrationUrl('The endpoint exceeded the redirect limit.');
        }

        $originalHost = strtolower((string) parse_url($originalUrl, PHP_URL_HOST));
        foreach ($redirectUrls as $redirectUrl) {
            $this->assertSafe($redirectUrl);
            $redirectHost = strtolower((string) parse_url($redirectUrl, PHP_URL_HOST));
            if (! (bool) config('integrations.network.allow_cross_host_redirects', false) && $redirectHost !== $originalHost) {
                throw new UnsafeIntegrationUrl('Cross-host redirects are not permitted.');
            }
        }
    }

    private function hostAllowed(string $host): bool
    {
        $allowedHosts = config('integrations.network.allowed_hosts', []);
        if (! is_array($allowedHosts) || $allowedHosts === []) {
            return false;
        }

        foreach ($allowedHosts as $pattern) {
            $pattern = strtolower(rtrim(trim((string) $pattern), '.'));
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

    private function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
