<?php

namespace App\Services\Auth\Oidc;

use App\Security\Network\OidcUrlPolicy;
use App\Security\Network\UnsafeOidcUrl;
use App\Services\Auth\Oidc\Exceptions\OidcException;
use Illuminate\Support\Facades\Cache;

class OidcDiscoveryService
{
    private const CACHE_KEY_PREFIX = 'oidc:discovery:';

    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly string $discoveryUrl,
        private readonly OidcHttpClient $http,
        private readonly OidcUrlPolicy $urlPolicy,
    ) {}

    /** @return array<string, mixed> */
    public function config(): array
    {
        return Cache::remember($this->cacheKey(), self::CACHE_TTL, function (): array {
            $config = $this->http->getJson(
                $this->discoveryUrl,
                'application/json',
                'discovery',
            );
            foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $required) {
                if (! isset($config[$required]) || ! is_string($config[$required]) || trim($config[$required]) === '') {
                    throw new OidcException('discovery_malformed', "Missing/invalid '{$required}' in discovery document");
                }
            }
            try {
                $this->urlPolicy->assertSafeDiscoveryMetadata($this->discoveryUrl, $config);
            } catch (UnsafeOidcUrl $exception) {
                throw new OidcException($exception->reason, previous: $exception);
            }

            $body = $this->http->getJson(
                (string) $config['jwks_uri'],
                'application/jwk-set+json, application/json',
                'jwks',
            );
            if (! isset($body['keys']) || ! is_array($body['keys'])) {
                throw new OidcException('jwks_malformed', "JWKS response missing 'keys'");
            }
            if (count($body['keys']) > 100 || collect($body['keys'])->contains(fn (mixed $key): bool => ! is_array($key))) {
                throw new OidcException('jwks_malformed', 'JWKS contains an invalid key set');
            }
            $config['_jwks'] = $body;

            return $config;
        });
    }

    public function issuer(): string
    {
        return (string) $this->config()['issuer'];
    }

    public function authorizationEndpoint(): string
    {
        return (string) $this->config()['authorization_endpoint'];
    }

    public function tokenEndpoint(): string
    {
        return (string) $this->config()['token_endpoint'];
    }

    /** @return array{keys: list<array<string, mixed>>} */
    public function jwks(): array
    {
        return $this->config()['_jwks'];
    }

    public function flush(): void
    {
        Cache::forget($this->cacheKey());
    }

    /** @return array<string, mixed> */
    public function diagnostics(): array
    {
        $started = hrtime(true);
        $config = $this->config();
        $keys = (array) ($config['_jwks']['keys'] ?? []);
        $algorithms = collect($keys)
            ->pluck('alg')
            ->filter(fn (mixed $algorithm): bool => is_string($algorithm) && $algorithm !== '')
            ->unique()
            ->values()
            ->all();

        return [
            'status' => $keys === [] ? 'degraded' : 'healthy',
            'issuer' => $config['issuer'],
            'authorization_endpoint' => $config['authorization_endpoint'],
            'token_endpoint' => $config['token_endpoint'],
            'jwks_uri' => $config['jwks_uri'],
            'signing_key_count' => count($keys),
            'signing_algorithms' => $algorithms,
            'latency_ms' => round((hrtime(true) - $started) / 1_000_000, 1),
        ];
    }

    private function cacheKey(): string
    {
        return self::CACHE_KEY_PREFIX.sha1($this->discoveryUrl);
    }
}
