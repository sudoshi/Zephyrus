<?php

namespace App\Integrations\Healthcare\Services;

use App\Security\Network\CidrMatcher;
use App\Security\Network\DnsResolver;
use App\Security\Network\IntegrationUrlPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class NetworkRouteService
{
    public function __construct(
        private readonly DnsResolver $dns,
        private readonly CidrMatcher $cidrs,
        private readonly IntegrationUrlPolicy $publicUrlPolicy,
        private readonly CredentialValidationService $credentials,
    ) {}

    /** @return list<array<string, mixed>> */
    public function routes(int $sourceId): array
    {
        $this->source($sourceId);

        return DB::table('integration.source_network_routes')
            ->where('source_id', $sourceId)
            ->orderBy('route_key')
            ->get()
            ->map(fn (object $route): array => $this->payload($route))
            ->all();
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function create(int $sourceId, array $input, ?int $actorUserId): array
    {
        $source = $this->assertMutable($sourceId);
        $normalized = $this->normalize($source, $input);
        if (DB::table('integration.source_network_routes')
            ->where('source_id', $sourceId)
            ->where('route_key', $normalized['route_key'])
            ->exists()) {
            throw ValidationException::withMessages(['route_key' => 'This network route key already exists for the source.']);
        }
        $this->assertEndpointAvailable($normalized['source_endpoint_id']);

        $routeId = (int) DB::table('integration.source_network_routes')->insertGetId([
            'route_uuid' => (string) Str::uuid7(),
            'source_id' => $sourceId,
            ...$normalized,
            'status' => 'unverified',
            'created_by_user_id' => $actorUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'source_network_route_id');

        $this->inspect($routeId, $actorUserId, persist: true);

        return $this->payload($this->route($routeId));
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function update(int $sourceId, int $routeId, array $input, ?int $actorUserId): array
    {
        $source = $this->assertMutable($sourceId);
        $route = $this->route($routeId, $sourceId);
        $current = [
            'route_key' => $route->route_key,
            'source_endpoint_id' => $route->source_endpoint_id,
            'transport' => $route->transport,
            'hostname' => $route->hostname,
            'port' => $route->port,
            'proxy_url' => $route->proxy_url,
            'dns_policy' => $route->dns_policy,
            'allowed_ip_cidrs' => $this->decodeList($route->allowed_ip_cidrs),
            'egress_policy_key' => $route->egress_policy_key,
            'mtls_required' => (bool) $route->mtls_required,
            'client_credential_id' => $route->client_credential_id,
            'server_name' => $route->server_name,
            'change_reason' => $input['change_reason'] ?? null,
        ];
        $normalized = $this->normalize($source, [...$current, ...$input]);
        if (DB::table('integration.source_network_routes')
            ->where('source_id', $sourceId)
            ->where('route_key', $normalized['route_key'])
            ->where('source_network_route_id', '<>', $routeId)
            ->exists()) {
            throw ValidationException::withMessages(['route_key' => 'This network route key already exists for the source.']);
        }
        $this->assertEndpointAvailable($normalized['source_endpoint_id'], $routeId);
        DB::table('integration.source_network_routes')->where('source_network_route_id', $routeId)->update([
            ...$normalized,
            'status' => 'unverified',
            'updated_at' => now(),
        ]);
        $this->inspect($routeId, $actorUserId, persist: true);

        return $this->payload($this->route($routeId));
    }

    /** @return array<string, mixed> */
    public function validate(int $sourceId, int $routeId, ?int $actorUserId): array
    {
        $this->route($routeId, $sourceId);
        $this->inspect($routeId, $actorUserId, persist: true);

        return $this->payload($this->route($routeId));
    }

    public function retire(int $sourceId, int $routeId, string $reason): void
    {
        $this->assertMutable($sourceId);
        $route = $this->route($routeId, $sourceId);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Route retirement requires a 10–500 character reason.']);
        }
        DB::table('integration.source_network_routes')
            ->where('source_network_route_id', $route->source_network_route_id)
            ->update(['status' => 'retired', 'change_reason' => $reason, 'updated_at' => now()]);
    }

    /** @return array{status: string, errorCode: ?string, addresses: list<string>, proxyAddresses: list<string>, policySha256: string} */
    public function inspect(
        int $routeId,
        ?int $actorUserId = null,
        bool $persist = false,
        ?CarbonImmutable $evaluatedFor = null,
    ): array {
        $evaluatedFor ??= CarbonImmutable::now();
        $route = $this->route($routeId);
        $status = 'validated';
        $errorCode = null;
        $addresses = [];
        $proxyAddresses = [];
        $policyHash = $this->policyHash($route);

        try {
            $addresses = $this->dns->resolve((string) $route->hostname);
            if ($addresses === []) {
                throw ValidationException::withMessages(['hostname' => 'network_dns_resolution_required']);
            }
            $allowedCidrs = $this->decodeList($route->allowed_ip_cidrs);
            foreach ($addresses as $address) {
                $public = $this->publicUrlPolicy->isPublicAddress($address);
                if ((string) $route->transport === 'public_internet' && ! $public) {
                    throw ValidationException::withMessages(['hostname' => 'network_public_address_required']);
                }
                if ((string) $route->dns_policy === 'public_only' && ! $public) {
                    throw ValidationException::withMessages(['hostname' => 'network_public_address_required']);
                }
                if ((string) $route->dns_policy === 'private_only' && $public) {
                    throw ValidationException::withMessages(['hostname' => 'network_private_address_required']);
                }
                if (in_array((string) $route->dns_policy, ['allowlist', 'private_only'], true)
                    && ! collect($allowedCidrs)->contains(fn (string $cidr): bool => $this->cidrs->contains($cidr, $address))) {
                    throw ValidationException::withMessages(['hostname' => 'network_address_not_allowlisted']);
                }
            }
            if (filled($route->proxy_url)) {
                try {
                    $proxy = $this->publicUrlPolicy->assertSafeAndResolve((string) $route->proxy_url);
                    $proxyAddresses = $proxy['addresses'];
                    if ($proxyAddresses === []) {
                        throw ValidationException::withMessages(['proxy_url' => 'network_proxy_dns_resolution_required']);
                    }
                } catch (Throwable $exception) {
                    if ($exception instanceof ValidationException) {
                        throw $exception;
                    }
                    throw ValidationException::withMessages(['proxy_url' => 'network_proxy_policy_failed']);
                }
            }
            if ((bool) $route->mtls_required) {
                $credential = $this->credentials->evaluate(
                    (int) $route->client_credential_id,
                    $evaluatedFor,
                    $actorUserId,
                    persist: $persist,
                );
                if ($credential['status'] !== 'ready') {
                    throw ValidationException::withMessages(['client_credential_id' => 'network_mtls_credential_not_ready']);
                }
            }
        } catch (Throwable $exception) {
            $status = 'blocked';
            $errorCode = $exception instanceof ValidationException
                ? (string) collect($exception->errors())->flatten()->first()
                : 'network_route_validation_failed';
        }

        if ($persist) {
            DB::transaction(function () use ($route, $status, $errorCode, $addresses, $proxyAddresses, $policyHash, $actorUserId): void {
                $observedAddresses = [...$addresses, ...$proxyAddresses];
                DB::table('integration.source_network_routes')
                    ->where('source_network_route_id', $route->source_network_route_id)
                    ->update(['status' => $status, 'updated_at' => now()]);
                DB::table('integration.network_route_observations')->insert([
                    'observation_uuid' => (string) Str::uuid7(),
                    'source_network_route_id' => $route->source_network_route_id,
                    'source_id' => $route->source_id,
                    'observation_status' => $status,
                    'address_count' => count($observedAddresses),
                    'address_fingerprints' => json_encode(array_map(
                        fn (string $address): string => hash('sha256', $address),
                        $observedAddresses,
                    ), JSON_THROW_ON_ERROR),
                    'policy_sha256' => $policyHash,
                    'error_code' => $errorCode,
                    'observed_by_user_id' => $actorUserId,
                    'observed_at' => now(),
                ]);
            });
        }

        return [
            'status' => $status,
            'errorCode' => $errorCode,
            'addresses' => $addresses,
            'proxyAddresses' => $proxyAddresses,
            'policySha256' => $policyHash,
        ];
    }

    public function assertUrlAllowed(int $sourceId, string $url): void
    {
        $parts = $this->urlParts($url);
        $route = DB::table('integration.source_network_routes')
            ->where('source_id', $sourceId)
            ->where('hostname', $parts['host'])
            ->where('port', $parts['port'])
            ->where('status', 'validated')
            ->first();
        if ($route !== null) {
            $inspection = $this->inspect((int) $route->source_network_route_id);
            if ($inspection['status'] === 'validated') {
                return;
            }
        }

        $this->publicUrlPolicy->assertSafe($url);
    }

    /** @return array<string, mixed> */
    private function normalize(object $source, array $input): array
    {
        $reason = trim((string) ($input['change_reason'] ?? ''));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['change_reason' => 'Network route changes require a 10–500 character reason.']);
        }
        $routeKey = trim((string) ($input['route_key'] ?? ''));
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,118}[a-z0-9]$/', $routeKey) !== 1) {
            throw ValidationException::withMessages(['route_key' => 'The route key must be a stable 3–120 character lowercase identifier.']);
        }
        $egressPolicyKey = trim((string) ($input['egress_policy_key'] ?? ''));
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,118}[a-z0-9]$/', $egressPolicyKey) !== 1) {
            throw ValidationException::withMessages(['egress_policy_key' => 'The egress policy key must be a stable 3–120 character lowercase identifier.']);
        }
        $host = strtolower(rtrim((string) ($input['hostname'] ?? ''), '.'));
        if (preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/', $host) !== 1
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || in_array($host, ['metadata.google.internal', 'instance-data.ec2.internal'], true)) {
            throw ValidationException::withMessages(['hostname' => 'The route hostname is invalid or reserved.']);
        }
        $port = (int) ($input['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            throw ValidationException::withMessages(['port' => 'The route port must be between 1 and 65535.']);
        }
        $dnsPolicy = (string) ($input['dns_policy'] ?? 'public_only');
        $transport = (string) ($input['transport'] ?? 'public_internet');
        if (! in_array($dnsPolicy, ['public_only', 'allowlist', 'private_only'], true)) {
            throw ValidationException::withMessages(['dns_policy' => 'The DNS policy is not supported.']);
        }
        if (! in_array($transport, ['public_internet', 'vpn', 'private_link', 'direct_connect', 'interface_engine'], true)) {
            throw ValidationException::withMessages(['transport' => 'The route transport is not supported.']);
        }
        $allowedCidrs = array_values(array_unique(array_map('trim', (array) ($input['allowed_ip_cidrs'] ?? []))));
        foreach ($allowedCidrs as $cidr) {
            if (! $this->cidrs->valid($cidr)) {
                throw ValidationException::withMessages(['allowed_ip_cidrs' => 'Every allowed IP range must be a valid IPv4 or IPv6 CIDR.']);
            }
            if (in_array($cidr, ['0.0.0.0/0', '::/0'], true)) {
                throw ValidationException::withMessages(['allowed_ip_cidrs' => 'Catch-all network ranges are not permitted.']);
            }
        }
        if (in_array($dnsPolicy, ['allowlist', 'private_only'], true) && $allowedCidrs === []) {
            throw ValidationException::withMessages(['allowed_ip_cidrs' => 'Allowlist and private DNS policies require at least one CIDR.']);
        }
        if ($transport === 'public_internet' && $dnsPolicy === 'private_only') {
            throw ValidationException::withMessages(['transport' => 'Public internet routes cannot use the private-only DNS policy.']);
        }
        if ($transport !== 'public_internet' && $dnsPolicy === 'public_only') {
            throw ValidationException::withMessages(['dns_policy' => 'Private transports require an explicit address allowlist or private-only policy.']);
        }
        $proxyUrl = filled($input['proxy_url'] ?? null) ? trim((string) $input['proxy_url']) : null;
        if ($proxyUrl !== null) {
            $proxyParts = parse_url($proxyUrl);
            if (! is_array($proxyParts)
                || ! in_array((string) ($proxyParts['path'] ?? ''), ['', '/'], true)
                || isset($proxyParts['query'])
                || isset($proxyParts['fragment'])) {
                throw ValidationException::withMessages(['proxy_url' => 'The proxy URL must be an HTTPS origin without a path, query, or fragment.']);
            }
            $this->publicUrlPolicy->assertSafe($proxyUrl);
        }

        $endpointId = isset($input['source_endpoint_id']) ? (int) $input['source_endpoint_id'] : null;
        if ($endpointId !== null) {
            $endpoint = DB::table('integration.source_endpoints')
                ->where('source_id', $source->source_id)
                ->where('source_endpoint_id', $endpointId)
                ->first();
            if ($endpoint === null) {
                throw ValidationException::withMessages(['source_endpoint_id' => 'The route endpoint does not belong to the selected source.']);
            }
            $endpointParts = $this->urlParts((string) $endpoint->url);
            if ($endpointParts['host'] !== $host || $endpointParts['port'] !== $port) {
                throw ValidationException::withMessages(['hostname' => 'The route host and port must match its selected endpoint.']);
            }
        }

        $mtlsRequired = (bool) ($input['mtls_required'] ?? false);
        $clientCredentialId = isset($input['client_credential_id']) ? (int) $input['client_credential_id'] : null;
        if ($mtlsRequired && $clientCredentialId === null) {
            throw ValidationException::withMessages(['client_credential_id' => 'mTLS routes require a client credential.']);
        }
        if ($clientCredentialId !== null && ! DB::table('integration.source_credentials')
            ->where('source_id', $source->source_id)
            ->where('source_credential_id', $clientCredentialId)
            ->exists()) {
            throw ValidationException::withMessages(['client_credential_id' => 'The route credential does not belong to the selected source.']);
        }
        $serverName = filled($input['server_name'] ?? null)
            ? strtolower(rtrim(trim((string) $input['server_name']), '.'))
            : $host;
        if (preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/', $serverName) !== 1) {
            throw ValidationException::withMessages(['server_name' => 'The TLS server name is invalid.']);
        }
        if ($serverName !== $host) {
            throw ValidationException::withMessages([
                'server_name' => 'The TLS server name must match the endpoint host until an explicit partner peer policy is implemented.',
            ]);
        }

        return [
            'route_key' => $routeKey,
            'source_endpoint_id' => $endpointId,
            'environment' => (string) $source->environment,
            'transport' => $transport,
            'hostname' => $host,
            'port' => $port,
            'proxy_url' => $proxyUrl,
            'dns_policy' => $dnsPolicy,
            'allowed_ip_cidrs' => json_encode($allowedCidrs, JSON_THROW_ON_ERROR),
            'egress_policy_key' => $egressPolicyKey,
            'mtls_required' => $mtlsRequired,
            'client_credential_id' => $clientCredentialId,
            'server_name' => $serverName,
            'change_reason' => $reason,
        ];
    }

    /** @return array{host: string, port: int} */
    private function urlParts(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! filled($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw ValidationException::withMessages(['url' => 'Network route URLs must use HTTPS without embedded credentials.']);
        }

        return [
            'host' => strtolower(rtrim((string) $parts['host'], '.')),
            'port' => (int) ($parts['port'] ?? 443),
        ];
    }

    private function assertMutable(int $sourceId): object
    {
        $source = $this->source($sourceId);
        if (in_array((string) $source->lifecycle_state, ['approved', 'scheduled', 'live', 'degraded', 'suspended', 'retired'], true)) {
            throw ValidationException::withMessages([
                'network_route' => 'Protected sources require a governed reconfiguration cycle before network authority changes.',
            ]);
        }

        return $source;
    }

    private function assertEndpointAvailable(?int $endpointId, ?int $exceptRouteId = null): void
    {
        if ($endpointId === null) {
            return;
        }
        $query = DB::table('integration.source_network_routes')
            ->where('source_endpoint_id', $endpointId)
            ->where('status', '<>', 'retired');
        if ($exceptRouteId !== null) {
            $query->where('source_network_route_id', '<>', $exceptRouteId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'source_endpoint_id' => 'This endpoint already has an active network authority route.',
            ]);
        }
    }

    private function source(int $sourceId): object
    {
        return DB::table('integration.sources')->where('source_id', $sourceId)->firstOrFail();
    }

    private function route(int $routeId, ?int $sourceId = null): object
    {
        $query = DB::table('integration.source_network_routes')->where('source_network_route_id', $routeId);
        if ($sourceId !== null) {
            $query->where('source_id', $sourceId);
        }

        return $query->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(object $route): array
    {
        $latest = DB::table('integration.network_route_observations')
            ->where('source_network_route_id', $route->source_network_route_id)
            ->orderByDesc('network_route_observation_id')
            ->first();

        return [
            'networkRouteId' => (int) $route->source_network_route_id,
            'routeUuid' => (string) $route->route_uuid,
            'sourceId' => (int) $route->source_id,
            'endpointId' => $route->source_endpoint_id !== null ? (int) $route->source_endpoint_id : null,
            'routeKey' => (string) $route->route_key,
            'environment' => (string) $route->environment,
            'transport' => (string) $route->transport,
            'hostname' => (string) $route->hostname,
            'port' => (int) $route->port,
            'proxyConfigured' => filled($route->proxy_url),
            'proxyOrigin' => filled($route->proxy_url) ? $this->origin((string) $route->proxy_url) : null,
            'dnsPolicy' => (string) $route->dns_policy,
            'allowedIpCidrs' => $this->decodeList($route->allowed_ip_cidrs),
            'egressPolicyKey' => (string) $route->egress_policy_key,
            'mtlsRequired' => (bool) $route->mtls_required,
            'clientCredentialId' => $route->client_credential_id !== null ? (int) $route->client_credential_id : null,
            'serverName' => $route->server_name,
            'status' => (string) $route->status,
            'changeReason' => (string) $route->change_reason,
            'lastObservedAtIso' => $latest?->observed_at !== null ? CarbonImmutable::parse((string) $latest->observed_at)->toIso8601String() : null,
            'lastErrorCode' => $latest?->error_code,
            'lastAddressCount' => $latest !== null ? (int) $latest->address_count : 0,
            'policySha256' => $latest?->policy_sha256 ?? $this->policyHash($route),
        ];
    }

    private function policyHash(object $route): string
    {
        $policy = [
            'source_id' => (int) $route->source_id,
            'endpoint_id' => $route->source_endpoint_id !== null ? (int) $route->source_endpoint_id : null,
            'environment' => (string) $route->environment,
            'transport' => (string) $route->transport,
            'hostname' => (string) $route->hostname,
            'port' => (int) $route->port,
            'proxy_origin' => filled($route->proxy_url) ? $this->origin((string) $route->proxy_url) : null,
            'dns_policy' => (string) $route->dns_policy,
            'allowed_ip_cidrs' => $this->decodeList($route->allowed_ip_cidrs),
            'egress_policy_key' => (string) $route->egress_policy_key,
            'mtls_required' => (bool) $route->mtls_required,
            'client_credential_id' => $route->client_credential_id !== null ? (int) $route->client_credential_id : null,
            'server_name' => $route->server_name,
        ];

        return hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private function decodeList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
