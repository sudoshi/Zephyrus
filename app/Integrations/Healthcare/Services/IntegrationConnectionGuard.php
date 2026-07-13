<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;
use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;
use App\Security\Network\GuardedConnection;
use App\Security\Network\IntegrationUrlPolicy;
use Illuminate\Support\Facades\DB;

final class IntegrationConnectionGuard
{
    public function __construct(
        private readonly NetworkRouteService $routes,
        private readonly IntegrationUrlPolicy $publicPolicy,
        private readonly CredentialRuntimeResolver $credentials,
    ) {}

    public function guard(int $sourceId, string $url): GuardedConnection
    {
        $source = DB::table('integration.sources')->where('source_id', $sourceId)->firstOrFail();
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! filled($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new IntegrationProtocolException('network_endpoint_invalid');
        }
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $port = (int) ($parts['port'] ?? 443);
        $route = DB::table('integration.source_network_routes')
            ->where('source_id', $sourceId)
            ->where('hostname', $host)
            ->where('port', $port)
            ->where('status', '<>', 'retired')
            ->orderByDesc('source_network_route_id')
            ->first();
        if ($route !== null) {
            $inspection = $this->routes->inspect((int) $route->source_network_route_id);
            if ($inspection['status'] !== 'validated') {
                throw new IntegrationProtocolException($inspection['errorCode'] ?? 'network_route_blocked');
            }
            $certificate = null;
            $privateKey = null;
            if ((bool) $route->mtls_required) {
                try {
                    $certificate = $this->credentials->resolveCertificate(
                        $sourceId,
                        (int) $route->client_credential_id,
                    )->value();
                    $privateKey = $this->credentials->resolveSecret(
                        $sourceId,
                        (int) $route->client_credential_id,
                    )->value();
                } catch (IntegrationCredentialException $exception) {
                    throw new IntegrationProtocolException($exception->errorCode);
                }
            }

            return new GuardedConnection(
                'https',
                $host,
                $port,
                $inspection['addresses'],
                (int) $route->source_network_route_id,
                filled($route->proxy_url) ? (string) $route->proxy_url : null,
                $inspection['proxyAddresses'],
                $certificate,
                $privateKey,
            );
        }
        if ((string) $source->environment === 'production') {
            throw new IntegrationProtocolException('network_route_required');
        }
        try {
            $public = $this->publicPolicy->assertSafeAndResolve($url);
        } catch (\Throwable) {
            throw new IntegrationProtocolException('network_endpoint_policy_failed');
        }

        return new GuardedConnection(
            $public['scheme'],
            $public['host'],
            $public['port'],
            $public['addresses'],
            null,
        );
    }
}
