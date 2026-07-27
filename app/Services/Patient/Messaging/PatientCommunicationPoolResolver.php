<?php

namespace App\Services\Patient\Messaging;

use App\Models\Encounter;
use App\Models\Facility\FacilitySpace;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\PatientCommunication\ResponsibilityPool;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves the one governed, currently accountable pool for patient messages.
 *
 * Every decision binds the exact policy, topic and keyed pool digest to the
 * active encounter's current unit/facility scope. Automatic routing prefers
 * unit, then facility, then enterprise and rejects ambiguous configurations.
 */
final class PatientCommunicationPoolResolver
{
    private const MAX_CANDIDATE_ROWS = 4;

    public function __construct(
        private readonly PatientCommunicationResponderEligibility $responders,
    ) {}

    /** @return array{unit_id: int, facility_key: string|null}|null */
    public function scopeForGrant(PatientEncounterAccessGrant $grant, bool $lock = false): ?array
    {
        $encounterId = (int) $grant->source_encounter_id;
        if ($encounterId <= 0) {
            return null;
        }

        $query = Encounter::query()
            ->whereKey($encounterId)
            ->where('status', 'active')
            ->whereNull('discharged_at')
            ->where('is_deleted', false);
        if ($lock) {
            $query->lockForUpdate();
        }

        $encounter = $query->first();
        if (! $encounter instanceof Encounter || (int) $encounter->unit_id <= 0) {
            return null;
        }

        return $this->scopeForUnit((int) $encounter->unit_id, $lock);
    }

    /** @return array{unit_id: int, facility_key: string|null}|null */
    public function scopeForUnit(int $unitId, bool $lock = false): ?array
    {
        if ($unitId <= 0 || ! in_array($unitId, $this->pilotUnitIds(), true)) {
            return null;
        }

        $query = Unit::query()
            ->whereKey($unitId)
            ->where('is_deleted', false);
        if ($lock) {
            $query->lockForUpdate();
        }

        $unit = $query->first();
        if (! $unit instanceof Unit) {
            return null;
        }

        if ($unit->facility_space_id === null) {
            return ['unit_id' => $unitId, 'facility_key' => null];
        }

        if (! Schema::hasTable('hosp_space.facility_spaces')
            || ! Schema::hasColumn('hosp_space.facility_spaces', 'facility_key')
        ) {
            return null;
        }

        $facilityQuery = FacilitySpace::query()
            ->whereKey((int) $unit->facility_space_id)
            ->where('status', 'active');
        if ($lock) {
            $facilityQuery->lockForUpdate();
        }

        $facilitySpace = $facilityQuery->first();
        if (! $facilitySpace instanceof FacilitySpace) {
            return null;
        }

        $facilityKey = (string) $facilitySpace->facility_key;
        if (! $this->validFacilityKey($facilityKey)) {
            return null;
        }

        return ['unit_id' => $unitId, 'facility_key' => $facilityKey];
    }

    public function resolveForGrant(
        string $policyVersion,
        string $topicCode,
        string $poolDigest,
        PatientEncounterAccessGrant $grant,
        bool $lock = false,
    ): ?ResponsibilityPool {
        $scope = $this->scopeForGrant($grant, $lock);

        return $scope === null
            ? null
            : $this->resolveForScope($policyVersion, $topicCode, $poolDigest, $scope, $lock);
    }

    public function resolveForUnit(
        string $policyVersion,
        string $topicCode,
        string $poolDigest,
        int $unitId,
        bool $lock = false,
    ): ?ResponsibilityPool {
        $scope = $this->scopeForUnit($unitId, $lock);

        return $scope === null
            ? null
            : $this->resolveForScope($policyVersion, $topicCode, $poolDigest, $scope, $lock);
    }

    /**
     * @param  array{unit_id: int, facility_key: string|null}  $scope
     */
    public function resolveForScope(
        string $policyVersion,
        string $topicCode,
        string $poolDigest,
        array $scope,
        bool $lock = false,
    ): ?ResponsibilityPool {
        $candidates = $this->unambiguousCandidates(
            $policyVersion,
            $topicCode,
            $poolDigest,
            $scope,
            $lock,
        );
        if ($candidates === null) {
            return null;
        }

        foreach (['unit', 'facility', 'enterprise'] as $scopeType) {
            $candidate = $candidates->first(
                fn (ResponsibilityPool $pool): bool => $pool->scope_type === $scopeType,
            );
            if ($candidate instanceof ResponsibilityPool
                && $this->responders->poolHasEligibleResponder($candidate, $lock)
            ) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Return all governed manual destinations in automatic-routing precedence.
     * Any same-scope ambiguity invalidates the complete candidate set.
     *
     * @param  array{unit_id: int, facility_key: string|null}  $scope
     * @return Collection<int, ResponsibilityPool>
     */
    public function eligibleCandidatesForScope(
        string $policyVersion,
        string $topicCode,
        string $poolDigest,
        array $scope,
        ?int $excludedPoolId = null,
        bool $lock = false,
    ): Collection {
        $candidates = $this->unambiguousCandidates(
            $policyVersion,
            $topicCode,
            $poolDigest,
            $scope,
            $lock,
        );
        if ($candidates === null) {
            return collect();
        }

        return $candidates
            ->reject(fn (ResponsibilityPool $pool): bool => $excludedPoolId !== null
                && (int) $pool->getKey() === $excludedPoolId)
            ->filter(fn (ResponsibilityPool $pool): bool => $this->responders
                ->poolHasEligibleResponder($pool, $lock))
            ->values();
    }

    /**
     * Validate one already-accountable pool. This method never substitutes a
     * different matching pool and is therefore safe for follow-up continuity.
     *
     * @param  array{unit_id: int, facility_key: string|null}  $scope
     */
    public function poolIsEligibleForScope(
        ResponsibilityPool $pool,
        string $policyVersion,
        string $topicCode,
        string $poolDigest,
        array $scope,
        bool $lockResponder = false,
    ): bool {
        return $this->poolMatchesScope(
            $pool,
            $policyVersion,
            $topicCode,
            $poolDigest,
            $scope,
            $lockResponder,
        )
            && $this->responders->poolHasEligibleResponder($pool, $lockResponder);
    }

    /**
     * Validate route identity and scope without changing source-pool actor
     * authorization into a destination-responder decision.
     *
     * @param  array{unit_id: int, facility_key: string|null}  $scope
     */
    public function poolMatchesScope(
        ResponsibilityPool $pool,
        string $policyVersion,
        string $topicCode,
        string $poolDigest,
        array $scope,
        bool $lock = false,
    ): bool {
        if (! $this->validRouteIdentity($policyVersion, $topicCode, $poolDigest)
            || ! $this->validScope($scope)
            || ! $this->poolMatchesRoute($pool, $policyVersion, $topicCode, $poolDigest, $scope)
        ) {
            return false;
        }

        $candidates = $this->unambiguousCandidates(
            $policyVersion,
            $topicCode,
            $poolDigest,
            $scope,
            $lock,
        );

        return $candidates !== null
            && $candidates->contains(
                fn (ResponsibilityPool $candidate): bool => (int) $candidate->getKey() === (int) $pool->getKey(),
            );
    }

    /** @return Collection<int, ResponsibilityPool>|null */
    private function unambiguousCandidates(
        string $policyVersion,
        string $topicCode,
        string $poolDigest,
        array $scope,
        bool $lock,
    ): ?Collection {
        if (! $this->validRouteIdentity($policyVersion, $topicCode, $poolDigest)
            || ! $this->validScope($scope)
        ) {
            return null;
        }

        $query = ResponsibilityPool::query()
            ->with('unit')
            ->where('routing_policy_version', $policyVersion)
            ->where('topic_code', $topicCode)
            ->where('pool_key_digest', $poolDigest)
            ->where('status', 'active')
            ->where(function (Builder $candidate) use ($scope): void {
                $candidate->where(function (Builder $unit) use ($scope): void {
                    $unit->where('scope_type', 'unit')
                        ->where('unit_id', $scope['unit_id'])
                        ->whereNull('facility_key');
                });
                if ($scope['facility_key'] !== null) {
                    $candidate->orWhere(function (Builder $facility) use ($scope): void {
                        $facility->where('scope_type', 'facility')
                            ->whereNull('unit_id')
                            ->where('facility_key', $scope['facility_key']);
                    });
                }
                $candidate->orWhere(function (Builder $enterprise): void {
                    $enterprise->where('scope_type', 'enterprise')
                        ->whereNull('unit_id')
                        ->whereNull('facility_key');
                });
            })
            ->orderByRaw("CASE scope_type WHEN 'unit' THEN 0 WHEN 'facility' THEN 1 ELSE 2 END")
            ->orderBy('responsibility_pool_id')
            ->limit(self::MAX_CANDIDATE_ROWS);
        if ($lock) {
            $query->lockForUpdate();
        }

        $candidates = $query->get();
        if ($candidates->contains(fn (ResponsibilityPool $pool): bool => ! $this->poolMatchesRoute(
            $pool,
            $policyVersion,
            $topicCode,
            $poolDigest,
            $scope,
        ))) {
            return null;
        }

        foreach (['unit', 'facility', 'enterprise'] as $scopeType) {
            if ($candidates->where('scope_type', $scopeType)->count() > 1) {
                return null;
            }
        }

        return $candidates;
    }

    /** @param array{unit_id: int, facility_key: string|null} $scope */
    private function poolMatchesRoute(
        ResponsibilityPool $pool,
        string $policyVersion,
        string $topicCode,
        string $poolDigest,
        array $scope,
    ): bool {
        if ($pool->status !== 'active'
            || ! hash_equals($policyVersion, (string) $pool->routing_policy_version)
            || ! hash_equals($topicCode, (string) $pool->topic_code)
            || ! hash_equals($poolDigest, (string) $pool->pool_key_digest)
            || (int) $pool->response_target_minutes < 1
            || (int) $pool->response_target_minutes > 10080
            || (int) $pool->escalation_target_minutes < (int) $pool->response_target_minutes
            || (int) $pool->escalation_target_minutes > 10080
        ) {
            return false;
        }

        return match ($pool->scope_type) {
            'unit' => (int) $pool->unit_id === $scope['unit_id'] && $pool->facility_key === null,
            'facility' => $pool->unit_id === null
                && $scope['facility_key'] !== null
                && hash_equals($scope['facility_key'], (string) $pool->facility_key),
            'enterprise' => $pool->unit_id === null && $pool->facility_key === null,
            default => false,
        };
    }

    private function validRouteIdentity(string $policyVersion, string $topicCode, string $poolDigest): bool
    {
        return $policyVersion !== ''
            && $policyVersion === trim($policyVersion)
            && mb_strlen($policyVersion) <= 120
            && preg_match('/^[a-z][a-z0-9_]{1,78}[a-z0-9]$/D', $topicCode) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $poolDigest) === 1;
    }

    /** @param array<string, mixed> $scope */
    private function validScope(array $scope): bool
    {
        if (count($scope) !== 2
            || ! array_key_exists('unit_id', $scope)
            || ! array_key_exists('facility_key', $scope)
            || ! is_int($scope['unit_id'] ?? null)
            || $scope['unit_id'] <= 0
        ) {
            return false;
        }

        return ($scope['facility_key'] ?? null) === null
            || (is_string($scope['facility_key']) && $this->validFacilityKey($scope['facility_key']));
    }

    private function validFacilityKey(string $facilityKey): bool
    {
        return $facilityKey !== ''
            && $facilityKey === trim($facilityKey)
            && mb_strlen($facilityKey) <= 120;
    }

    /** @return list<int> */
    private function pilotUnitIds(): array
    {
        return collect(config('hummingbird-patient.staff_messaging.pilot_unit_ids', []))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
