<?php

namespace App\Services\Patient\Messaging;

use App\Authorization\Capability;
use App\Models\PatientCommunication\PoolMembership;
use App\Models\PatientCommunication\ResponsibilityPool;
use App\Models\User;
use App\Services\Authorization\RoleCapabilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The single eligibility decision for staff who may receive patient messages.
 *
 * Pool membership alone is not enough: the membership must be effective and
 * reply-enabled, the canonical user must remain active, and the user's current
 * role/capability policy must still permit patient-communication responses.
 */
final class PatientCommunicationResponderEligibility
{
    private const MAX_ELIGIBILITY_SCAN = 200;

    public function __construct(private readonly RoleCapabilityService $authorization) {}

    public function poolHasEligibleResponder(ResponsibilityPool|int $pool, bool $lock = false): bool
    {
        $poolId = $pool instanceof ResponsibilityPool ? (int) $pool->getKey() : $pool;
        if ($poolId <= 0) {
            return false;
        }

        $memberships = $this->effectiveReplyMembershipQuery($poolId)
            ->orderBy('pool_membership_id')
            ->limit(self::MAX_ELIGIBILITY_SCAN);
        if ($lock) {
            $memberships->lockForUpdate();
        }

        foreach ($memberships->get() as $membership) {
            if ($this->eligibleUser($membership, $lock) instanceof User) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, PoolMembership> */
    public function eligibleMembershipsForPool(
        int $poolId,
        ?int $excludedUserId = null,
        bool $lock = false,
    ): Collection {
        if ($poolId <= 0) {
            return collect();
        }

        $memberships = $this->effectiveReplyMembershipQuery($poolId)
            ->when(
                $excludedUserId !== null,
                fn (Builder $query): Builder => $query->where('staff_user_id', '<>', $excludedUserId),
            )
            ->orderBy('pool_membership_id')
            ->limit(self::MAX_ELIGIBILITY_SCAN);
        if ($lock) {
            $memberships->lockForUpdate();
        }

        return $memberships->get()
            ->map(function (PoolMembership $membership) use ($lock): ?PoolMembership {
                $user = $this->eligibleUser($membership, $lock);
                if (! $user instanceof User) {
                    return null;
                }

                $membership->setRelation('user', $user);

                return $membership;
            })
            ->filter()
            ->values();
    }

    public function eligibleMembershipByUuid(
        string $membershipUuid,
        int $poolId,
        ?int $excludedUserId = null,
        bool $lock = false,
    ): ?PoolMembership {
        if ($poolId <= 0 || trim($membershipUuid) === '') {
            return null;
        }

        $query = $this->effectiveReplyMembershipQuery($poolId)
            ->where('membership_uuid', $membershipUuid)
            ->when(
                $excludedUserId !== null,
                fn (Builder $membership): Builder => $membership->where(
                    'staff_user_id',
                    '<>',
                    $excludedUserId,
                ),
            );
        if ($lock) {
            $query->lockForUpdate();
        }

        $membership = $query->first();
        if (! $membership instanceof PoolMembership) {
            return null;
        }

        $user = $this->eligibleUser($membership, $lock);
        if (! $user instanceof User) {
            return null;
        }

        $membership->setRelation('user', $user);

        return $membership;
    }

    /** @return Builder<PoolMembership> */
    private function effectiveReplyMembershipQuery(int $poolId): Builder
    {
        return PoolMembership::query()
            ->effective()
            ->where('responsibility_pool_id', $poolId)
            ->where('can_reply', true);
    }

    private function eligibleUser(PoolMembership $membership, bool $lock): ?User
    {
        $query = User::query()
            ->whereKey((int) $membership->staff_user_id)
            ->where('is_active', true);
        if ($lock) {
            $query->lockForUpdate();
        }

        $user = $query->first();
        if (! $user instanceof User
            || ! $this->authorization->allows($user, Capability::RespondPatientCommunications)
        ) {
            return null;
        }

        return $user;
    }
}
