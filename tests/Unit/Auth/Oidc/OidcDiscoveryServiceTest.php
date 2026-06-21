<?php

namespace Tests\Unit\Auth\Oidc;

use App\Services\Auth\Oidc\Exceptions\OidcException;
use App\Services\Auth\Oidc\OidcDiscoveryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OidcDiscoveryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_fetches_and_caches_discovery_and_jwks(): void
    {
        Http::fake([
            'https://idp/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://idp',
                'authorization_endpoint' => 'https://idp/authorize',
                'token_endpoint' => 'https://idp/token',
                'jwks_uri' => 'https://idp/jwks',
            ]),
            'https://idp/jwks' => Http::response(['keys' => [['kid' => 'k1']]]),
        ]);

        $svc = new OidcDiscoveryService('https://idp/.well-known/openid-configuration');

        $this->assertSame('https://idp', $svc->issuer());
        $this->assertSame('https://idp/token', $svc->tokenEndpoint());
        $this->assertSame('k1', $svc->jwks()['keys'][0]['kid']);
    }

    public function test_throws_when_discovery_is_malformed(): void
    {
        Http::fake(['*' => Http::response(['issuer' => 'https://idp'])]);
        $this->expectException(OidcException::class);
        (new OidcDiscoveryService('https://idp/.well-known/openid-configuration'))->issuer();
    }
}
