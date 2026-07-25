<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthenticationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_authenticates_a_user_with_valid_credentials(): void
    {
        User::factory()->create([
            'username' => 'testuser',
            'password' => Hash::make('password123'),
            'must_change_password' => false,
        ]);

        $this->post('/login', [
            'username' => 'testuser',
            'password' => 'password123',
        ]);

        $this->assertAuthenticated();
    }

    public function test_rejects_login_with_invalid_credentials(): void
    {
        User::factory()->create([
            'username' => 'testuser',
            'password' => Hash::make('password123'),
        ]);

        $this->post('/login', [
            'username' => 'testuser',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_rejects_login_with_nonexistent_username(): void
    {
        $this->post('/login', [
            'username' => 'nonexistent',
            'password' => 'password123',
        ]);

        $this->assertGuest();
    }

    public function test_registration_creates_user_with_must_change_password_set(): void
    {
        config()->set('auth-drivers.local.registration_enabled', true);
        Http::fake();

        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'newuser@example.com',
        ]);

        $user = User::where('email', 'newuser@example.com')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->must_change_password);
    }

    public function test_registration_auto_generates_username_from_email(): void
    {
        config()->set('auth-drivers.local.registration_enabled', true);
        Http::fake();

        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'john.doe@example.com',
        ]);

        $user = User::where('email', 'john.doe@example.com')->first();

        $this->assertNotNull($user);
        $this->assertNotSame('', $user->username);
    }

    public function test_shows_change_password_page_for_authenticated_user(): void
    {
        $this->withoutVite();

        $user = User::factory()->create([
            'must_change_password' => true,
        ]);

        $response = $this->actingAs($user)->get('/change-password');

        $response->assertStatus(200);
    }

    public function test_updates_password_and_clears_must_change_password_flag(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('temp-password'),
            'must_change_password' => true,
        ]);

        $this->actingAs($user)->post('/change-password', [
            'current_password' => 'temp-password',
            'new_password' => 'new-secure-password',
            'new_password_confirmation' => 'new-secure-password',
        ]);

        $user->refresh();
        $this->assertFalse($user->must_change_password);
    }

    public function test_logs_out_the_authenticated_user(): void
    {
        $user = User::factory()->create([
            'must_change_password' => false,
        ]);

        $this->actingAs($user);
        $this->assertAuthenticated();

        $this->post('/logout');

        $this->assertGuest();
    }

    public function test_rbac_blocks_non_admin_users_from_admin_routes(): void
    {
        $user = User::factory()->create([
            'must_change_password' => false,
        ]);

        $response = $this->actingAs($user)->get('/users');

        $response->assertStatus(403);
    }

    public function test_rbac_allows_admin_users_to_access_admin_routes(): void
    {
        $this->withoutVite();

        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create([
            'must_change_password' => false,
        ]);
        $user->assignRole('admin');

        $response = $this->actingAs($user)->get('/users');

        $response->assertStatus(200);
    }

    public function test_redirects_unauthenticated_users_to_login(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_allows_authenticated_users_to_access_dashboard(): void
    {
        $this->withoutVite();

        $user = User::factory()->create([
            'must_change_password' => false,
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
    }
}
