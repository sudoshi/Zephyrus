<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Governed bulk deactivation. Preview simulates the exact execution order
 * inside a rolled-back transaction, so combined effects (for example a
 * selection that would remove every remaining active administrator) are
 * reported truthfully per member. Execution is all-or-nothing: any blocked
 * member rolls the entire batch back, and every applied member carries its
 * own append-only audit trail.
 */
class BulkAccountDeactivationService
{
    public const MAX_BATCH = 100;

    public function __construct(
        private readonly AccountLifecyclePolicy $lifecycle,
        private readonly AccountSessionService $sessions,
        private readonly UserAuditRecorder $audit,
    ) {}

    /**
     * @param  list<int>  $userIds
     * @return array{members: list<array<string, mixed>>, eligible_count: int, blocked_count: int}
     */
    public function preview(User $actor, array $userIds, Request $request): array
    {
        $members = [];
        DB::beginTransaction();
        try {
            $members = $this->simulate($actor, $userIds, $request, apply: true, failClosed: false);
        } finally {
            DB::rollBack();
        }

        $eligible = count(array_filter($members, fn (array $member): bool => $member['eligible']));
        $blocked = count($members) - $eligible;

        $this->audit->record('administration.user.bulk_deactivation_previewed', 'administration', 'success', [
            'request' => $request,
            'actor' => $actor,
            'reason' => 'bulk_deactivation_preview',
            'metadata' => [
                'member_count' => count($members),
                'eligible_count' => $eligible,
                'blocked_count' => $blocked,
            ],
        ]);

        return [
            'members' => $members,
            'eligible_count' => $eligible,
            'blocked_count' => $blocked,
        ];
    }

    /**
     * @param  list<int>  $userIds
     * @return list<int> deactivated user ids
     *
     * @throws AccountLifecycleViolation when any member is blocked (nothing is applied)
     */
    public function execute(User $actor, array $userIds, string $reason, Request $request): array
    {
        return DB::transaction(function () use ($actor, $userIds, $reason, $request): array {
            $members = $this->simulate($actor, $userIds, $request, apply: true, failClosed: true, reason: $reason);
            $deactivated = array_map(
                fn (array $member): int => (int) $member['id'],
                array_filter($members, fn (array $member): bool => $member['eligible']),
            );

            $this->audit->record('administration.user.bulk_deactivation_executed', 'administration', 'success', [
                'request' => $request,
                'actor' => $actor,
                'reason' => $reason,
                'metadata' => [
                    'member_count' => count($deactivated),
                    'eligible_count' => count($deactivated),
                    'blocked_count' => 0,
                ],
            ]);

            return array_values($deactivated);
        });
    }

    /**
     * Walk the batch in a deterministic order, applying each deactivation so
     * later policy checks observe earlier members. Preview rolls this back;
     * execute commits it or throws on the first blocked member.
     *
     * @param  list<int>  $userIds
     * @return list<array<string, mixed>>
     */
    private function simulate(
        User $actor,
        array $userIds,
        Request $request,
        bool $apply,
        bool $failClosed,
        string $reason = 'bulk_deactivation_preview',
    ): array {
        $ids = array_values(array_unique(array_map(intval(...), $userIds)));
        sort($ids);

        $members = [];
        foreach ($ids as $id) {
            $target = User::query()->whereKey($id)->lockForUpdate()->first();

            if ($target === null) {
                $members[] = $this->blocked($id, null, 'user_not_found', 'This account no longer exists.', $failClosed);

                continue;
            }
            if (! (bool) $target->is_active) {
                $members[] = $this->blocked($id, $target, 'already_inactive', 'This account is already deactivated.', $failClosed);

                continue;
            }

            try {
                $this->lifecycle->assertUpdateAllowed($actor, $target, (string) $target->role, false, false, false);
            } catch (AccountLifecycleViolation $violation) {
                $members[] = $this->blocked($id, $target, $violation->reason, $violation->getMessage(), $failClosed);

                continue;
            }

            if ($apply) {
                $target->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();
                $this->sessions->revoke($target, $request, $reason);
                $this->audit->record('administration.user.deactivated', 'administration', 'success', [
                    'request' => $request,
                    'actor' => $actor,
                    'reason' => $reason,
                    'target_type' => 'user',
                    'target_id' => $target->getKey(),
                    'changes' => ['is_active' => ['from' => true, 'to' => false]],
                    'metadata' => ['changed_fields' => ['is_active']],
                ]);
            }

            $members[] = $this->member($id, $target, eligible: true);
        }

        return $members;
    }

    /** @return array<string, mixed> */
    private function blocked(int $id, ?User $target, string $reason, string $message, bool $failClosed): array
    {
        if ($failClosed) {
            throw new AccountLifecycleViolation($reason, 'user_ids', $message);
        }

        return $this->member($id, $target, eligible: false, blockedReason: $reason, blockedMessage: $message);
    }

    /** @return array<string, mixed> */
    private function member(
        int $id,
        ?User $target,
        bool $eligible,
        ?string $blockedReason = null,
        ?string $blockedMessage = null,
    ): array {
        return [
            'id' => $id,
            'name' => $target?->name,
            'username' => $target?->username,
            'role' => $target !== null ? (string) $target->role : null,
            'is_protected' => (bool) ($target?->is_protected ?? false),
            'eligible' => $eligible,
            'blocked_reason' => $blockedReason,
            'blocked_message' => $blockedMessage,
        ];
    }
}
