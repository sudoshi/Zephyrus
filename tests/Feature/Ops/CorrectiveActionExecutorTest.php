<?php

namespace Tests\Feature\Ops;

use App\Models\Barrier;
use App\Models\Ops\Approval;
use App\Models\Ops\OperationalAction;
use App\Models\PdsaCycle;
use App\Models\Unit;
use App\Models\User;
use App\Services\Eddy\EddyActionService;
use App\Services\Ops\CorrectiveActionExecutor;
use App\Services\Ops\OperationalActionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zephyrus 2.0 — Part X / Flow Reconciliation, seam 2 (the executor) + seam 3 (the
 * barrier link). Proves the "advice, not autopilot" contract has a real terminus:
 * a governed copilot draft touches NO domain table until a human approves, and then
 * — and only then — a prod.pdsa_cycles row is materialized from the drafted plan and
 * the originating barrier is pointed back at it. Also pins the invariants the
 * approval path depends on: idempotent (no duplicate cycle on re-run), scoped (only
 * the two copilot draft types materialize), and null on rejection.
 */
class CorrectiveActionExecutorTest extends TestCase
{
    use RefreshDatabase;

    private function unit(): Unit
    {
        return Unit::create(['name' => '5 East', 'abbreviation' => '5E', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
    }

    /** Create a pending governed draft via the real Eddy plane (notifier no-ops in tests). */
    private function propose(string $actionType, array $params, ?int $barrierId, string $rationale = 'Because the hand-off is slow.'): OperationalAction
    {
        // bed_manager clears the minimum-role gate for every proposable type this
        // suite exercises (propose_pdsa_cycle / propose_pathway_correction need
        // bed_manager; flag_barrier needs user — bed_manager outranks both).
        $actor = User::factory()->create(['role' => 'bed_manager']);
        $result = app(EddyActionService::class)->propose($actor, [
            'action_type' => $actionType,
            'title' => 'Reduce assign_bed → transport wait',
            'rationale' => $rationale,
            'surface' => 'arena',
            'barrier_id' => $barrierId,
            'params' => $params,
        ], approve: false);

        return OperationalAction::where('action_uuid', $result['action_uuid'])->firstOrFail();
    }

    private function approve(OperationalAction $action): OperationalAction
    {
        $approval = Approval::where('action_id', $action->action_id)->where('status', 'pending')->firstOrFail();

        return app(OperationalActionLifecycleService::class)
            ->decideApproval($approval, 'approved', 'Looks right.', $action->approved_by_user_id ?? 1);
    }

    public function test_approving_a_pdsa_draft_materializes_a_cycle_linked_to_its_barrier(): void
    {
        $unit = $this->unit();
        $barrier = Barrier::create([
            'unit_id' => $unit->unit_id, 'category' => 'placement', 'reason_code' => 'no_bed',
            'status' => 'open', 'opened_at' => now()->subHours(6), 'is_deleted' => false,
        ]);

        $pdsa = [
            'title' => 'Reduce hand-off wait — assign_bed→transport',
            'objective' => 'Cut the worst hand-off wait.',
            'hypothesis' => 'The sync constraint is the transport dispatch.',
            'prediction' => 'A 25% wait reduction in four PDSA weeks.',
            'proposed_status' => 'planned',
        ];
        $action = $this->propose('propose_pdsa_cycle', ['pdsa' => $pdsa, 'focus' => 'bottleneck', 'proposed_status' => 'planned'], $barrier->barrier_id);

        // Seam 3 threading: the draft remembers its barrier BEFORE approval.
        $this->assertSame((int) $barrier->barrier_id, (int) $action->recommendation->barrier_id);
        // Nothing materialized while the draft is merely pending.
        $this->assertSame(0, PdsaCycle::count());

        $this->approve($action);

        // Exactly one cycle, planned, anchored to the barrier's unit, built from the draft.
        $this->assertSame(1, PdsaCycle::count());
        $cycle = PdsaCycle::first();
        $this->assertSame('planned', $cycle->status);
        $this->assertSame((int) $unit->unit_id, (int) $cycle->unit_id);
        $this->assertSame($pdsa['title'], $cycle->title);
        $this->assertSame($pdsa['objective'], $cycle->objective);
        $this->assertSame($pdsa['hypothesis'], $cycle->rationale);
        $this->assertSame($pdsa['prediction'], $cycle->prediction);
        $this->assertNotNull($cycle->started_at);

        // The id is written back onto the action (idempotency + intervention attribution).
        $this->assertSame((int) $cycle->pdsa_cycle_id, (int) data_get($action->refresh()->payload, 'pdsa_cycle_id'));
        // Seam 3 closes: the barrier points at its resolution cycle.
        $this->assertSame((int) $cycle->pdsa_cycle_id, (int) $barrier->refresh()->pdsa_cycle_id);
    }

    public function test_materialize_is_idempotent(): void
    {
        $action = $this->propose('propose_pdsa_cycle', ['pdsa' => ['title' => 'Fix it'], 'focus' => 'bottleneck'], null);
        $this->approve($action);

        $this->assertSame(1, PdsaCycle::count());
        $first = PdsaCycle::first();

        // A retry (same action, id already stamped) returns the same cycle, mints none.
        $again = app(CorrectiveActionExecutor::class)->materialize($action->refresh()->load('recommendation'));
        $this->assertSame((int) $first->pdsa_cycle_id, (int) $again->pdsa_cycle_id);
        $this->assertSame(1, PdsaCycle::count());
    }

    public function test_pathway_correction_without_a_pdsa_plan_falls_back_to_the_recommendation(): void
    {
        $action = $this->propose(
            'propose_pathway_correction',
            ['pathway' => 'sepsis', 'conformance_pct' => 82.0, 'deviant' => 6, 'cases' => 41],
            null,
            rationale: 'Sepsis conformance is 82% — a governed correction is proposed.',
        );
        $this->approve($action);

        $this->assertSame(1, PdsaCycle::count());
        $cycle = PdsaCycle::first();
        $this->assertSame('planned', $cycle->status);
        $this->assertSame('Reduce assign_bed → transport wait', $cycle->title); // the recommendation title
        $this->assertSame('Sepsis conformance is 82% — a governed correction is proposed.', $cycle->objective);
    }

    public function test_non_materializing_action_type_is_a_no_op(): void
    {
        $action = $this->propose('flag_barrier', ['note' => 'stuck'], null);
        $this->approve($action);

        $this->assertSame(0, PdsaCycle::count());
        $this->assertNull(app(CorrectiveActionExecutor::class)->materialize($action->refresh()->load('recommendation')));
    }

    public function test_rejection_materializes_nothing(): void
    {
        $unit = $this->unit();
        $barrier = Barrier::create(['unit_id' => $unit->unit_id, 'category' => 'placement', 'status' => 'open', 'opened_at' => now()->subHours(3), 'is_deleted' => false]);
        $action = $this->propose('propose_pdsa_cycle', ['pdsa' => ['title' => 'x']], $barrier->barrier_id);

        $approval = Approval::where('action_id', $action->action_id)->firstOrFail();
        app(OperationalActionLifecycleService::class)->decideApproval($approval, 'rejected', 'Not now.', 1);

        $this->assertSame(0, PdsaCycle::count());
        $this->assertNull($barrier->refresh()->pdsa_cycle_id);
    }
}
