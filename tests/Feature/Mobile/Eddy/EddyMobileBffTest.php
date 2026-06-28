<?php

namespace Tests\Feature\Mobile\Eddy;

use App\Models\Eddy\EddyConversation;
use App\Models\Ops\Approval;
use App\Models\Ops\OperationalAction;
use App\Models\Ops\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 5 — the Hummingbird Eddy mobile BFF. Verifies the mobile envelope, the
 * origin=hummingbird tagging, Eddy-only approval inbox filtering, the mobile:act
 * gate on the approval decision, and user isolation. The native Compose/SwiftUI
 * screens (separate repo) consume exactly these endpoints.
 */
class EddyMobileBffTest extends TestCase
{
    use RefreshDatabase;

    private function fakeEddy(): void
    {
        Http::fake([
            '*/eddy/chat' => Http::response([
                'reply' => 'Net bed need is 12; suggest 2 early geri transfers.',
                'provider' => 'ollama',
                'model' => 'medgemma',
                'status' => 'success',
            ], 200),
        ]);
    }

    /** Create a pending Eddy-sourced approval requested by $user. */
    private function eddyApproval(User $user, string $source = 'eddy', string $actionType = 'flag_barrier'): Approval
    {
        $recommendation = Recommendation::create([
            'recommendation_uuid' => (string) Str::uuid(),
            'recommendation_type' => 'eddy_barrier',
            'scope_type' => 'rtdc',
            'title' => 'Imaging delay blocking 3W discharges',
            'rationale' => 'Two discharges held on pending CT reads.',
            'risk_level' => 'low',
            'status' => 'draft',
            'created_by_source' => $source,
            'expected_impact' => [],
            'evidence' => ['runner_up' => 'Escalate to radiology charge.', 'tier' => 'T1'],
        ]);

        $action = OperationalAction::create([
            'action_uuid' => (string) Str::uuid(),
            'recommendation_id' => $recommendation->recommendation_id,
            'action_type' => $actionType,
            'status' => 'draft',
            'payload' => ['unit' => '3W', 'barrier' => 'imaging'],
        ]);

        return Approval::create([
            'approval_uuid' => (string) Str::uuid(),
            'action_id' => $action->action_id,
            'status' => 'pending',
            'requested_by_user_id' => $user->id,
            'reason' => 'Proposed by Eddy (T1).',
        ]);
    }

    public function test_mobile_chat_returns_an_envelope_and_tags_origin_hummingbird(): void
    {
        $this->fakeEddy();
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['mobile:read']);

        $this->postJson('/api/mobile/v1/eddy/chat', ['message' => 'what is the net bed need?', 'surface' => 'rtdc'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['conversation_id', 'status', 'message'], 'meta' => ['as_of', 'stale'], 'links'])
            ->assertJsonPath('data.status', 'success');

        $conversation = EddyConversation::forUser($user->id)->firstOrFail();
        $this->assertSame('hummingbird', $conversation->origin);
        $this->assertSame('rtdc', $conversation->surface);
    }

    public function test_mobile_chat_requires_a_token(): void
    {
        $this->postJson('/api/mobile/v1/eddy/chat', ['message' => 'hi'])->assertStatus(401);
    }

    public function test_inbox_lists_only_eddy_sourced_pending_approvals_for_the_user(): void
    {
        $user = User::factory()->create();
        $this->eddyApproval($user, 'eddy');
        $this->eddyApproval($user, 'rules');           // not Eddy → excluded
        Sanctum::actingAs($user, ['mobile:read']);

        $response = $this->getJson('/api/mobile/v1/eddy/approvals')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('flag_barrier', $response->json('data.0.action_type'));
        $this->assertSame('T1', $response->json('data.0.tier'));
    }

    public function test_inbox_is_user_scoped_for_non_admins(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->eddyApproval($owner, 'eddy');
        Sanctum::actingAs($other, ['mobile:read']);

        $this->getJson('/api/mobile/v1/eddy/approvals')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_approval_show_returns_a_phi_minimized_preview(): void
    {
        $user = User::factory()->create();
        $approval = $this->eddyApproval($user, 'eddy');
        Sanctum::actingAs($user, ['mobile:read']);

        $this->getJson("/api/mobile/v1/eddy/approvals/{$approval->approval_uuid}")
            ->assertOk()
            ->assertJsonPath('data.action_type', 'flag_barrier')
            ->assertJsonPath('data.runner_up', 'Escalate to radiology charge.')
            ->assertJsonStructure(['data' => ['preview', 'params', 'rationale']]);
    }

    public function test_decision_requires_the_mobile_act_ability(): void
    {
        $user = User::factory()->create();
        $approval = $this->eddyApproval($user, 'eddy');
        Sanctum::actingAs($user, ['mobile:read']);   // read-only token

        $this->postJson("/api/mobile/v1/eddy/approvals/{$approval->approval_uuid}/decision", ['decision' => 'approved'])
            ->assertForbidden();

        $this->assertSame('pending', $approval->fresh()->status);
    }

    public function test_a_human_with_mobile_act_can_approve_on_mobile(): void
    {
        $user = User::factory()->create();
        $approval = $this->eddyApproval($user, 'eddy');
        Sanctum::actingAs($user, ['mobile:read', 'mobile:act']);

        $this->postJson("/api/mobile/v1/eddy/approvals/{$approval->approval_uuid}/decision", ['decision' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.decision', 'approved')
            ->assertJsonPath('data.action_status', 'approved');

        $this->assertSame('approved', $approval->fresh()->status);
    }
}
