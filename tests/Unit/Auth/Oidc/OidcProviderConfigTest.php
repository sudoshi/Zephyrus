<?php

namespace Tests\Unit\Auth\Oidc;

use App\Models\Auth\AuthProviderSetting;
use App\Services\Auth\Oidc\OidcProviderConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OidcProviderConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_falls_back_to_env_when_a_stored_setting_is_an_empty_string(): void
    {
        config()->set('services.oidc.client_id', 'env-client');
        config()->set('services.oidc.client_secret', 'env-secret');
        config()->set('services.oidc.enabled', true);
        config()->set('services.oidc.discovery_url', 'https://idp/.well-known/openid-configuration');
        config()->set('services.oidc.redirect_uri', 'https://app/auth/oidc/callback');

        AuthProviderSetting::create([
            'provider_type' => 'oidc',
            'display_name' => 'Sign in with Authentik',
            'is_enabled' => true,
            'settings' => ['client_id' => ''], // empty string must NOT override env
        ]);

        $config = app(OidcProviderConfig::class);

        $this->assertSame('env-client', $config->clientId());
        $this->assertTrue($config->isPubliclyAvailable());
    }

    public function test_is_not_publicly_available_when_disabled(): void
    {
        config()->set('services.oidc.enabled', false);
        $this->assertFalse(app(OidcProviderConfig::class)->isPubliclyAvailable());
    }

    public function test_confidential_client_is_not_advertised_without_its_deployment_secret(): void
    {
        config()->set('services.oidc.enabled', true);
        config()->set('services.oidc.discovery_url', 'https://idp/.well-known/openid-configuration');
        config()->set('services.oidc.client_id', 'client');
        config()->set('services.oidc.client_secret', '');
        config()->set('services.oidc.redirect_uri', 'https://app/auth/oidc/callback');
        config()->set('auth-drivers.oidc_network.require_client_secret', true);

        $this->assertFalse(app(OidcProviderConfig::class)->isPubliclyAvailable());

        config()->set('auth-drivers.oidc_network.require_client_secret', false);
        $this->assertTrue(app(OidcProviderConfig::class)->isPubliclyAvailable());
    }

    public function test_stored_enablement_is_authoritative_over_the_bootstrap_environment_flag(): void
    {
        config()->set('services.oidc.enabled', true);
        AuthProviderSetting::create([
            'provider_type' => 'oidc',
            'display_name' => 'Enterprise SSO',
            'is_enabled' => false,
            'settings' => [],
        ]);

        $this->assertFalse(app(OidcProviderConfig::class)->isEnabled());

        AuthProviderSetting::query()->where('provider_type', 'oidc')->update(['is_enabled' => true]);
        config()->set('services.oidc.enabled', false);
        $this->assertTrue(app(OidcProviderConfig::class)->isEnabled());
    }
}
