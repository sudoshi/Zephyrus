<?php

namespace Tests\Feature\Eddy;

use App\Models\Ops\Approval;
use App\Models\Ops\OperationalAction;
use App\Models\Ops\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EddyActionTest extends TestCase
{
    use RefreshDatabase;

    private function barrierProposal(array $override = []): array
    {
        return array_merge([
            'action_type' => 'flag_barrier',
            'title' => 'Imaging delay blocking 3W discharges',
            'surface' => 'rtdc',
            'rationale' => 'Two discharges are held on pending CT reads.',
            'runner_up' => 'Escalate to the radiology charge instead.',
            'params' => ['unit' => '3W', 'barrier' => 'imaging'],
        ], $override);
    }

    public function test_dock_human_proposal_creates_draft_governance_records(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/eddy/actions/propose', $this->barrierProposal());

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.tier', 'T1')
            ->assertJsonPath('data.approved', false);

        $recommendation = Recommendation::firstOrFail();
        $this->assertSame('eddy', $recommendation->created_by_source);   // Eddy proposed it, not the rules engine
        $this->assertSame('draft', $recommendation->status);
        $this->assertSame('draft', OperationalAction::firstOrFail()->status);
        $this->assertSame('pending', Approval::firstOrFail()->status);   // awaiting a human
    }

    public function test_human_can_propose_and_approve_in_one_step(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/eddy/actions/propose', $this->barrierProposal(['approve' => true]));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved', true);

        // The existing lifecycle executed the approval.
        $this->assertSame('approved', OperationalAction::firstOrFail()->status);
        $approval = Approval::firstOrFail();
        $this->assertSame('approved', $approval->status);
        $this->assertSame($user->id, $approval->decided_by_user_id);
    }

    public function test_scoped_token_can_draft_but_can_never_auto_approve(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['ops:read', 'ops:draft']);  // Eddy's agent token

        // Even with approve=true, the token cannot approve — it stays a draft for a human.
        $response = $this->postJson('/api/eddy/agent/actions/propose', $this->barrierProposal(['approve' => true]));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.approved', false);

        $this->assertSame('pending', Approval::firstOrFail()->status);
    }

    public function test_scoped_token_without_ops_draft_is_forbidden(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['ops:read']);  // read-only token

        $this->postJson('/api/eddy/agent/actions/propose', $this->barrierProposal())
            ->assertForbidden();

        $this->assertSame(0, OperationalAction::count());
    }

    public function test_unknown_action_type_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/eddy/actions/propose', $this->barrierProposal(['action_type' => 'rm_minus_rf']))
            ->assertStatus(422);
    }

    public function test_catalog_lists_the_proposable_actions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/eddy/actions/catalog')
            ->assertOk()
            ->assertJsonPath('data.flag_barrier.tier', 'T1')
            ->assertJsonPath('data.propose_surge_plan.risk', 'critical');
    }

    public function test_mint_agent_token_never_grants_ops_approve(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/eddy/agent/token');

        $response->assertOk();
        $abilities = $response->json('data.abilities');
        $this->assertContains('ops:draft', $abilities);
        $this->assertNotContains('ops:approve', $abilities);
    }
}
