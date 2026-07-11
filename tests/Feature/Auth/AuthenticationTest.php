<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create([
            'must_change_password' => false,
        ]);

        $response = $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
            'workflow' => 'rtdc',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_standard_demo_admin_can_open_the_admin_panel(): void
    {
        $user = User::query()->where('username', 'admin')->firstOrFail();
        $user->forceFill([
            'password' => Hash::make('password'),
            'role' => 'admin',
            'must_change_password' => false,
            'is_active' => true,
        ])->save();
        Role::findOrCreate('user', 'web');
        $user->syncRoles('user');

        $this->assertFalse($user->hasRole(['super-admin', 'admin']));

        $this->post('/login', [
            'username' => 'admin',
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->get('/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users/Index')
                ->where('auth.is_admin', true));
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
