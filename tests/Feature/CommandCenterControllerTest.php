<?php

// tests/Feature/CommandCenterControllerTest.php

namespace Tests\Feature;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CommandCenterControllerTest extends TestCase
{
    public function test_dashboard_renders_command_center_with_payload(): void
    {
        $this->withoutVite();
        $user = User::factory()->make(['must_change_password' => false]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/CommandCenter')
                ->has('data.strain')
                ->has('data.heroMetrics')
                ->has('data.capacity')
                ->has('data.flow')
                ->has('data.forecast'));
    }
}
