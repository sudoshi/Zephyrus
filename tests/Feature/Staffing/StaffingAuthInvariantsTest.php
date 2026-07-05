<?php

namespace Tests\Feature\Staffing;

use App\Models\Org\StaffMember;
use App\Models\Reference\StaffRole;
use App\Models\User;
use App\Services\Staffing\StaffProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase 7 GUARDRAIL (written first, red). StaffProvisioningService is the ONLY class
 * that writes prod.users, and only additively. It obeys .claude/rules/auth-system.md:
 * it can NEVER write password / must_change_password / email / username / role, it
 * NEVER touches the admin@acumenus.net superuser, and it never disturbs the
 * temp-password / must_change_password login flow. This test locks those invariants.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§10, §11)
 */
class StaffingAuthInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private function service(): StaffProvisioningService
    {
        return app(StaffProvisioningService::class);
    }

    private function seedRoles(): void
    {
        $this->artisan('deployment:seed-registry');
        $this->artisan('deployment:seed-staff-roles');
    }

    public function test_the_guarded_writer_refuses_every_protected_column(): void
    {
        $forbidden = [
            'password' => 'anything',
            'must_change_password' => false,
            'email' => 'evil@example.com',
            'username' => 'evil',
            'role' => 'super-admin',
            'name' => 'Mallory',
            'phone' => '555',
            'remember_token' => 'x',
        ];

        foreach ($forbidden as $column => $value) {
            $user = User::factory()->create(['role' => 'user']);
            try {
                $this->service()->applyOperationalAttributes($user, [$column => $value]);
                $this->fail("Expected a refusal writing prod.users.{$column}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($column, $e->getMessage());
            }
        }
    }

    public function test_a_forbidden_write_persists_nothing(): void
    {
        $user = User::factory()->create(['role' => 'user', 'email' => 'nurse@hospital.org', 'username' => 'nurse']);
        $originalWorkflow = $user->fresh()->workflow_preference; // DB default, applied at insert

        try {
            $this->service()->applyOperationalAttributes($user, [
                'workflow_preference' => 'rtdc',
                'password' => 'hacked', // one bad key voids the whole write
            ]);
        } catch (InvalidArgumentException) {
            // expected
        }

        $fresh = $user->fresh();
        $this->assertSame($originalWorkflow, $fresh->workflow_preference, 'a batch with a forbidden key must write nothing');
        $this->assertSame('nurse@hospital.org', $fresh->email);
        $this->assertSame('nurse', $fresh->username);
    }

    public function test_only_workflow_and_is_active_are_writable(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email' => 'rn@hospital.org',
            'username' => 'rn',
            'must_change_password' => true,
            'is_active' => false,
        ]);
        $originalPassword = $user->password;

        $applied = $this->service()->applyOperationalAttributes($user, [
            'workflow_preference' => 'rtdc',
            'is_active' => true,
        ]);

        $fresh = $user->fresh();
        $this->assertSame('rtdc', $fresh->workflow_preference);
        $this->assertTrue($fresh->is_active);
        // Auth-of-record columns are untouched.
        $this->assertSame('user', $fresh->role);
        $this->assertSame('rn@hospital.org', $fresh->email);
        $this->assertSame('rn', $fresh->username);
        $this->assertTrue($fresh->must_change_password, 'the login-flow flag must be preserved');
        $this->assertSame($originalPassword, $fresh->password);
        $this->assertEqualsCanonicalizing(['workflow_preference', 'is_active'], array_keys($applied));
    }

    public function test_the_superuser_row_is_never_mutated(): void
    {
        $superuser = User::factory()->create([
            'email' => 'admin@acumenus.net',
            'role' => 'super-admin',
            'must_change_password' => false,
            'is_active' => true,
            'workflow_preference' => 'command',
        ]);

        // Even a "legal" operational write is a no-op on the protected superuser.
        $applied = $this->service()->applyOperationalAttributes($superuser, [
            'workflow_preference' => 'rtdc',
            'is_active' => false,
        ]);

        $this->assertSame([], $applied);
        $fresh = $superuser->fresh();
        $this->assertFalse($fresh->must_change_password);
        $this->assertTrue($fresh->is_active);
        $this->assertSame('command', $fresh->workflow_preference);
        $this->assertSame('super-admin', $fresh->role);
    }

    public function test_provision_from_assignment_sets_only_operational_fields_and_never_role(): void
    {
        $this->seedRoles();

        $user = User::factory()->create([
            'role' => 'user',
            'email' => 'mgr@hospital.org',
            'must_change_password' => true,
            'is_active' => false,
        ]);
        $member = StaffMember::create([
            'staff_key' => 'HRIS:1',
            'source_system' => 'HRIS',
            'external_id' => '1',
            'user_id' => $user->id,
            'email' => 'mgr@hospital.org',
            'display_name' => 'Pat Manager',
        ]);
        // nurse_manager carries metadata.app_role = ops-leader — it must NOT be auto-applied.
        $role = StaffRole::findOrFail('nurse_manager');

        $delta = $this->service()->provisionFromAssignment($member, $role);

        $fresh = $user->fresh();
        $this->assertSame('rtdc', $fresh->workflow_preference); // nurse_manager default_workflow
        $this->assertTrue($fresh->is_active);
        $this->assertSame('user', $fresh->role, 'role is never auto-escalated by a sync');
        $this->assertTrue($fresh->must_change_password);
        // The recommended app role is surfaced for an explicit admin action, not applied.
        $this->assertSame('ops-leader', $delta['recommended_app_role'] ?? null);
    }

    public function test_provisioning_is_a_noop_when_no_account_is_linked(): void
    {
        $this->seedRoles();

        $member = StaffMember::create([
            'staff_key' => 'HRIS:2',
            'source_system' => 'HRIS',
            'external_id' => '2',
            'user_id' => null,
            'display_name' => 'Locum Only',
        ]);

        $delta = $this->service()->provisionFromAssignment($member, StaffRole::findOrFail('staff_nurse'));

        $this->assertSame(['provisioned' => false, 'reason' => 'no_linked_account'], $delta);
    }
}
