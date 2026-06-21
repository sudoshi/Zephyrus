<?php

namespace App\Services\Auth\Oidc;

use App\Services\Auth\Oidc\Exceptions\OidcException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OidcDiscoveryService
{
    private const CACHE_KEY_PREFIX = 'oidc:discovery:';

    private const CACHE_TTL = 3600;

    public function __construct(private readonly string $discoveryUrl) {}

    /** @return array<string, mixed> */
    public function config(): array
    {
        return Cache::remember($this->cacheKey(), self::CACHE_TTL, function (): array {
            try {
                $response = Http::timeout(5)->get($this->discoveryUrl);
            } catch (ConnectionException $e) {
                throw new OidcException('discovery_unreachable', $e->getMessage(), $e);
            }
            if ($response->failed()) {
                throw new OidcException('discovery_failed', 'Discovery returned HTTP '.$response->status());
            }
            $config = $response->json() ?? [];
            foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $required) {
                if (! isset($config[$required]) || ! is_string($config[$required])) {
                    throw new OidcException('discovery_malformed', "Missing/invalid '{$required}' in discovery document");
                }
            }
            try {
                $jwks = Http::timeout(5)->get($config['jwks_uri']);
            } catch (ConnectionException $e) {
                throw new OidcException('jwks_unreachable', $e->getMessage(), $e);
            }
            if ($jwks->failed()) {
                throw new OidcException('jwks_failed', 'JWKS returned HTTP '.$jwks->status());
            }
            $body = $jwks->json() ?? [];
            if (! isset($body['keys']) || ! is_array($body['keys'])) {
                throw new OidcException('jwks_malformed', "JWKS response missing 'keys'");
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

    private function cacheKey(): string
    {
        return self::CACHE_KEY_PREFIX.sha1($this->discoveryUrl);
    }
}
