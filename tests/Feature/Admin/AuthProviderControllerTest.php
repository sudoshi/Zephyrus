<?php

namespace Tests\Feature\Admin;

use App\Models\Audit\UserEvent;
use App\Models\Auth\AuthProviderSetting;
use App\Models\User;
use App\Services\Auth\StepUpAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AuthProviderControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('auth-drivers.oidc_network.allowed_hosts', ['identity.example', 'idp']);
        config()->set('auth-drivers.oidc_network.allowed_ports', [443]);
        config()->set('auth-drivers.oidc_network.allowed_redirect_uris', [
            'https://zephyrus.example/auth/oidc/callback',
            'https://app.test/auth/oidc/callback',
        ]);
        config()->set('auth-drivers.oidc_network.require_https', true);
        config()->set('auth-drivers.oidc_network.require_dns_resolution', false);
        config()->set('auth-drivers.oidc_network.max_response_bytes', 1_048_576);
    }

    public function test_forbids_non_admins_from_reading_provider_settings(): void
    {
        $user = User::factory()->create(['role' => 'user', 'is_active' => true, 'must_change_password' => false]);
        $this->actingAs($user)->getJson('/admin/auth-providers/oidc')->assertForbidden();
        $this->actingAs($user)->get('/admin/auth-providers')->assertForbidden();
    }

    public function test_admin_page_reports_effective_configuration_without_exposing_secrets(): void
    {
        config()->set('services.oidc.enabled', true);
        config()->set('services.oidc.client_secret', 'environment-only-secret');
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        AuthProviderSetting::create([
            'provider_type' => 'oidc',
            'display_name' => 'Enterprise SSO',
            'is_enabled' => false,
            'settings' => [
                'client_id' => 'zephyrus-web',
                'client_secret' => 'legacy-database-secret',
                'unknown_private_value' => 'do-not-return',
            ],
        ]);

        $response = $this->actingAs($admin)->get('/admin/auth-providers')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/AuthProviders')
            ->where('local.registrationEnabled', false)
            ->where('oidc.effective.enabled', false)
            ->where('oidc.effective.clientSecretConfigured', true)
            ->where('oidc.effective.settings.client_id', 'zephyrus-web')
            ->where('oidc.networkPolicy.allowedHosts', ['identity.example', 'idp'])
            ->where('oidc.networkPolicy.privateNetworksAllowed', false)
            ->missing('oidc.effective.settings.client_secret')
            ->missing('oidc.stored.settings.client_secret')
            ->missing('oidc.stored.settings.unknown_private_value'));

        $this->assertStringNotContainsString('environment-only-secret', $response->getContent());
        $this->assertStringNotContainsString('legacy-database-secret', $response->getContent());
    }

    public function test_lets_an_admin_read_settings_with_the_secret_masked(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        AuthProviderSetting::create([
            'provider_type' => 'oidc',
            'display_name' => 'Sign in with Authentik',
            'is_enabled' => true,
            'settings' => ['client_id' => 'abc', 'client_secret' => 'should-never-appear'],
        ]);

        $res = $this->actingAs($admin)->getJson('/admin/auth-providers/oidc')->assertOk();
        $res->assertJsonPath('settings.client_id', 'abc');
        $this->assertStringNotContainsString('should-never-appear', json_encode($res->json()));
    }

    public function test_update_rejects_client_secrets_instead_of_silently_accepting_them(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);

        $this->actingAs($admin)->putJson('/admin/auth-providers/oidc', [
            'is_enabled' => true,
            'settings' => ['client_id' => 'xyz', 'client_secret' => 'leaked-secret'],
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('prod.auth_provider_settings', ['provider_type' => 'oidc']);
    }

    public function test_update_accepts_only_the_governed_oidc_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        AuthProviderSetting::create([
            'provider_type' => 'oidc',
            'display_name' => 'Legacy provider',
            'is_enabled' => false,
            'settings' => [
                'client_secret' => 'legacy-secret',
                'issuer_override' => 'https://legacy.example',
            ],
        ]);

        $this->actingAs($admin)->withSession([
            StepUpAuthenticationService::VERIFIED_AT => time(),
            StepUpAuthenticationService::METHOD => 'password',
        ])->putJson('/admin/auth-providers/oidc', [
            'is_enabled' => true,
            'display_name' => 'Enterprise SSO',
            'change_reason' => 'identity_provider_configuration',
            'settings' => [
                'discovery_url' => 'https://identity.example/.well-known/openid-configuration',
                'client_id' => 'zephyrus-web',
                'redirect_uri' => 'https://zephyrus.example/auth/oidc/callback',
                'scopes' => ['openid', 'profile', 'email', 'groups'],
                'allowed_groups' => ['Zephyrus Users'],
                'admin_groups' => ['Zephyrus Admins'],
            ],
        ])->assertOk()->assertJsonMissingPath('settings.client_secret');

        $row = AuthProviderSetting::where('provider_type', 'oidc')->firstOrFail();
        $this->assertSame('zephyrus-web', $row->settings['client_id']);
        $this->assertSame(['Zephyrus Admins'], $row->settings['admin_groups']);
        $this->assertArrayNotHasKey('client_secret', $row->settings);
        $this->assertArrayNotHasKey('issuer_override', $row->settings);

        $this->actingAs($admin)->putJson('/admin/auth-providers/oidc', [
            'settings' => ['issuer_override' => 'https://attacker.example'],
        ])->assertUnprocessable();
    }

    public function test_provider_update_requires_recent_step_up_and_a_governed_reason(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);

        $this->actingAs($admin)->putJson('/admin/auth-providers/oidc', [
            'is_enabled' => false,
            'change_reason' => 'identity_provider_emergency_disable',
        ])->assertStatus(428)->assertJsonPath('error.code', 'step_up_required');

        $this->assertDatabaseMissing('prod.auth_provider_settings', ['provider_type' => 'oidc']);
        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $admin->id,
            'action' => 'security.step_up.required',
            'outcome' => 'challenge',
            'reason' => 'identity_provider_configuration_changed',
        ]);
    }

    public function test_unknown_provider_types_are_not_an_unbounded_configuration_namespace(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);

        $this->actingAs($admin)->getJson('/admin/auth-providers/arbitrary')->assertNotFound();
        $this->actingAs($admin)->putJson('/admin/auth-providers/arbitrary', [
            'is_enabled' => true,
        ])->assertNotFound();
    }

    public function test_admin_can_run_bounded_discovery_diagnostics_and_the_result_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        config()->set('services.oidc.client_secret', 'deployment-secret');
        AuthProviderSetting::create([
            'provider_type' => 'oidc',
            'display_name' => 'Enterprise SSO',
            'is_enabled' => true,
            'settings' => [
                'discovery_url' => 'https://idp/.well-known/openid-configuration',
                'client_id' => 'zephyrus-web',
                'redirect_uri' => 'https://app.test/auth/oidc/callback',
            ],
        ]);
        Http::fake([
            'https://idp/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://idp',
                'authorization_endpoint' => 'https://idp/authorize',
                'token_endpoint' => 'https://idp/token',
                'jwks_uri' => 'https://idp/jwks',
            ]),
            'https://idp/jwks' => Http::response(['keys' => [['kid' => 'signing-1', 'alg' => 'RS256']]]),
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/admin/auth-providers/oidc/diagnostics')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('issuer', 'https://idp')
            ->assertJsonPath('signing_key_count', 1)
            ->assertJsonMissingPath('client_secret');

        $this->assertStringNotContainsString('deployment-secret', $response->getContent());
        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $admin->id,
            'action' => 'administration.auth_provider.diagnostic',
            'outcome' => 'success',
            'target_type' => 'auth_provider',
            'target_id' => 'oidc',
        ]);
    }

    public function test_diagnostics_fail_closed_on_unapproved_metadata_and_audit_the_reason(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        config()->set('services.oidc.enabled', true);
        config()->set('services.oidc.client_id', 'zephyrus-web');
        config()->set('services.oidc.client_secret', 'deployment-secret');
        config()->set('services.oidc.discovery_url', 'https://idp/.well-known/openid-configuration');
        config()->set('services.oidc.redirect_uri', 'https://app.test/auth/oidc/callback');
        Http::fake(['*' => Http::response([
            'issuer' => 'https://idp',
            'authorization_endpoint' => 'https://idp/authorize',
            'token_endpoint' => 'https://metadata.invalid/token',
            'jwks_uri' => 'https://idp/jwks',
        ])]);

        $this->actingAs($admin)
            ->postJson('/admin/auth-providers/oidc/diagnostics')
            ->assertUnprocessable()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('reason', 'oidc_host_not_allowed')
            ->assertJsonStructure(['checkedAt']);

        $event = UserEvent::query()
            ->where('action', 'administration.auth_provider.diagnostic')
            ->latest('event_cursor')
            ->firstOrFail();
        $this->assertSame('failure', $event->outcome);
        $this->assertSame('oidc_host_not_allowed', $event->reason);
    }

    public function test_non_admin_cannot_run_oidc_diagnostics(): void
    {
        $user = User::factory()->create(['role' => 'user', 'is_active' => true, 'must_change_password' => false]);

        $this->actingAs($user)
            ->postJson('/admin/auth-providers/oidc/diagnostics')
            ->assertForbidden();
        Http::assertNothingSent();
    }
}
