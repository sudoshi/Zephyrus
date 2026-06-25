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
                ->has('data.forecast')
                ->has('data.outcomes')
                ->has('data.forecastDetail')
                ->has('data.unitCensus')
                ->has('data.objectives')
                ->has('data.generatedAtIso'));
    }

    public function test_command_center_drilldown_api_returns_ninety_day_detail(): void
    {
        $user = User::factory()->make(['must_change_password' => false]);

        $this->actingAs($user)
            ->getJson('/api/command-center/drilldown?focus=panel:flow&days=30')
            ->assertOk()
            ->assertJsonPath('window.days', 90)
            ->assertJsonPath('window.synthetic', true)
            ->assertJsonPath('focus.type', 'panel')
            ->assertJsonPath('focus.key', 'flow')
            ->assertJsonCount(90, 'timeline')
            ->assertJsonCount(4, 'panels')
            ->assertJsonCount(90, 'panels.1.daily');
    }
}
