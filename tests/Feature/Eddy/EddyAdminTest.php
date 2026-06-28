<?php

namespace Tests\Feature\Eddy;

use App\Models\Eddy\EddyCloudUsage;
use App\Models\Eddy\EddyKnowledge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6 super-admin surfaces: cost/redaction accounting, the route simulator,
 * and the proposed-knowledge review gate — all locked to admins.
 */
class EddyAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function proposedKnowledge(): EddyKnowledge
    {
        return EddyKnowledge::create([
            'eddy_knowledge_uuid' => (string) Str::uuid(),
            'surface' => 'rtdc',
            'category' => 'playbook',
            'title' => 'Barrier playbook: imaging / ct_delay',
            'body' => 'Recurring barrier pattern.',
            'tags' => ['imaging'],
            'source' => 'auto:resolved-barriers',
            'is_phi_free' => true,
            'is_active' => true,
            'status' => 'proposed',
            'curated_from' => ['origin' => 'resolved_barriers', 'category' => 'imaging'],
        ]);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/eddy/admin/usage')->assertForbidden();
    }

    public function test_admin_sees_cost_and_redaction_aggregates(): void
    {
        $admin = $this->admin();
        EddyCloudUsage::create([
            'user_id' => $admin->id, 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-6',
            'tokens_in' => 100, 'tokens_out' => 50, 'cost_usd' => 0.0125,
            'sanitizer_redaction_count' => 2, 'request_surface' => 'command_center', 'status' => 'success',
        ]);

        $this->actingAs($admin)->getJson('/api/eddy/admin/usage')
            ->assertOk()
            ->assertJsonPath('data.totals.cloud_calls', 1)
            ->assertJsonPath('data.totals.redactions', 2);
    }

    public function test_route_simulator_returns_a_decision(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/eddy/admin/route-simulate', ['surface' => 'chat'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['will_call_paid_provider']]);
    }

    public function test_admin_can_approve_proposed_knowledge(): void
    {
        $admin = $this->admin();
        $knowledge = $this->proposedKnowledge();

        $this->actingAs($admin)->getJson('/api/eddy/admin/knowledge/proposed')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($admin)
            ->postJson("/api/eddy/admin/knowledge/{$knowledge->eddy_knowledge_uuid}/review", ['decision' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame('approved', $knowledge->fresh()->status);
    }
}
