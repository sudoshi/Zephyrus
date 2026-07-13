<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        config()->set('auth-drivers.local.registration_enabled', true);

        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        config()->set('auth-drivers.local.registration_enabled', true);
        Http::fake();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '555-555-5555',
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('login', absolute: false));
        $response->assertSessionHas('status');

        $user = User::where('email', 'test@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('test', $user->username);
        $this->assertTrue($user->must_change_password);
    }

    public function test_self_registration_is_not_available_by_default(): void
    {
        config()->set('auth-drivers.local.registration_enabled', false);

        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Unapproved User',
            'email' => 'unapproved@example.com',
        ])->assertNotFound();

        $this->assertDatabaseMissing('prod.users', ['email' => 'unapproved@example.com']);
    }
}
