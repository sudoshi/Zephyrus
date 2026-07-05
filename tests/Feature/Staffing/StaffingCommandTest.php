<?php

namespace Tests\Feature\Staffing;

use App\Models\Org\StaffAssignment;
use App\Models\Org\StaffingSource;
use App\Models\Org\StaffMappingRule;
use App\Models\Reference\StaffRole;
use App\Models\User;
use App\Services\Deployment\ServiceLineNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Phase 7 CLI + RBAC: deployment:seed-staff-roles (idempotent taxonomy seed),
 * deployment:staffing-sync end-to-end through the connector factory + a CSV file,
 * and the manageDeploymentConfig authoring ability.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§6, §11)
 */
class StaffingCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('deployment:seed-registry');
        ServiceLineNormalizer::flush();
    }

    public function test_seed_staff_roles_is_idempotent_and_projects_taxonomy(): void
    {
        $this->artisan('deployment:seed-staff-roles')->assertExitCode(0);
        $first = StaffRole::count();
        $this->assertGreaterThan(20, $first);

        $this->artisan('deployment:seed-staff-roles')->assertExitCode(0);
        $this->assertSame($first, StaffRole::count(), 'reseeding upserts, never duplicates');

        $trauma = StaffRole::findOrFail('trauma_surgeon');
        $this->assertTrue($trauma->is_regulated);
        $this->assertTrue($trauma->is_provider);

        $chargeNurse = StaffRole::findOrFail('charge_nurse');
        $this->assertSame('rtdc', $chargeNurse->default_workflow);
        $this->assertTrue($chargeNurse->is_nursing);

        $this->assertSame('ops-leader', StaffRole::findOrFail('nurse_manager')->metadata['app_role']);
    }

    public function test_staffing_sync_command_dry_run_then_commit_with_csv_file(): void
    {
        $this->artisan('deployment:seed-staff-roles');

        StaffingSource::create([
            'source_key' => 'CSV_UPLOAD', 'connector_type' => 'manual', 'transport' => 'file_upload', 'mapping_template' => [],
        ]);
        StaffMappingRule::create([
            'match_field' => 'department', 'match_operator' => 'equals', 'match_value' => 'Critical Care',
            'target_service_line_code' => 'critical_care', 'target_role_code' => 'intensivist', 'confidence' => 0.95,
        ]);

        $path = tempnam(sys_get_temp_dir(), 'roster').'.csv';
        file_put_contents($path, "external_id,display_name,department\nEMP1,Ada Intensivist,Critical Care\n");

        try {
            // Dry run writes nothing.
            $this->artisan('deployment:staffing-sync', ['source' => 'CSV_UPLOAD', '--facility' => 'SUMMIT_REGIONAL', '--file' => $path])
                ->assertExitCode(0);
            $this->assertSame(0, StaffAssignment::count());

            // Commit writes the auto-approved assignment + stamps last_synced_at.
            $this->artisan('deployment:staffing-sync', ['source' => 'CSV_UPLOAD', '--facility' => 'SUMMIT_REGIONAL', '--file' => $path, '--commit' => true])
                ->assertExitCode(0);
            $this->assertSame(1, StaffAssignment::count());
            $this->assertNotNull(StaffingSource::firstOrFail()->last_synced_at);
        } finally {
            @unlink($path);
        }
    }

    public function test_staffing_sync_unknown_source_fails(): void
    {
        $this->artisan('deployment:staffing-sync', ['source' => 'NOPE', '--file' => '/dev/null'])
            ->assertExitCode(1);
    }

    public function test_manage_deployment_config_ability_is_narrow(): void
    {
        $allowed = ['superuser', 'ops-leader', 'super-admin'];
        $denied = ['admin', 'user', 'executive'];

        foreach ($allowed as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertTrue(Gate::forUser($user)->allows('manageDeploymentConfig'), "{$role} should author");
        }

        foreach ($denied as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertFalse(Gate::forUser($user)->allows('manageDeploymentConfig'), "{$role} must not author");
        }
    }
}
