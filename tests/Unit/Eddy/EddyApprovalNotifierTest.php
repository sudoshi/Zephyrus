<?php

namespace Tests\Unit\Eddy;

use App\Contracts\PushNotifier;
use App\Models\Ops\Approval;
use App\Models\Ops\OperationalAction;
use App\Models\Ops\Recommendation;
use App\Models\User;
use App\Services\Eddy\EddyApprovalNotifier;
use Tests\TestCase;

/**
 * The PHI-free Hummingbird approval doorbell: correct tier derivation, an ID-only
 * payload (no params/rationale ever), and hard no-op gating.
 */
class EddyApprovalNotifierTest extends TestCase
{
    /** A pending, Eddy-sourced approval graph built in-memory (no DB). */
    private function approval(string $risk, string $actionType): Approval
    {
        $recommendation = new Recommendation([
            'risk_level' => $risk,
            'scope_type' => 'rtdc',
            'created_by_source' => 'eddy',
            'rationale' => 'PHI-ish rationale that must never ride the push',
        ]);
        $action = new OperationalAction([
            'action_type' => $actionType,
            'action_uuid' => 'action-uuid-1',
            'payload' => ['unit' => '4E', 'mrn' => 'SHOULD-NOT-LEAK'],
        ]);
        $action->setRelation('recommendation', $recommendation);

        $approval = new Approval(['status' => 'pending', 'approval_uuid' => 'approval-uuid-1']);
        $approval->setRelation('action', $action);

        return $approval;
    }

    private function bindSpy(): PushNotifier
    {
        $spy = new class implements PushNotifier
        {
            /** @var array<int, array{title:string, body:string, data:array<string,mixed>}> */
            public array $calls = [];

            public function sendToUser(User $user, string $title, string $body, array $data = []): int
            {
                $this->calls[] = ['title' => $title, 'body' => $body, 'data' => $data];

                return 1;
            }
        };

        $this->app->instance(PushNotifier::class, $spy);

        return $spy;
    }

    public function test_critical_risk_maps_to_tier_1_with_a_phi_free_payload(): void
    {
        config(['eddy.push.enabled' => true]);
        $spy = $this->bindSpy();

        $sent = $this->app->make(EddyApprovalNotifier::class)
            ->notifyApprover($this->approval('critical', 'propose_surge_plan'), User::factory()->make(['id' => 7]));

        $this->assertSame(1, $sent);
        $call = $spy->calls[0];

        $this->assertSame('tier_1', $call['data']['tier']);
        $this->assertSame('eddy_approval', $call['data']['kind']);
        $this->assertSame('approval-uuid-1', $call['data']['approval_uuid']);
        $this->assertSame('rtdc', $call['data']['surface']);

        // PHI guard: only ids/tier/deep-link ride the doorbell — never params/rationale.
        $flat = json_encode($call);
        $this->assertStringNotContainsString('SHOULD-NOT-LEAK', $flat);
        $this->assertStringNotContainsString('rationale', $flat);
        $this->assertArrayNotHasKey('params', $call['data']);
    }

    public function test_low_risk_maps_to_tier_3(): void
    {
        config(['eddy.push.enabled' => true]);
        $spy = $this->bindSpy();

        $this->app->make(EddyApprovalNotifier::class)
            ->notifyApprover($this->approval('low', 'flag_barrier'), User::factory()->make(['id' => 7]));

        $this->assertSame('tier_3', $spy->calls[0]['data']['tier']);
    }

    public function test_no_op_when_push_disabled(): void
    {
        config(['eddy.push.enabled' => false]);
        $spy = $this->bindSpy();

        $sent = $this->app->make(EddyApprovalNotifier::class)
            ->notifyApprover($this->approval('critical', 'propose_surge_plan'), User::factory()->make(['id' => 7]));

        $this->assertSame(0, $sent);
        $this->assertCount(0, $spy->calls);
    }

    public function test_no_op_for_non_eddy_sourced_approvals(): void
    {
        config(['eddy.push.enabled' => true]);
        $spy = $this->bindSpy();

        $approval = $this->approval('critical', 'propose_surge_plan');
        $approval->action->recommendation->created_by_source = 'rules';   // not Eddy

        $sent = $this->app->make(EddyApprovalNotifier::class)
            ->notifyApprover($approval, User::factory()->make(['id' => 7]));

        $this->assertSame(0, $sent);
        $this->assertCount(0, $spy->calls);
    }
}
