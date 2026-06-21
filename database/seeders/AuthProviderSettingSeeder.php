<?php

namespace Database\Seeders;

use App\Models\Auth\AuthProviderSetting;
use Illuminate\Database\Seeder;

class AuthProviderSettingSeeder extends Seeder
{
    public function run(): void
    {
        AuthProviderSetting::query()->updateOrCreate(
            ['provider_type' => 'oidc'],
            [
                'display_name' => 'Sign in with Authentik',
                'is_enabled' => false, // flip on after secrets are in env + Authentik app exists
                'settings' => [
                    'discovery_url' => 'https://auth.acumenus.net/application/o/zephyrus-oidc/.well-known/openid-configuration',
                    'redirect_uri' => 'https://zephyrus.acumenus.net/auth/oidc/callback',
                    'scopes' => ['openid', 'profile', 'email', 'groups'],
                    'allowed_groups' => ['Zephyrus Users'],
                    'admin_groups' => ['Zephyrus Admins'],
                ],
            ],
        );
    }
}
