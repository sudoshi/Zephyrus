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
}
