<?php

namespace App\Security\Network;

use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;

final readonly class GuardedConnection
{
    /** @param list<string> $addresses */
    public function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public array $addresses,
        public ?int $networkRouteId,
        public ?string $proxyUrl = null,
        public array $proxyAddresses = [],
        private ?string $clientCertificate = null,
        private ?string $clientPrivateKey = null,
    ) {}

    /** @return array<string, mixed> */
    public function httpOptions(): array
    {
        if ($this->addresses === []) {
            throw new IntegrationProtocolException('network_dns_resolution_required');
        }
        if (! defined('CURLOPT_RESOLVE')) {
            throw new IntegrationProtocolException('network_dns_pinning_unavailable');
        }
        $resolve = [$this->resolveEntry($this->host, $this->port, $this->addresses)];
        if ($this->proxyUrl !== null) {
            $proxyHost = (string) parse_url($this->proxyUrl, PHP_URL_HOST);
            $proxyPort = (int) (parse_url($this->proxyUrl, PHP_URL_PORT) ?: 443);
            if ($proxyHost === '' || $this->proxyAddresses === []) {
                throw new IntegrationProtocolException('network_proxy_dns_resolution_required');
            }
            $resolve[] = $this->resolveEntry($proxyHost, $proxyPort, $this->proxyAddresses);
        }

        $curl = [CURLOPT_RESOLVE => $resolve];
        if (($this->clientCertificate === null) !== ($this->clientPrivateKey === null)) {
            throw new IntegrationProtocolException('network_mtls_material_incomplete');
        }
        if ($this->clientCertificate !== null && $this->clientPrivateKey !== null) {
            if (! defined('CURLOPT_SSLCERT_BLOB') || ! defined('CURLOPT_SSLKEY_BLOB')) {
                throw new IntegrationProtocolException('network_mtls_blob_options_unavailable');
            }
            $curl[CURLOPT_SSLCERT_BLOB] = $this->clientCertificate;
            $curl[CURLOPT_SSLKEY_BLOB] = $this->clientPrivateKey;
        }

        return array_filter([
            'allow_redirects' => false,
            'proxy' => $this->proxyUrl,
            'curl' => $curl,
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @param list<string> $addresses */
    private function resolveEntry(string $host, int $port, array $addresses): string
    {
        sort($addresses, SORT_STRING);
        $address = $addresses[0];
        if (str_contains($address, ':')) {
            $address = '['.$address.']';
        }

        return "{$host}:{$port}:{$address}";
    }
}
