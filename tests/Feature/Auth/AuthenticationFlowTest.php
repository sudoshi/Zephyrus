<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

describe('login', function () {
    it('renders the login page', function () {
        $response = $this->get('/login');

        $response->assertStatus(200);
    });

    it('authenticates a user with valid credentials', function () {
        $user = User::factory()->create([
            'username' => 'testuser',
            'password' => Hash::make('password123'),
            'must_change_password' => false,
        ]);

        $response = $this->post('/login', [
            'username' => 'testuser',
            'password' => 'password123',
        ]);

        $this->assertAuthenticated();
    });

    it('rejects login with invalid credentials', function () {
        $user = User::factory()->create([
            'username' => 'testuser',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->post('/login', [
            'username' => 'testuser',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    });

    it('rejects login with non-existent username', function () {
        $response = $this->post('/login', [
            'username' => 'nonexistent',
            'password' => 'password123',
        ]);

        $this->assertGuest();
    });
});

describe('registration', function () {
    it('creates user with must_change_password set to true', function () {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'newuser@example.com',
        ]);

        $user = User::where('email', 'newuser@example.com')->first();

        expect($user)->not->toBeNull();
        expect($user->must_change_password)->toBeTrue();
    });

    it('auto-generates username from email', function () {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'john.doe@example.com',
        ]);

        $user = User::where('email', 'john.doe@example.com')->first();

        expect($user)->not->toBeNull();
        expect($user->username)->not->toBeEmpty();
    });
});

describe('change password', function () {
    it('shows change password page for authenticated user', function () {
        $user = User::factory()->create([
            'must_change_password' => true,
        ]);

        $response = $this->actingAs($user)->get('/change-password');

        $response->assertStatus(200);
    });

    it('updates password and clears must_change_password flag', function () {
        $user = User::factory()->create([
            'password' => Hash::make('temp-password'),
            'must_change_password' => true,
        ]);

        $response = $this->actingAs($user)->post('/change-password', [
            'current_password' => 'temp-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $user->refresh();
        expect($user->must_change_password)->toBeFalse();
    });
});

describe('logout', function () {
    it('logs out the authenticated user', function () {
        $user = User::factory()->create([
            'must_change_password' => false,
        ]);

        $this->actingAs($user);
        $this->assertAuthenticated();

        $this->post('/logout');

        $this->assertGuest();
    });
});

describe('RBAC middleware', function () {
    it('blocks non-admin users from admin routes', function () {
        $user = User::factory()->create([
            'must_change_password' => false,
        ]);

        $response = $this->actingAs($user)->get('/users');

        $response->assertStatus(403);
    });

    it('allows admin users to access admin routes', function () {
        $user = User::factory()->create([
            'must_change_password' => false,
        ]);
        $user->assignRole('admin');

        $response = $this->actingAs($user)->get('/users');

        $response->assertStatus(200);
    });
});

describe('protected routes', function () {
    it('redirects unauthenticated users to login', function () {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    });

    it('allows authenticated users to access dashboard', function () {
        $user = User::factory()->create([
            'must_change_password' => false,
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
    });
});
