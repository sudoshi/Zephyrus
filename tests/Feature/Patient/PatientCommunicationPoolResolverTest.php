<?php

namespace Tests\Feature\Patient;

use App\Models\Encounter;
use App\Models\Facility\FacilitySpace;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\PatientCommunication\PoolMembership;
use App\Models\PatientCommunication\ResponsibilityPool;
use App\Models\Unit;
use App\Models\User;
use App\Services\Patient\Messaging\PatientCommunicationPoolResolver;
use App\Services\Patient\PatientHmac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatientCommunicationPoolResolverTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY_VERSION = 'resolver-test-policy-v1';

    private const TOPIC_CODE = 'care_question';

    private const POOL_KEY = 'resolver.test.care-team';

    public function test_responder_eligibility_requires_reply_permission_active_user_and_current_capability(): void
    {
        $unit = $this->createUnit();
        $this->configurePilot($unit);
        $pool = $this->createPool($unit);
        $staff = User::factory()->create(['role' => 'charge_nurse', 'is_active' => true]);
        $membership = $this->createMembership($pool, $staff);
        $resolver = $this->app->make(PatientCommunicationPoolResolver::class);
        $digest = $this->poolDigest();

        $this->assertSame(
            $pool->getKey(),
            $resolver->resolveForUnit(self::POLICY_VERSION, self::TOPIC_CODE, $digest, $unit->getKey())?->getKey(),
        );

        $membership->forceFill(['can_reply' => false])->save();
        $this->assertNull(
            $resolver->resolveForUnit(self::POLICY_VERSION, self::TOPIC_CODE, $digest, $unit->getKey()),
        );

        $membership->forceFill(['can_reply' => true])->save();
        $staff->forceFill(['is_active' => false])->save();
        $this->assertNull(
            $resolver->resolveForUnit(self::POLICY_VERSION, self::TOPIC_CODE, $digest, $unit->getKey()),
        );

        $staff->forceFill(['is_active' => true, 'role' => 'auditor'])->save();
        $this->assertNull(
            $resolver->resolveForUnit(self::POLICY_VERSION, self::TOPIC_CODE, $digest, $unit->getKey()),
        );

        $staff->forceFill(['role' => 'charge_nurse'])->save();
        $this->assertSame(
            $pool->getKey(),
            $resolver->resolveForUnit(self::POLICY_VERSION, self::TOPIC_CODE, $digest, $unit->getKey())?->getKey(),
        );
    }

    public function test_current_encounter_resolution_prefers_unit_then_matching_facility_then_enterprise(): void
    {
        $facilitySpace = FacilitySpace::query()->create([
            'space_code' => 'resolver-facility-'.Str::lower(Str::random(10)),
            'space_name' => 'Resolver Test Facility Unit',
            'space_category' => 'unit',
            'status' => 'active',
            'facility_key' => 'RESOLVER_FACILITY',
        ]);
        $unit = $this->createUnit($facilitySpace);
        $this->configurePilot($unit);
        $encounter = Encounter::query()->create([
            'patient_ref' => 'resolver-patient-'.Str::lower(Str::random(10)),
            'unit_id' => $unit->getKey(),
            'admitted_at' => now()->subDay(),
            'acuity_tier' => 2,
            'status' => 'active',
            'is_deleted' => false,
        ]);
        $grant = new PatientEncounterAccessGrant(['source_encounter_id' => $encounter->getKey()]);

        $unitPool = $this->createPool($unit, ['display_name' => 'Unit Resolver Team']);
        $facilityPool = $this->createPool($unit, [
            'scope_type' => 'facility',
            'unit_id' => null,
            'facility_key' => 'RESOLVER_FACILITY',
            'display_name' => 'Facility Resolver Team',
        ]);
        $enterprisePool = $this->createPool($unit, [
            'scope_type' => 'enterprise',
            'unit_id' => null,
            'display_name' => 'Enterprise Resolver Team',
        ]);
        $mismatchedFacilityPool = $this->createPool($unit, [
            'scope_type' => 'facility',
            'unit_id' => null,
            'facility_key' => 'ANOTHER_FACILITY',
            'display_name' => 'Wrong Facility Team',
        ]);

        $unitMembership = $this->createMembership(
            $unitPool,
            User::factory()->create(['role' => 'charge_nurse', 'is_active' => true]),
        );
        $facilityMembership = $this->createMembership(
            $facilityPool,
            User::factory()->create(['role' => 'bedside_nurse', 'is_active' => true]),
        );
        $this->createMembership(
            $enterprisePool,
            User::factory()->create(['role' => 'hospitalist', 'is_active' => true]),
        );
        $this->createMembership(
            $mismatchedFacilityPool,
            User::factory()->create(['role' => 'hospitalist', 'is_active' => true]),
        );

        $resolver = $this->app->make(PatientCommunicationPoolResolver::class);
        $digest = $this->poolDigest();
        $scope = $resolver->scopeForGrant($grant);
        $this->assertSame([
            'unit_id' => $unit->getKey(),
            'facility_key' => 'RESOLVER_FACILITY',
        ], $scope);
        $this->assertSame(
            [$unitPool->getKey(), $facilityPool->getKey(), $enterprisePool->getKey()],
            $resolver->eligibleCandidatesForScope(
                self::POLICY_VERSION,
                self::TOPIC_CODE,
                $digest,
                $scope,
            )->modelKeys(),
        );
        $this->assertSame(
            $unitPool->getKey(),
            $resolver->resolveForGrant(
                self::POLICY_VERSION,
                self::TOPIC_CODE,
                $digest,
                $grant,
            )?->getKey(),
        );

        $unitMembership->forceFill(['can_reply' => false])->save();
        $this->assertSame(
            $facilityPool->getKey(),
            $resolver->resolveForGrant(
                self::POLICY_VERSION,
                self::TOPIC_CODE,
                $digest,
                $grant,
            )?->getKey(),
        );

        $facilityMembership->forceFill(['can_reply' => false])->save();
        $this->assertSame(
            $enterprisePool->getKey(),
            $resolver->resolveForGrant(
                self::POLICY_VERSION,
                self::TOPIC_CODE,
                $digest,
                $grant,
            )?->getKey(),
        );
        $this->assertNotSame(
            $mismatchedFacilityPool->getKey(),
            $resolver->resolveForGrant(
                self::POLICY_VERSION,
                self::TOPIC_CODE,
                $digest,
                $grant,
            )?->getKey(),
        );

        $encounter->forceFill(['discharged_at' => now()])->save();
        $this->assertNull($resolver->resolveForGrant(
            self::POLICY_VERSION,
            self::TOPIC_CODE,
            $digest,
            $grant,
        ));

        $encounter->forceFill(['status' => 'discharged'])->save();
        $this->assertNull($resolver->resolveForGrant(
            self::POLICY_VERSION,
            self::TOPIC_CODE,
            $digest,
            $grant,
        ));
    }

    public function test_established_pool_validation_fails_closed_on_same_tier_ambiguity(): void
    {
        $unit = $this->createUnit();
        $this->configurePilot($unit);
        $pool = $this->createPool($unit);
        $this->createMembership(
            $pool,
            User::factory()->create(['role' => 'charge_nurse', 'is_active' => true]),
        );
        $resolver = $this->app->make(PatientCommunicationPoolResolver::class);
        $digest = $this->poolDigest();
        $scope = $resolver->scopeForUnit($unit->getKey());
        $this->assertNotNull($scope);
        $this->assertTrue($resolver->poolIsEligibleForScope(
            $pool,
            self::POLICY_VERSION,
            self::TOPIC_CODE,
            $digest,
            $scope,
        ));

        // Production uniqueness prevents this state. Temporarily remove and
        // then explicitly restore that index to verify the resolver remains
        // fail-closed if storage invariants are ever bypassed.
        DB::statement('DROP INDEX patient_communications.uq_patient_communications_pool_scope');
        $duplicate = null;
        try {
            $duplicate = $this->createPool($unit, ['display_name' => 'Ambiguous Unit Resolver Team']);

            $this->assertNull($resolver->resolveForUnit(
                self::POLICY_VERSION,
                self::TOPIC_CODE,
                $digest,
                $unit->getKey(),
            ));
            $this->assertFalse($resolver->poolIsEligibleForScope(
                $pool,
                self::POLICY_VERSION,
                self::TOPIC_CODE,
                $digest,
                $scope,
            ));
        } finally {
            $duplicate?->delete();
            DB::statement(<<<'SQL'
CREATE UNIQUE INDEX uq_patient_communications_pool_scope
    ON patient_communications.responsibility_pools (
        routing_policy_version,
        pool_key_digest,
        topic_code,
        scope_type,
        COALESCE(unit_id, 0),
        COALESCE(facility_key, '')
    )
SQL);
        }
    }

    private function configurePilot(Unit $unit): void
    {
        config(['hummingbird-patient.staff_messaging.pilot_unit_ids' => [$unit->getKey()]]);
    }

    private function createUnit(?FacilitySpace $facilitySpace = null): Unit
    {
        return Unit::query()->create([
            'name' => 'Resolver Test Unit '.Str::upper(Str::random(4)),
            'abbreviation' => Str::upper(Str::random(5)),
            'type' => 'med_surg',
            'staffed_bed_count' => 12,
            'ratio_floor' => 4,
            'access_standard_minutes' => 120,
            'facility_space_id' => $facilitySpace?->getKey(),
            'is_deleted' => false,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createPool(Unit $unit, array $overrides = []): ResponsibilityPool
    {
        return ResponsibilityPool::query()->create([
            'pool_uuid' => (string) Str::uuid7(),
            'pool_key_digest' => $this->poolDigest(),
            'topic_code' => self::TOPIC_CODE,
            'display_name' => 'Resolver Test Care Team',
            'routing_policy_version' => self::POLICY_VERSION,
            'scope_type' => 'unit',
            'unit_id' => $unit->getKey(),
            'status' => 'active',
            'response_target_minutes' => 30,
            'escalation_target_minutes' => 60,
            ...$overrides,
        ]);
    }

    private function createMembership(ResponsibilityPool $pool, User $staff): PoolMembership
    {
        return PoolMembership::query()->create([
            'membership_uuid' => (string) Str::uuid7(),
            'responsibility_pool_id' => $pool->getKey(),
            'staff_user_id' => $staff->getKey(),
            'membership_role' => 'responder',
            'availability_state' => 'active',
            'can_claim' => true,
            'can_reply' => true,
            'can_reroute' => false,
            'can_close' => false,
            'effective_from' => now()->subMinute(),
        ]);
    }

    private function poolDigest(): string
    {
        return $this->app->make(PatientHmac::class)->digest(
            'messaging-pool-ref',
            self::POLICY_VERSION.'|'.self::POOL_KEY,
        );
    }
}
