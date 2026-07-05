<?php

namespace Tests\Feature\Deployment;

use App\Models\User;
use Database\Seeders\SummitDeploymentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 6 read-side API (§6): the deployment console endpoints project the Layer-1/2/3/4
 * tables as JSON, gated by the viewDeploymentConsole ability.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 6, §6)
 */
class DeploymentApiTest extends TestCase
{
    use RefreshDatabase;

    private function privilegedUser(): User
    {
        return User::factory()->create(['role' => 'superuser']);
    }

    public function test_service_line_catalog_returns_the_registry(): void
    {
        $this->seed(SummitDeploymentSeeder::class);

        $data = $this->actingAs($this->privilegedUser())
            ->getJson('/api/deployment/service-lines')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data['service_lines']);
        $this->assertNotEmpty($data['capability_levels']);
        $this->assertNotEmpty($data['idn_roles']);

        $codes = array_column($data['service_lines'], 'code');
        $this->assertContains('trauma_acute_care_surgery', $codes);
    }

    public function test_organizations_index_and_show(): void
    {
        $this->seed(SummitDeploymentSeeder::class);
        $user = $this->privilegedUser();

        $orgs = $this->actingAs($user)->getJson('/api/deployment/organizations')->assertOk()->json('data');
        $this->assertContains('SUMMIT_HEALTH', array_column($orgs, 'organization_key'));

        $org = $this->actingAs($user)->getJson('/api/deployment/organizations/SUMMIT_HEALTH')->assertOk()->json('data');
        $this->assertNotEmpty($org['markets']);
        $this->assertCount(5, $org['facilities']); // flagship + 4 affiliates

        $this->actingAs($user)->getJson('/api/deployment/organizations/NOPE')->assertNotFound();
    }

    public function test_facilities_index_filters_by_role_and_service_line(): void
    {
        $this->seed(SummitDeploymentSeeder::class);
        $user = $this->privilegedUser();

        $affiliates = $this->actingAs($user)
            ->getJson('/api/deployment/facilities?idn_role=community_hospital')
            ->assertOk()->json('data');
        $this->assertCount(4, $affiliates);
        foreach ($affiliates as $f) {
            $this->assertSame('community_hospital', $f['idn_role']);
        }

        $trauma = $this->actingAs($user)
            ->getJson('/api/deployment/facilities?service_line=trauma_acute_care_surgery')
            ->assertOk()->json('data');
        $this->assertContains('SUMMIT_REGIONAL', array_column($trauma, 'facility_key'));
    }

    public function test_facility_show_includes_capabilities_and_transfers(): void
    {
        $this->seed(SummitDeploymentSeeder::class);

        $data = $this->actingAs($this->privilegedUser())
            ->getJson('/api/deployment/facilities/SUMMIT_REGIONAL')
            ->assertOk()->json('data');

        $this->assertSame('SUMMIT_REGIONAL', $data['facility']['facility_key']);
        $this->assertNotEmpty($data['capabilities']);
        $this->assertContains('trauma_acute_care_surgery', array_column($data['capabilities'], 'service_line_code'));
        // Summit is the inbound hub for the affiliate trauma transfers.
        $this->assertNotEmpty($data['transfers']);
    }

    public function test_capability_matrix_requires_facility_and_returns_cells(): void
    {
        $this->seed(SummitDeploymentSeeder::class);
        $user = $this->privilegedUser();

        $this->actingAs($user)->getJson('/api/deployment/capability-matrix')->assertStatus(422);

        $data = $this->actingAs($user)
            ->getJson('/api/deployment/capability-matrix?facility=SUMMIT_REGIONAL')
            ->assertOk()->json('data');

        $this->assertSame('SUMMIT_REGIONAL', $data['facility_key']);
        $this->assertNotEmpty($data['cells']);
        foreach ($data['cells'] as $cell) {
            $this->assertArrayHasKey('capability_level', $cell);
        }
    }

    public function test_facility_spaces_returns_mapped_service_lines(): void
    {
        $this->seed(SummitDeploymentSeeder::class);
        $now = Carbon::parse('2026-07-05 00:00:00');

        $spaceId = DB::table('hosp_space.facility_spaces')->insertGetId([
            'space_code' => 'MICU3',
            'space_name' => 'Medical ICU',
            'space_category' => 'unit',
            'floor_number' => 3,
            'service_line_code' => 'critical_care',
            'acuity_level' => 'icu',
            'facility_key' => 'SUMMIT_REGIONAL',
            'status' => 'active',
            'attributes' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ], 'facility_space_id');

        DB::table('hosp_space.facility_space_service_lines')->insert([
            'facility_space_id' => $spaceId,
            'service_line_code' => 'critical_care',
            'primary_flag' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $data = $this->actingAs($this->privilegedUser())
            ->getJson('/api/deployment/facilities/SUMMIT_REGIONAL/spaces')
            ->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('MICU3', $data[0]['space_code']);
        $this->assertContains('critical_care', $data[0]['service_lines']);
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/deployment/service-lines')->assertUnauthorized();
        $this->getJson('/api/deployment/organizations')->assertUnauthorized();
    }

    public function test_endpoints_reject_non_privileged_roles(): void
    {
        $frontline = User::factory()->create(['role' => 'user']);

        $this->actingAs($frontline)->getJson('/api/deployment/service-lines')->assertForbidden();
        $this->actingAs($frontline)->getJson('/api/deployment/facilities')->assertForbidden();
    }
}
