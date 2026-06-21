<?php

namespace Tests\Feature\Admin;

use App\Models\Auth\AuthProviderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthProviderControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_forbids_non_admins_from_reading_provider_settings(): void
    {
        $user = User::factory()->create(['role' => 'user', 'is_active' => true, 'must_change_password' => false]);
        $this->actingAs($user)->getJson('/admin/auth-providers/oidc')->assertForbidden();
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

    public function test_update_never_persists_the_client_secret_to_the_db(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);

        $this->actingAs($admin)->putJson('/admin/auth-providers/oidc', [
            'is_enabled' => true,
            'settings' => ['client_id' => 'xyz', 'client_secret' => 'leaked-secret'],
        ])->assertOk();

        $row = AuthProviderSetting::where('provider_type', 'oidc')->first();
        $this->assertSame('xyz', $row->settings['client_id']);
        $this->assertArrayNotHasKey('client_secret', $row->settings);
    }
}
