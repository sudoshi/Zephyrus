<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OidcControllerTest extends TestCase
{
    use RefreshDatabase;

    private function enableOidc(): void
    {
        config()->set('services.oidc.enabled', true);
        config()->set('services.oidc.client_id', 'client-123');
        config()->set('services.oidc.client_secret', 'secret');
        config()->set('services.oidc.discovery_url', 'https://idp/.well-known/openid-configuration');
        config()->set('services.oidc.redirect_uri', 'https://app.test/auth/oidc/callback');
        Cache::flush();
        Http::fake([
            'https://idp/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://idp',
                'authorization_endpoint' => 'https://idp/authorize',
                'token_endpoint' => 'https://idp/token',
                'jwks_uri' => 'https://idp/jwks',
            ]),
            'https://idp/jwks' => Http::response(['keys' => []]),
        ]);
    }

    public function test_returns_404_on_redirect_when_oidc_is_disabled(): void
    {
        config()->set('services.oidc.enabled', false);
        $this->get('/auth/oidc/redirect')->assertNotFound();
    }

    public function test_redirects_to_authentik_authorize_endpoint_when_enabled(): void
    {
        $this->enableOidc();
        $res = $this->get('/auth/oidc/redirect');
        $res->assertRedirect();
        $loc = (string) $res->headers->get('Location');
        $this->assertStringContainsString('https://idp/authorize', $loc);
        $this->assertStringContainsString('code_challenge_method=S256', $loc);
        $this->assertStringContainsString('client_id=client-123', $loc);
    }

    public function test_rejects_a_callback_with_an_unknown_state(): void
    {
        $this->enableOidc();
        $this->get('/auth/oidc/callback?state=bogus&code=abc')->assertStatus(400);
    }
}
