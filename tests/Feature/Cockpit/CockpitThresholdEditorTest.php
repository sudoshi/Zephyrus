<?php

namespace Tests\Feature\Cockpit;

use App\Models\Ops\MetricDefinition;
use App\Models\User;
use Database\Seeders\CockpitKpiDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Zephyrus 2.0 P8 WS-6b — the admin threshold editor. GET + PUT
 * /api/cockpit/kpi-definitions are admin-gated (AdminMiddleware); the PUT is the
 * audited band-edge tune that lets a CMIO retune status bands without a deploy.
 * PHPUnit class syntax (Pest excluded on this environment).
 */
class CockpitThresholdEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CockpitKpiDefinitionSeeder::class);
    }

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_admin_can_read_the_kpi_definitions(): void
    {
        $response = $this->actingAs($this->admin())
            ->getJson('/api/cockpit/kpi-definitions')
            ->assertOk();

        $definitions = $response->json('definitions');
        $this->assertNotEmpty($definitions);
        $this->assertArrayHasKey('key', $definitions[0]);
        $this->assertArrayHasKey('edges', $definitions[0]);
    }

    public function test_admin_can_tune_a_band_edge_and_it_persists(): void
    {
        $definition = MetricDefinition::query()->firstOrFail();

        $this->actingAs($this->admin())
            ->putJson("/api/cockpit/kpi-definitions/{$definition->metric_key}", ['warn_edge' => 42.5])
            ->assertOk()
            ->assertJsonPath('key', $definition->metric_key);

        $this->assertSame(42.5, (float) $definition->fresh()->warn_edge);
    }

    public function test_a_non_admin_is_denied_the_editor_endpoints(): void
    {
        $user = User::factory()->create();
        $definition = MetricDefinition::query()->firstOrFail();

        $this->actingAs($user)->getJson('/api/cockpit/kpi-definitions')->assertStatus(403);
        $this->actingAs($user)
            ->putJson("/api/cockpit/kpi-definitions/{$definition->metric_key}", ['warn_edge' => 10])
            ->assertStatus(403);
    }

    public function test_an_unknown_metric_key_is_a_404(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/api/cockpit/kpi-definitions/not.a.real.metric', ['warn_edge' => 1])
            ->assertStatus(404);
    }
}
