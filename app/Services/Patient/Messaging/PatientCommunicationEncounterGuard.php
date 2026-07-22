<?php

namespace App\Services\Patient\Messaging;

use App\Models\Encounter;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientMessageThread;
use App\Models\PatientCommunication\ResponsibilityPool;
use App\Models\PatientCommunication\ThreadWorkItem;

/**
 * Resolves the live census encounter behind a patient-scoped access grant.
 *
 * Grant authorization and encounter lifecycle are deliberately separate
 * decisions. A still-effective grant must not disclose messaging resources or
 * accept new work after the canonical encounter is missing, deleted, or no
 * longer active.
 */
class PatientCommunicationEncounterGuard
{
    public function __construct(private readonly PatientCommunicationPoolResolver $pools) {}

    public function assertDisclosable(PatientEncounterAccessGrant $grant): Encounter
    {
        $encounter = $this->currentActiveEncounter($grant);

        if ($encounter === null) {
            throw PatientMessagingFailure::notFound();
        }

        return $encounter;
    }

    public function assertFreshMutationRoutable(PatientEncounterAccessGrant $grant): Encounter
    {
        $encounter = $this->currentActiveEncounter($grant);

        if ($encounter === null || $encounter->unit_id === null) {
            throw PatientMessagingFailure::unavailable();
        }

        return $encounter;
    }

    /**
     * A follow-up may reuse only an already-established accountable route.
     * Unit drift is left for a governed reroute transition; this guard never
     * mutates the work projection or appends transition facts itself.
     */
    public function assertFreshThreadMutationRoutable(
        PatientEncounterAccessGrant $grant,
        PatientMessageThread $thread,
    ): Encounter {
        $encounter = $this->assertFreshMutationRoutable($grant);
        $workItem = ThreadWorkItem::query()
            ->where('message_thread_id', $thread->getKey())
            ->lockForUpdate()
            ->first();

        if ($workItem === null
            || (int) $workItem->access_grant_id !== (int) $grant->getKey()
            || $workItem->status !== 'open'
        ) {
            throw PatientMessagingFailure::unavailable();
        }

        $pool = ResponsibilityPool::query()
            ->whereKey($workItem->responsibility_pool_id)
            ->sharedLock()
            ->first();

        $scope = $this->pools->scopeForUnit((int) $encounter->unit_id, true);

        if ($pool === null
            || $scope === null
            || ! $this->pools->poolIsEligibleForScope(
                $pool,
                (string) $thread->routing_policy_version,
                (string) $thread->topic_code,
                (string) $thread->responsibility_pool_ref_digest,
                $scope,
                true,
            )
        ) {
            throw PatientMessagingFailure::unavailable();
        }

        return $encounter;
    }

    /**
     * Return the current active census row for internal routing decisions.
     * Callers must still enforce their own policy, pilot, and pool checks.
     */
    public function currentActiveEncounter(PatientEncounterAccessGrant $grant): ?Encounter
    {
        if ($grant->source_encounter_id === null) {
            return null;
        }

        return Encounter::query()
            ->whereKey((int) $grant->source_encounter_id)
            ->where('status', 'active')
            ->whereNull('discharged_at')
            ->where('is_deleted', false)
            ->sharedLock()
            ->first();
    }
}
