<?php

namespace App\Services\Eddy;

use App\Models\Ops\Approval;
use App\Models\Ops\OperationalAction;
use App\Models\Ops\Recommendation;
use App\Models\User;
use App\Services\Ops\OperationalActionLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The "advice, not autopilot" action surface. Eddy PROPOSES; it never mutates a
 * domain table and never auto-executes. A proposal becomes governance records —
 * Recommendation(draft) → OperationalAction(draft) → Approval(pending) — that ride
 * the EXISTING OperationalActionLifecycleService inbox. A human (web session, which
 * can()s every ability) may approve; Eddy's scoped token (ops:draft, never
 * ops:approve) cannot. Every prescriptive proposal carries a runner-up + rationale.
 */
class EddyActionService
{
    /**
     * The allowlist of proposable action types. Each is a GOVERNANCE proposal
     * (a draft for human review), not a direct domain mutation.
     *
     * @var array<string, array{tier:string, risk:string, label:string, recommendation_type:string}>
     */
    public const CATALOG = [
        'flag_barrier' => ['tier' => 'T1', 'risk' => 'low', 'label' => 'Flag a throughput/discharge barrier', 'recommendation_type' => 'eddy_barrier'],
        'propose_huddle_action' => ['tier' => 'T1', 'risk' => 'low', 'label' => 'Propose a huddle action item', 'recommendation_type' => 'eddy_huddle_action'],
        'propose_transport_dispatch' => ['tier' => 'T2', 'risk' => 'medium', 'label' => 'Propose a transport dispatch', 'recommendation_type' => 'eddy_transport'],
        'propose_bed_placement' => ['tier' => 'T3', 'risk' => 'high', 'label' => 'Propose a bed placement', 'recommendation_type' => 'eddy_bed_placement'],
        'propose_surge_plan' => ['tier' => 'T3', 'risk' => 'critical', 'label' => 'Propose a surge / red-stretch plan', 'recommendation_type' => 'eddy_surge'],
    ];

    public function __construct(
        private readonly OperationalActionLifecycleService $lifecycle,
        private readonly EddyApprovalNotifier $notifier,
    ) {}

    public function catalog(): array
    {
        return self::CATALOG;
    }

    public function isProposable(string $actionType): bool
    {
        return array_key_exists($actionType, self::CATALOG);
    }

    /**
     * Create governance records for an Eddy proposal. When $approve is true AND the
     * actor is allowed to approve (web-session human — tokenCan('ops:approve')),
     * the proposal is immediately approved through the existing lifecycle service.
     * Eddy's scoped token can NEVER approve (it lacks ops:approve), so its proposals
     * always land as draft/pending for a human.
     *
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    public function propose(User $actor, array $proposal, bool $approve = false): array
    {
        $actionType = (string) ($proposal['action_type'] ?? '');
        if (! $this->isProposable($actionType)) {
            throw new InvalidArgumentException("Action type [{$actionType}] is not proposable by Eddy.");
        }

        $spec = self::CATALOG[$actionType];
        $title = trim((string) ($proposal['title'] ?? $spec['label']));
        $surface = (string) ($proposal['surface'] ?? 'house');

        $action = DB::transaction(function () use ($actor, $proposal, $actionType, $spec, $title, $surface): OperationalAction {
            $recommendation = Recommendation::create([
                'recommendation_uuid' => (string) Str::uuid(),
                'recommendation_type' => $spec['recommendation_type'],
                'scope_type' => $surface,
                'scope_key' => $proposal['scope_key'] ?? null,
                'title' => $title,
                'rationale' => $proposal['rationale'] ?? null,
                'risk_level' => $spec['risk'],
                'status' => 'draft',
                'created_by_source' => 'eddy',
                'expected_impact' => $proposal['expected_impact'] ?? [],
                'evidence' => [
                    'runner_up' => $proposal['runner_up'] ?? null,
                    'tier' => $spec['tier'],
                    'proposed_by' => 'eddy',
                ],
            ]);

            $action = OperationalAction::create([
                'action_uuid' => (string) Str::uuid(),
                'recommendation_id' => $recommendation->recommendation_id,
                'action_type' => $actionType,
                'status' => 'draft',
                'payload' => $proposal['params'] ?? [],
            ]);

            Approval::create([
                'approval_uuid' => (string) Str::uuid(),
                'action_id' => $action->action_id,
                'status' => 'pending',
                'requested_by_user_id' => $actor->id,
                'reason' => "Proposed by Eddy ({$spec['tier']}).",
            ]);

            return $action;
        });

        $approval = $action->approvals()->latest('approval_id')->firstOrFail();

        // $approve is the ALREADY-GATED decision (the controller enforces that only a
        // human may approve; Eddy's scoped token can never reach $approve === true).
        $approved = false;
        if ($approve) {
            $this->lifecycle->decideApproval($approval, 'approved', 'Approved via Eddy dock.', $actor->id);
            $approved = true;
        }

        // Pending (not auto-approved) → ring the PHI-free Hummingbird doorbell so an
        // approver can review on mobile. Resolves to the actor unless an explicit
        // approver target is given. No-op when push is disabled (the default).
        if (! $approved) {
            $approver = $this->resolveApprover($actor, $proposal['notify_user_id'] ?? null);
            $this->notifier->notifyApprover($approval->refresh(), $approver);
        }

        $action->refresh();

        return [
            'action_uuid' => $action->action_uuid,
            'approval_id' => $approval->approval_id,
            'approval_uuid' => $approval->approval_uuid,
            'action_type' => $actionType,
            'tier' => $spec['tier'],
            'risk' => $spec['risk'],
            'title' => $title,
            'status' => $action->status,        // 'approved' if auto-approved, else 'draft'
            'approved' => $approved,
        ];
    }

    /**
     * Resolve who should receive the approval doorbell. Defaults to the proposing
     * actor; an explicit, valid user id overrides (a future approver-routing policy
     * can supply this). An unresolvable id falls back to the actor — never null,
     * so the seam can't silently drop a pending approval.
     */
    private function resolveApprover(User $actor, mixed $notifyUserId): User
    {
        if ($notifyUserId !== null && (int) $notifyUserId !== (int) $actor->id) {
            $target = User::find((int) $notifyUserId);
            if ($target) {
                return $target;
            }
        }

        return $actor;
    }
}
