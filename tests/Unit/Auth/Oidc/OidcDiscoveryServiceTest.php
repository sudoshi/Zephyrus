<?php

namespace Tests\Unit\Auth\Oidc;

use App\Security\Network\OidcUrlPolicy;
use App\Services\Auth\Oidc\Exceptions\OidcException;
use App\Services\Auth\Oidc\OidcDiscoveryService;
use App\Services\Auth\Oidc\OidcHttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OidcDiscoveryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('auth-drivers.oidc_network.allowed_hosts', ['idp']);
        config()->set('auth-drivers.oidc_network.allowed_ports', [443]);
        config()->set('auth-drivers.oidc_network.require_https', true);
        config()->set('auth-drivers.oidc_network.require_dns_resolution', false);
        config()->set('auth-drivers.oidc_network.max_response_bytes', 1_048_576);
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

        $svc = $this->service();

        $this->assertSame('https://idp', $svc->issuer());
        $this->assertSame('https://idp/token', $svc->tokenEndpoint());
        $this->assertSame('k1', $svc->jwks()['keys'][0]['kid']);
    }

    public function test_throws_when_discovery_is_malformed(): void
    {
        Http::fake(['*' => Http::response(['issuer' => 'https://idp'])]);
        $this->expectException(OidcException::class);
        $this->service()->issuer();
    }

    public function test_rejects_discovery_redirects(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://idp/elsewhere'])]);

        $this->expectException(OidcException::class);
        try {
            $this->service()->issuer();
        } catch (OidcException $exception) {
            $this->assertSame('discovery_redirect_rejected', $exception->reason);
            throw $exception;
        }
    }

    public function test_rejects_oversized_discovery_documents(): void
    {
        config()->set('auth-drivers.oidc_network.max_response_bytes', 128);
        Http::fake(['*' => Http::response(str_repeat('x', 129))]);

        $this->expectException(OidcException::class);
        try {
            $this->service()->issuer();
        } catch (OidcException $exception) {
            $this->assertSame('discovery_response_too_large', $exception->reason);
            throw $exception;
        }
    }

    public function test_rejects_unallowlisted_metadata_endpoints(): void
    {
        Http::fake(['*' => Http::response([
            'issuer' => 'https://idp',
            'authorization_endpoint' => 'https://idp/authorize',
            'token_endpoint' => 'https://evil.example/token',
            'jwks_uri' => 'https://idp/jwks',
        ])]);

        $this->expectException(OidcException::class);
        try {
            $this->service()->issuer();
        } catch (OidcException $exception) {
            $this->assertSame('oidc_host_not_allowed', $exception->reason);
            throw $exception;
        }
    }

    public function test_diagnostics_reports_bounded_signing_readiness(): void
    {
        Http::fake([
            'https://idp/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://idp',
                'authorization_endpoint' => 'https://idp/authorize',
                'token_endpoint' => 'https://idp/token',
                'jwks_uri' => 'https://idp/jwks',
            ]),
            'https://idp/jwks' => Http::response(['keys' => [['kid' => 'k1', 'alg' => 'RS256']]]),
        ]);

        $diagnostics = $this->service()->diagnostics();

        $this->assertSame('healthy', $diagnostics['status']);
        $this->assertSame(1, $diagnostics['signing_key_count']);
        $this->assertSame(['RS256'], $diagnostics['signing_algorithms']);
        $this->assertArrayHasKey('latency_ms', $diagnostics);
    }

    private function service(): OidcDiscoveryService
    {
        return new OidcDiscoveryService(
            'https://idp/.well-known/openid-configuration',
            app(OidcHttpClient::class),
            app(OidcUrlPolicy::class),
        );
    }
}
