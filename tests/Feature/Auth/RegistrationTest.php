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
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
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
}
