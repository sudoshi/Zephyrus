<?php

namespace Tests\Feature\Authorization;

use App\Authorization\AuthorizationScope;
use App\Authorization\Capability;
use App\Models\Auth\UserAccessScope;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\Org\StaffAssignment;
use App\Models\Org\StaffMember;
use App\Models\User;
use App\Services\Authorization\RoleCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class RoleCapabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private RoleCapabilityService $authorization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authorization = app(RoleCapabilityService::class);
    }

    public function test_scalar_and_spatie_roles_resolve_through_the_same_profiles(): void
    {
        $scalarAdmin = User::factory()->create(['role' => 'admin']);
        $spatieAdmin = User::factory()->create(['role' => 'user']);
        Role::findOrCreate('admin', 'web');
        $spatieAdmin->assignRole('admin');

        foreach ([Capability::ViewAdministration, Capability::ManageIdentity, Capability::ManagePrivileges] as $capability) {
            $this->assertTrue($this->authorization->allows($scalarAdmin, $capability));
            $this->assertTrue($this->authorization->allows($spatieAdmin, $capability));
        }

        $this->assertFalse($this->authorization->allows($scalarAdmin, Capability::ViewIntegrations));
        $this->assertFalse($this->authorization->allows($spatieAdmin, Capability::ViewIntegrations));

        $scalarSuperuser = User::factory()->create(['role' => 'super-user']);
        $spatieSuperuser = User::factory()->create(['role' => 'user']);
        Role::findOrCreate('superuser', 'web');
        $spatieSuperuser->assignRole('superuser');

        foreach ([Capability::ViewIntegrations, Capability::ManageIntegrationConfiguration, Capability::OperateIntegrations] as $capability) {
            $this->assertTrue($this->authorization->allows($scalarSuperuser, $capability));
            $this->assertTrue($this->authorization->allows($spatieSuperuser, $capability));
        }
    }

    public function test_separated_administrative_roles_do_not_collapse_into_admin(): void
    {
        $identityAdmin = User::factory()->create(['role' => 'identity-admin']);
        $integrationOperator = User::factory()->create(['role' => 'integration_operator']);
        $auditor = User::factory()->create(['role' => 'auditor']);

        $this->assertTrue($this->authorization->allows($identityAdmin, Capability::ManageIdentity));
        $this->assertFalse($this->authorization->allows($identityAdmin, Capability::ManagePrivileges));
        $this->assertFalse($this->authorization->allows($identityAdmin, Capability::ManageIntegrationConfiguration));

        $this->assertTrue($this->authorization->allows($integrationOperator, Capability::OperateIntegrations));
        $this->assertFalse($this->authorization->allows($integrationOperator, Capability::ManageIntegrationConfiguration));
        $this->assertFalse($this->authorization->allows($integrationOperator, Capability::ApproveIntegrationChanges));

        $this->assertTrue($this->authorization->allows($auditor, Capability::ViewAudit));
        $this->assertFalse($this->authorization->allows($auditor, Capability::ManageIdentity));
    }

    public function test_direct_spatie_permissions_accept_only_canonical_capability_names(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        Permission::findOrCreate('capability:manageDataStewardship', 'web');
        Permission::findOrCreate('not-a-capability', 'web');
        $user->givePermissionTo(['capability:manageDataStewardship', 'not-a-capability']);

        $this->assertTrue($this->authorization->allows($user, Capability::ManageDataStewardship));
        $this->assertSame(
            [Capability::ManageDataStewardship->value, Capability::MobileAct->value, Capability::MobileRead->value],
            array_map(fn (Capability $capability): string => $capability->value, $this->authorization->effectiveCapabilities($user)),
        );
    }

    public function test_inactive_accounts_have_no_effective_capabilities(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => false]);
        $decision = $this->authorization->decide($user, Capability::ManagePrivileges);

        $this->assertFalse($decision->allowed);
        $this->assertSame('account_inactive', $decision->reason);
        $this->assertSame([], $this->authorization->effectiveCapabilities($user));
        $this->assertSame([], $user->mobileTokenAbilities());
    }

    public function test_explicit_organization_and_facility_scopes_are_effective_dated_and_fail_closed(): void
    {
        [$first, $second] = $this->facilities();
        $user = User::factory()->create(['role' => 'facility_admin']);

        $missing = $this->authorization->decide(
            $user,
            Capability::ManageFacilityAdministration,
            AuthorizationScope::facility($first->facility_id),
        );
        $this->assertFalse($missing->allowed);
        $this->assertSame('scope_missing', $missing->reason);

        UserAccessScope::create([
            'user_id' => $user->id,
            'facility_id' => $first->facility_id,
            'granted_by_user_id' => $user->id,
            'grant_reason' => 'facility administrator assignment',
            'valid_from' => now()->subMinute(),
        ]);

        $facilityDecision = $this->authorization->decide(
            $user,
            Capability::ManageFacilityAdministration,
            AuthorizationScope::facility($first->facility_id),
        );
        $this->assertTrue($facilityDecision->allowed, json_encode($facilityDecision->toArray(), JSON_THROW_ON_ERROR));
        $this->assertFalse($this->authorization->allows(
            $user,
            Capability::ManageFacilityAdministration,
            AuthorizationScope::facility($second->facility_id),
        ));

        UserAccessScope::query()->update(['revoked_at' => now(), 'revocation_reason' => 'assignment ended']);
        UserAccessScope::create([
            'user_id' => $user->id,
            'organization_id' => $first->organization_id,
            'granted_by_user_id' => $user->id,
            'grant_reason' => 'regional administrator assignment',
            'valid_from' => now()->subMinute(),
            'valid_until' => now()->addDay(),
        ]);

        $this->assertTrue($this->authorization->allows(
            $user,
            Capability::ManageFacilityAdministration,
            AuthorizationScope::facility($first->facility_id),
        ));

        if ((int) $second->organization_id !== (int) $first->organization_id) {
            $this->assertFalse($this->authorization->allows(
                $user,
                Capability::ManageFacilityAdministration,
                AuthorizationScope::facility($second->facility_id),
            ));
        }
    }

    public function test_scope_rejects_unknown_facilities_and_organization_mismatch(): void
    {
        [$first, $second] = $this->facilities();
        $user = User::factory()->create(['role' => 'superuser']);

        $unknown = $this->authorization->decide(
            $user,
            Capability::ViewIntegrations,
            AuthorizationScope::facility(9_999_999),
        );
        $this->assertFalse($unknown->allowed);
        $this->assertSame('facility_not_found', $unknown->reason);

        if ((int) $first->organization_id !== (int) $second->organization_id) {
            $mismatch = $this->authorization->decide(
                $user,
                Capability::ViewIntegrations,
                AuthorizationScope::facility($first->facility_id, $second->organization_id),
            );
            $this->assertFalse($mismatch->allowed);
            $this->assertSame('scope_mismatch', $mismatch->reason);
        } else {
            $this->addToAssertionCount(2);
        }
    }

    public function test_workforce_assignment_scopes_only_operational_capabilities(): void
    {
        [$first, $second] = $this->facilities();
        $this->artisan('deployment:seed-staff-roles')->assertExitCode(0);
        $user = User::factory()->create(['role' => 'identity_admin']);
        $member = StaffMember::create([
            'staff_key' => 'authorization-test-'.$user->id,
            'source_system' => 'test',
            'external_id' => (string) $user->id,
            'user_id' => $user->id,
            'display_name' => $user->name,
            'is_active' => true,
        ]);
        StaffAssignment::create([
            'staff_member_id' => $member->staff_member_id,
            'facility_key' => $first->facility_key,
            'service_line_code' => 'hospital_medicine',
            'role_code' => 'house_supervisor',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);

        $this->assertTrue($this->authorization->allows(
            $user,
            Capability::ManageStaffingOperations,
            AuthorizationScope::facilityKey($first->facility_key),
        ));
        $this->assertFalse($this->authorization->allows(
            $user,
            Capability::ManageStaffingOperations,
            AuthorizationScope::facilityKey($second->facility_key),
        ));

        // Identity capability comes from the account role, but a workforce row
        // is deliberately insufficient evidence for its facility boundary.
        $decision = $this->authorization->decide(
            $user,
            Capability::ManageIdentity,
            AuthorizationScope::facilityKey($first->facility_key),
        );
        $this->assertFalse($decision->allowed);
        $this->assertSame('scope_missing', $decision->reason);
    }

    public function test_users_without_staff_identity_skip_the_assignment_ledger(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertSame(['user'], $this->authorization->effectiveRoleIds($user));

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertTrue($queries->contains(
            fn (string $query): bool => str_contains($query, 'hosp_org"."staff_members'),
        ));
        $this->assertFalse($queries->contains(
            fn (string $query): bool => str_contains($query, 'hosp_org"."staff_assignments'),
        ));
    }

    public function test_laravel_gates_and_mobile_abilities_are_adapters_to_the_service(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'workflow_preference' => 'rtdc']);
        $this->assertSame(
            $this->authorization->allows($admin, Capability::ManagePrivileges),
            Gate::forUser($admin)->allows('managePrivileges'),
        );
        $this->assertSame(['mobile:read', 'mobile:act', 'workflow:rtdc'], $admin->mobileTokenAbilities());

        $super = User::factory()->create(['role' => 'super-admin']);
        $this->assertSame(['*'], $super->mobileTokenAbilities());
    }

    /** @return array{Facility, Facility} */
    private function facilities(): array
    {
        $this->artisan('deployment:seed-registry')->assertExitCode(0);
        $firstOrganization = Organization::create([
            'organization_key' => 'AUTH_ORG_ONE',
            'name' => 'Authorization Test Organization One',
            'kind' => 'idn',
        ]);
        $secondOrganization = Organization::create([
            'organization_key' => 'AUTH_ORG_TWO',
            'name' => 'Authorization Test Organization Two',
            'kind' => 'idn',
        ]);
        $first = Facility::create([
            'organization_id' => $firstOrganization->organization_id,
            'facility_key' => 'AUTH_FACILITY_ONE',
            'facility_name' => 'Authorization Facility One',
            'idn_role' => 'flagship_quaternary_hub',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);
        $second = Facility::create([
            'organization_id' => $secondOrganization->organization_id,
            'facility_key' => 'AUTH_FACILITY_TWO',
            'facility_name' => 'Authorization Facility Two',
            'idn_role' => 'community_hospital',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);

        return [$first, $second];
    }
}
