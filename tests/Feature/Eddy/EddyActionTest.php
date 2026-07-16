<?php

namespace Tests\Feature\Eddy;

use App\Models\Ops\Approval;
use App\Models\Ops\OperationalAction;
use App\Models\Ops\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EddyActionTest extends TestCase
{
    use RefreshDatabase;

    private function operator(string $role = 'bed_manager'): User
    {
        return User::factory()->create([
            'role' => $role,
            'must_change_password' => false,
        ]);
    }

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
        $user = $this->operator();

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

    public function test_alert_spawned_proposal_records_its_provenance(): void
    {
        $user = $this->operator();

        $response = $this->actingAs($user)->postJson(
            '/api/eddy/actions/propose',
            $this->barrierProposal(['alert_key' => 'ed.nedocs']),
        );

        $response->assertCreated();
        $this->assertSame('ed.nedocs', Recommendation::firstOrFail()->evidence['alert_key'] ?? null);
    }

    public function test_action_for_alert_resolves_the_p6_preseed_mapping(): void
    {
        // The acceptance case: a crit ED alert pre-seeds the surge plan.
        $this->assertSame('propose_surge_plan', \App\Services\Eddy\EddyActionService::actionForAlert('ed.nedocs', 'crit'));
        $this->assertSame('propose_bed_placement', \App\Services\Eddy\EddyActionService::actionForAlert('rtdc.occupancy', 'crit'));
        $this->assertSame('propose_transport_dispatch', \App\Services\Eddy\EddyActionService::actionForAlert('flow.transport_wait', 'crit'));
        $this->assertSame('propose_huddle_action', \App\Services\Eddy\EddyActionService::actionForAlert('staffing.callouts', 'crit'));

        // Warns never escalate past the low-risk responses.
        $this->assertSame('flag_barrier', \App\Services\Eddy\EddyActionService::actionForAlert('ed.los_admit', 'warn'));
        $this->assertSame('propose_huddle_action', \App\Services\Eddy\EddyActionService::actionForAlert('staffing.overtime', 'warn'));

        // Unmapped domains fall back to the barrier flag.
        $this->assertSame('flag_barrier', \App\Services\Eddy\EddyActionService::actionForAlert('quality.cdiff', 'crit'));
    }

    public function test_human_can_propose_and_approve_in_one_step(): void
    {
        $user = $this->operator();

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
        $user = $this->operator();
        $token = $user->createToken('eddy-agent', ['ops:read', 'ops:draft'])->plainTextToken;

        // Even with approve=true, the token cannot approve — it stays a draft for a human.
        $response = $this->withToken($token)
            ->postJson('/api/eddy/agent/actions/propose', $this->barrierProposal(['approve' => true]));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.approved', false);

        $this->assertSame('pending', Approval::firstOrFail()->status);
    }

    public function test_scoped_token_with_misissued_ops_approve_still_cannot_auto_approve(): void
    {
        $user = $this->operator();
        $token = $user->createToken('misissued-eddy-agent', ['ops:read', 'ops:draft', 'ops:approve'])->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/eddy/agent/actions/propose', $this->barrierProposal(['approve' => true]));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.approved', false);

        $this->assertSame('draft', OperationalAction::firstOrFail()->status);
        $this->assertSame('pending', Approval::firstOrFail()->status);
    }

    public function test_scoped_token_without_ops_draft_is_forbidden(): void
    {
        $user = $this->operator();
        $token = $user->createToken('eddy-reader', ['ops:read'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/eddy/agent/actions/propose', $this->barrierProposal())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'machine_ability_required');

        $this->assertSame(0, OperationalAction::count());
    }

    public function test_unknown_action_type_is_rejected(): void
    {
        $user = $this->operator();

        $this->actingAs($user)
            ->postJson('/api/eddy/actions/propose', $this->barrierProposal(['action_type' => 'rm_minus_rf']))
            ->assertStatus(422);
    }

    public function test_catalog_lists_the_proposable_actions(): void
    {
        $user = $this->operator();

        $this->actingAs($user)->getJson('/api/eddy/actions/catalog')
            ->assertOk()
            ->assertJsonPath('data.flag_barrier.tier', 'T1')
            ->assertJsonPath('data.propose_surge_plan.risk', 'critical')
            ->assertJsonPath('data.propose_surge_plan.phi_policy.patient_identifiers_allowed', false)
            ->assertJsonPath('data.propose_surge_plan.dry_run.required', true)
            ->assertJsonPath('data.propose_surge_plan.execution_adapter.direct_domain_mutation', false)
            ->assertJsonPath('data.propose_surge_plan.mobile_available', true);

        $catalog = $this->actingAs($user)->getJson('/api/eddy/actions/catalog')->json('data');
        foreach ($catalog as $actionType => $entry) {
            $this->assertArrayHasKey('minimum_role', $entry, "{$actionType} must declare minimum_role");
            $this->assertArrayHasKey('scope', $entry, "{$actionType} must declare scope");
            $this->assertArrayHasKey('phi_policy', $entry, "{$actionType} must declare phi_policy");
            $this->assertArrayHasKey('dry_run', $entry, "{$actionType} must declare dry_run");
            $this->assertArrayHasKey('execution_adapter', $entry, "{$actionType} must declare execution_adapter");
            $this->assertArrayHasKey('rollback', $entry, "{$actionType} must declare rollback");
            $this->assertArrayHasKey('audit', $entry, "{$actionType} must declare audit");
            $this->assertArrayHasKey('mobile_available', $entry, "{$actionType} must declare mobile availability");
        }
    }

    public function test_mint_agent_token_never_grants_ops_approve(): void
    {
        $user = $this->operator();

        $response = $this->actingAs($user)->postJson('/api/eddy/agent/token');

        $response->assertOk();
        $abilities = $response->json('data.abilities');
        $this->assertContains('ops:draft', $abilities);
        $this->assertNotContains('ops:approve', $abilities);
    }

    public function test_action_tiers_enforce_minimum_operator_role(): void
    {
        $bedManager = $this->operator('bed_manager');

        $this->actingAs($bedManager)
            ->postJson('/api/eddy/actions/propose', $this->barrierProposal([
                'action_type' => 'propose_surge_plan',
            ]))
            ->assertForbidden();

        $capacityLead = $this->operator('capacity_lead');

        $this->actingAs($capacityLead)
            ->postJson('/api/eddy/actions/propose', $this->barrierProposal([
                'action_type' => 'propose_surge_plan',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.tier', 'T3');
    }
}
