<?php

namespace App\Services\Ops;

use App\Models\Ops\Approval;
use App\Models\Ops\OperationalAction;
use App\Models\Ops\Recommendation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OperationalActionLifecycleService
{
    private const TERMINAL_STATUSES = ['completed', 'rejected', 'overridden', 'expired'];

    /** @return array<string,mixed> */
    public function inbox(): array
    {
        $pendingApprovals = Approval::query()
            ->with(['action.recommendation'])
            ->where('status', 'pending')
            ->orderBy('requested_at')
            ->limit(50)
            ->get();

        $activeActions = OperationalAction::query()
            ->with(['recommendation', 'approvals'])
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->orderByRaw('due_at ASC NULLS LAST')
            ->orderBy('created_at')
            ->limit(50)
            ->get();

        return [
            'generatedAtIso' => now()->toIso8601String(),
            'summary' => [
                'pendingApprovals' => $pendingApprovals->count(),
                'activeActions' => $activeActions->count(),
                'approvedActions' => $activeActions->where('status', 'approved')->count(),
                'assignedActions' => $activeActions->where('status', 'assigned')->count(),
                'executingActions' => $activeActions->where('status', 'executing')->count(),
                'overdueActions' => $activeActions
                    ->filter(fn (OperationalAction $action): bool => $this->isOverdue($action))
                    ->count(),
            ],
            'approvals' => $pendingApprovals
                ->map(fn (Approval $approval): array => $this->serializeApprovalQueueItem($approval))
                ->values()
                ->all(),
            'actions' => $activeActions
                ->map(fn (OperationalAction $action): array => $this->serializeAction($action))
                ->values()
                ->all(),
        ];
    }

    public function decideApproval(Approval $approval, string $decision, ?string $reason, ?int $userId): OperationalAction
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new RuntimeException('Approval decision must be approved or rejected.');
        }

        if ($approval->status !== 'pending') {
            throw new RuntimeException('Only pending approvals can be decided.');
        }

        return DB::transaction(function () use ($approval, $decision, $reason, $userId): OperationalAction {
            /** @var Approval $approval */
            $approval = Approval::query()
                ->whereKey($approval->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            /** @var OperationalAction $action */
            $action = OperationalAction::query()
                ->with(['recommendation', 'approvals'])
                ->whereKey($approval->action_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($approval->status !== 'pending') {
                throw new RuntimeException('Only pending approvals can be decided.');
            }

            $approval->fill([
                'status' => $decision,
                'decided_by_user_id' => $userId,
                'decided_at' => now(),
                'reason' => $reason ?: $approval->reason,
            ])->save();

            if ($decision === 'approved') {
                $action->fill([
                    'status' => 'approved',
                    'approved_by_user_id' => $userId,
                    'approved_at' => now(),
                ])->save();
            } else {
                $action->fill([
                    'status' => 'rejected',
                    'override_reason' => $reason,
                ])->save();
            }

            $this->syncRecommendationStatus($action->recommendation);

            return $action->refresh()->load(['recommendation', 'approvals']);
        });
    }

    /** @param array<string,mixed> $payload */
    public function assign(OperationalAction $action, array $payload, ?int $userId): OperationalAction
    {
        $this->assertActionStatus($action, ['approved', 'assigned'], 'Only approved actions can be assigned.');

        $action->fill([
            'status' => 'assigned',
            'owner_name' => $payload['owner_name'] ?? $action->owner_name ?? data_get($action->payload, 'owner'),
            'assigned_to_user_id' => $payload['assigned_to_user_id'] ?? $action->assigned_to_user_id ?? $userId,
            'assigned_at' => now(),
            'due_at' => array_key_exists('due_at', $payload) ? $this->parseDate($payload['due_at']) : $action->due_at,
            'expires_at' => array_key_exists('expires_at', $payload) ? $this->parseDate($payload['expires_at']) : $action->expires_at,
        ])->save();

        $this->syncRecommendationStatus($action->recommendation);

        return $action->refresh()->load(['recommendation', 'approvals']);
    }

    public function start(OperationalAction $action, ?int $userId): OperationalAction
    {
        $this->assertActionStatus($action, ['approved', 'assigned'], 'Only approved or assigned actions can be started.');

        $action->fill([
            'status' => 'executing',
            'executed_by_user_id' => $userId,
            'executed_at' => $action->executed_at ?? now(),
        ])->save();

        $this->syncRecommendationStatus($action->recommendation);

        return $action->refresh()->load(['recommendation', 'approvals']);
    }

    /** @param array<string,mixed> $completionPayload */
    public function complete(OperationalAction $action, array $completionPayload, ?int $userId): OperationalAction
    {
        $this->assertActionStatus($action, ['approved', 'assigned', 'executing'], 'Only active actions can be completed.');

        $action->fill([
            'status' => 'completed',
            'executed_by_user_id' => $action->executed_by_user_id ?? $userId,
            'executed_at' => $action->executed_at ?? now(),
            'completed_at' => now(),
            'completion_payload' => $completionPayload,
        ])->save();

        $this->syncRecommendationStatus($action->recommendation);

        return $action->refresh()->load(['recommendation', 'approvals']);
    }

    public function override(OperationalAction $action, string $reason, ?int $userId): OperationalAction
    {
        if (in_array($action->status, self::TERMINAL_STATUSES, true)) {
            throw new RuntimeException('Terminal actions cannot be overridden.');
        }

        $action->fill([
            'status' => 'overridden',
            'executed_by_user_id' => $userId,
            'overridden_at' => now(),
            'override_reason' => $reason,
        ])->save();

        $this->syncRecommendationStatus($action->recommendation);

        return $action->refresh()->load(['recommendation', 'approvals']);
    }

    public function expire(OperationalAction $action, ?string $reason, ?int $userId): OperationalAction
    {
        if (in_array($action->status, self::TERMINAL_STATUSES, true)) {
            throw new RuntimeException('Terminal actions cannot be expired.');
        }

        $action->fill([
            'status' => 'expired',
            'executed_by_user_id' => $userId,
            'expired_at' => now(),
            'override_reason' => $reason ?: 'Expired before execution.',
        ])->save();

        $this->syncRecommendationStatus($action->recommendation);

        return $action->refresh()->load(['recommendation', 'approvals']);
    }

    /** @return array<string,mixed> */
    public function serializeAction(OperationalAction $action): array
    {
        $action->loadMissing(['recommendation', 'approvals']);

        return [
            'actionId' => $action->action_id,
            'actionUuid' => $action->action_uuid,
            'recommendationId' => $action->recommendation_id,
            'recommendation' => $action->recommendation ? $this->serializeRecommendationBrief($action->recommendation) : null,
            'type' => $action->action_type,
            'status' => $action->status,
            'ownerName' => $action->owner_name,
            'assignedToUserId' => $action->assigned_to_user_id,
            'payload' => $action->payload ?? [],
            'completionPayload' => $action->completion_payload ?? [],
            'overrideReason' => $action->override_reason,
            'isOverdue' => $this->isOverdue($action),
            'approvedAtIso' => $action->approved_at?->toIso8601String(),
            'assignedAtIso' => $action->assigned_at?->toIso8601String(),
            'dueAtIso' => $action->due_at?->toIso8601String(),
            'expiresAtIso' => $action->expires_at?->toIso8601String(),
            'executedAtIso' => $action->executed_at?->toIso8601String(),
            'completedAtIso' => $action->completed_at?->toIso8601String(),
            'expiredAtIso' => $action->expired_at?->toIso8601String(),
            'overriddenAtIso' => $action->overridden_at?->toIso8601String(),
            'approvals' => $action->approvals
                ->map(fn (Approval $approval): array => $this->serializeApproval($approval))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string,mixed> */
    public function serializeApproval(Approval $approval): array
    {
        return [
            'approvalId' => $approval->approval_id,
            'approvalUuid' => $approval->approval_uuid,
            'actionId' => $approval->action_id,
            'status' => $approval->status,
            'reason' => $approval->reason,
            'requestedByUserId' => $approval->requested_by_user_id,
            'decidedByUserId' => $approval->decided_by_user_id,
            'requestedAtIso' => $approval->requested_at?->toIso8601String(),
            'decidedAtIso' => $approval->decided_at?->toIso8601String(),
        ];
    }

    private function assertActionStatus(OperationalAction $action, array $allowedStatuses, string $message): void
    {
        if (! in_array($action->status, $allowedStatuses, true)) {
            throw new RuntimeException($message);
        }
    }

    private function parseDate(?string $date): ?Carbon
    {
        return $date ? Carbon::parse($date) : null;
    }

    private function isOverdue(OperationalAction $action): bool
    {
        return $action->due_at !== null
            && now()->greaterThan($action->due_at)
            && ! in_array($action->status, self::TERMINAL_STATUSES, true);
    }

    private function syncRecommendationStatus(?Recommendation $recommendation): void
    {
        if (! $recommendation) {
            return;
        }

        /** @var Collection<int,OperationalAction> $actions */
        $actions = $recommendation->actions()->get();
        if ($actions->isEmpty()) {
            return;
        }

        $status = match (true) {
            $actions->contains(fn (OperationalAction $action): bool => $action->status === 'executing') => 'executing',
            $actions->every(fn (OperationalAction $action): bool => $action->status === 'completed') => 'completed',
            $actions->every(fn (OperationalAction $action): bool => $action->status === 'rejected') => 'rejected',
            $actions->every(fn (OperationalAction $action): bool => $action->status === 'expired') => 'expired',
            $actions->every(fn (OperationalAction $action): bool => $action->status === 'overridden') => 'overridden',
            $actions->contains(fn (OperationalAction $action): bool => $action->status === 'assigned') => 'assigned',
            $actions->contains(fn (OperationalAction $action): bool => $action->status === 'approved') => 'approved',
            default => 'draft',
        };

        $recommendation->forceFill(['status' => $status])->save();
    }

    /** @return array<string,mixed> */
    private function serializeApprovalQueueItem(Approval $approval): array
    {
        $approval->loadMissing(['action.recommendation']);

        return array_merge($this->serializeApproval($approval), [
            'action' => $approval->action ? $this->serializeAction($approval->action) : null,
        ]);
    }

    /** @return array<string,mixed> */
    private function serializeRecommendationBrief(Recommendation $recommendation): array
    {
        return [
            'recommendationId' => $recommendation->recommendation_id,
            'recommendationUuid' => $recommendation->recommendation_uuid,
            'type' => $recommendation->recommendation_type,
            'title' => $recommendation->title,
            'riskLevel' => $recommendation->risk_level,
            'status' => $recommendation->status,
        ];
    }
}
