<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApiAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_session_api_families_reject_anonymous_requests(): void
    {
        foreach ([
            '/api/cockpit/snapshot',
            '/api/command-center/drilldown',
            '/api/facility/model/summary',
            '/api/patient-flow/summary',
            '/api/rtdc/units',
            '/api/transport/overview',
            '/api/evs/overview',
            '/api/staffing/overview',
            '/api/ops/graph/snapshot',
            '/api/eddy/actions/catalog',
            '/api/admin/integrations/health',
            '/api/deployment/service-lines',
            '/api/deployment/staffing/reference',
        ] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
    }

    public function test_public_legacy_read_routes_are_explicitly_public(): void
    {
        foreach ([
            '/api/health',
            '/api/cases/metrics',
            '/api/cases/room-status',
            '/api/blocks/utilization',
            '/api/blocks/service-utilization',
            '/api/blocks/room-utilization',
            '/api/services',
            '/api/rooms',
            '/api/providers',
            '/api/analytics/service-performance',
            '/api/analytics/provider-performance',
            '/api/analytics/historical-trends',
            '/api/improvement/api/nursing-operations',
        ] as $path) {
            $response = $this->getJson($path);

            $this->assertNotContains($response->status(), [401, 403], "{$path} unexpectedly requires auth.");
            $this->assertLessThan(500, $response->status(), "{$path} returned {$response->status()}.");
        }
    }

    public function test_admin_middleware_and_deployment_gates_are_distinct(): void
    {
        $frontline = User::factory()->create(['role' => 'user', 'must_change_password' => false]);
        $fieldAdmin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        $superuser = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $spatieAdmin = $this->spatieAdmin();

        $this->actingAs($frontline)->getJson('/api/cockpit/kpi-definitions')->assertForbidden();
        $this->actingAs($fieldAdmin)->getJson('/api/cockpit/kpi-definitions')->assertForbidden();
        $this->actingAs($spatieAdmin)->getJson('/api/cockpit/kpi-definitions')->assertOk();

        $this->actingAs($frontline)->getJson('/api/deployment/service-lines')->assertForbidden();
        $this->actingAs($fieldAdmin)->getJson('/api/deployment/service-lines')->assertOk();
        $this->actingAs($superuser)->getJson('/api/deployment/service-lines')->assertOk();

        $this->actingAs($fieldAdmin)->getJson('/api/deployment/staffing/reference')->assertForbidden();
        $this->actingAs($superuser)->getJson('/api/deployment/staffing/reference')->assertOk();

        $this->actingAs($frontline)->getJson('/api/admin/integrations/health')->assertForbidden();
        $this->actingAs($fieldAdmin)->getJson('/api/admin/integrations/health')->assertOk();
        $this->actingAs($fieldAdmin)->postJson('/api/admin/integrations/enterprise/fhir/capability-discovery', [
            'source_key' => 'epic.fhir.sandbox',
        ])->assertForbidden();
        $this->actingAs($superuser)->postJson('/api/admin/integrations/enterprise/fhir/capability-discovery', [
            'source_key' => 'epic.fhir.sandbox',
        ])->assertOk();
    }

    public function test_mobile_bff_requires_sanctum_read_and_act_abilities(): void
    {
        $user = User::factory()->create(['role' => 'bed_manager', 'must_change_password' => false]);

        $this->getJson('/api/mobile/v1/me')->assertUnauthorized();

        Sanctum::actingAs($user, ['password:change']);
        $this->getJson('/api/mobile/v1/me')->assertForbidden();
        $this->postJson('/api/mobile/v1/activity/00000000-0000-0000-0000-000000000000/ack')
            ->assertForbidden();

        Sanctum::actingAs($user, ['mobile:read']);
        $this->getJson('/api/mobile/v1/me')->assertOk();
        $this->postJson('/api/mobile/v1/activity/00000000-0000-0000-0000-000000000000/ack')
            ->assertForbidden();
    }

    public function test_eddy_agent_callback_requires_scoped_draft_ability(): void
    {
        $payload = [
            'action_type' => 'flag_barrier',
            'title' => 'Imaging delay blocking discharges',
            'surface' => 'rtdc',
            'rationale' => 'Two discharges are held on pending CT reads.',
            'runner_up' => 'Escalate to the radiology charge instead.',
            'params' => ['unit' => '3W', 'barrier' => 'imaging'],
        ];

        $this->postJson('/api/eddy/agent/actions/propose', $payload)->assertUnauthorized();

        $user = User::factory()->create(['must_change_password' => false]);
        Sanctum::actingAs($user, ['ops:read']);
        $this->postJson('/api/eddy/agent/actions/propose', $payload)->assertForbidden();

        Sanctum::actingAs($user, ['ops:read', 'ops:draft']);
        $this->postJson('/api/eddy/agent/actions/propose', $payload)->assertCreated();
    }

    private function spatieAdmin(): User
    {
        Role::findOrCreate('admin', 'web');

        $user = User::factory()->create([
            'role' => 'admin',
            'must_change_password' => false,
        ]);
        $user->assignRole('admin');

        return $user;
    }
}
