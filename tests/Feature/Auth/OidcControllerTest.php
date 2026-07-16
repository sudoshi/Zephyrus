<?php

namespace Tests\Feature\Auth;

use App\Models\Audit\UserEvent;
use App\Models\User;
use App\Services\Auth\Oidc\OidcHandshakeStore;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OidcControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('auth-drivers.oidc_network.allowed_hosts', ['idp']);
        config()->set('auth-drivers.oidc_network.allowed_ports', [443]);
        config()->set('auth-drivers.oidc_network.allowed_redirect_uris', ['https://app.test/auth/oidc/callback']);
        config()->set('auth-drivers.oidc_network.require_https', true);
        config()->set('auth-drivers.oidc_network.require_dns_resolution', false);
        config()->set('auth-drivers.oidc_network.max_response_bytes', 1_048_576);
    }

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

    public function test_authenticated_oidc_step_up_requests_fresh_upstream_authentication(): void
    {
        $this->enableOidc();
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $response = $this->actingAs($user)->get('/auth/oidc/step-up')->assertRedirect();
        $location = (string) $response->headers->get('Location');

        $this->assertStringContainsString('prompt=login', $location);
        $this->assertStringContainsString('max_age=0', $location);
        $this->assertStringContainsString('code_challenge_method=S256', $location);
    }

    public function test_redirect_fails_closed_when_callback_uri_is_not_deployment_approved(): void
    {
        $this->enableOidc();
        config()->set('auth-drivers.oidc_network.allowed_redirect_uris', ['https://different.test/callback']);

        $this->get('/auth/oidc/redirect')->assertRedirect(route('login'));
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'auth.login',
            'outcome' => 'failure',
            'reason' => 'oidc_redirect_not_allowed',
        ]);
    }

    public function test_rejects_a_callback_with_an_unknown_state(): void
    {
        $this->enableOidc();
        $this->get('/auth/oidc/callback?state=bogus&code=abc')->assertStatus(400);
    }

    /** @return array{0: string, 1: array} */
    private function keypair(): array
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privatePem);
        $d = openssl_pkey_get_details($res);
        $n = rtrim(strtr(base64_encode($d['rsa']['n']), '+/', '-_'), '=');
        $e = rtrim(strtr(base64_encode($d['rsa']['e']), '+/', '-_'), '=');
        $jwks = ['keys' => [['kty' => 'RSA', 'kid' => 'test-kid', 'use' => 'sig', 'alg' => 'RS256', 'n' => $n, 'e' => $e]]];

        return [$privatePem, $jwks];
    }

    private function mintIdToken(string $priv, string $nonce, array $overrides = []): string
    {
        $claims = array_merge([
            'iss' => 'https://idp', 'aud' => 'client-123', 'sub' => 'sub-xyz',
            'email' => 'u@example.com', 'name' => 'OIDC User', 'nonce' => $nonce,
            'exp' => time() + 300, 'iat' => time(), 'groups' => ['Zephyrus Users'],
        ], $overrides);

        return JWT::encode($claims, $priv, 'RS256', 'test-kid');
    }

    /** Enable OIDC and fake discovery/jwks/token endpoints; returns [privateKey, jwks]. */
    private function enableOidcWithKeys(): array
    {
        [$priv, $jwks] = $this->keypair();
        config()->set('services.oidc.enabled', true);
        config()->set('services.oidc.client_id', 'client-123');
        config()->set('services.oidc.client_secret', 'secret');
        config()->set('services.oidc.discovery_url', 'https://idp/.well-known/openid-configuration');
        config()->set('services.oidc.redirect_uri', 'https://app.test/auth/oidc/callback');
        config()->set('services.oidc.allowed_groups', ['Zephyrus Users']);
        config()->set('services.oidc.admin_groups', ['Zephyrus Admins']);
        Cache::flush();

        return [$priv, $jwks];
    }

    public function test_callback_logs_in_jit_user_and_redirects_to_dashboard(): void
    {
        [$priv, $jwks] = $this->enableOidcWithKeys();

        $store = app(OidcHandshakeStore::class);
        $state = $store->putState(['nonce' => 'the-nonce', 'code_verifier' => 'the-verifier']);
        $idToken = $this->mintIdToken($priv, 'the-nonce');

        Http::fake([
            'https://idp/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://idp',
                'authorization_endpoint' => 'https://idp/authorize',
                'token_endpoint' => 'https://idp/token',
                'jwks_uri' => 'https://idp/jwks',
            ]),
            'https://idp/jwks' => Http::response($jwks),
            'https://idp/token' => Http::response(['id_token' => $idToken]),
        ]);

        $res = $this->get('/auth/oidc/callback?state='.$state.'&code=abc');

        $res->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $user = User::where('email', 'u@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('user', $user->role);
        $this->assertFalse($user->must_change_password);
        $this->assertSame($user->id, session('user_id'));
    }

    public function test_callback_denies_user_not_in_an_allowed_group(): void
    {
        [$priv, $jwks] = $this->enableOidcWithKeys();

        $store = app(OidcHandshakeStore::class);
        $state = $store->putState(['nonce' => 'the-nonce', 'code_verifier' => 'the-verifier']);
        $idToken = $this->mintIdToken($priv, 'the-nonce', ['email' => 'stranger@example.com', 'groups' => ['Other']]);

        Http::fake([
            'https://idp/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://idp',
                'authorization_endpoint' => 'https://idp/authorize',
                'token_endpoint' => 'https://idp/token',
                'jwks_uri' => 'https://idp/jwks',
            ]),
            'https://idp/jwks' => Http::response($jwks),
            'https://idp/token' => Http::response(['id_token' => $idToken]),
        ]);

        $res = $this->get('/auth/oidc/callback?state='.$state.'&code=abc');

        $res->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull(User::where('email', 'stranger@example.com')->first());
    }

    public function test_callback_rejects_token_endpoint_redirect_without_following_it(): void
    {
        [, $jwks] = $this->enableOidcWithKeys();
        $store = app(OidcHandshakeStore::class);
        $state = $store->putState(['nonce' => 'the-nonce', 'code_verifier' => 'the-verifier']);

        Http::fake([
            'https://idp/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://idp',
                'authorization_endpoint' => 'https://idp/authorize',
                'token_endpoint' => 'https://idp/token',
                'jwks_uri' => 'https://idp/jwks',
            ]),
            'https://idp/jwks' => Http::response($jwks),
            'https://idp/token' => Http::response('', 302, ['Location' => 'https://idp/other-token']),
        ]);

        $this->get('/auth/oidc/callback?state='.$state.'&code=abc')
            ->assertRedirect(route('login'));

        $event = UserEvent::query()->where('action', 'auth.login')->latest('event_cursor')->firstOrFail();
        $this->assertSame('token_redirect_rejected', $event->reason);
        Http::assertSentCount(3);
    }
}
