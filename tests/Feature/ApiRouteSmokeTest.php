<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RtdcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiRouteSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_health_route_does_not_return_a_server_error(): void
    {
        $this->getJson('/api/health')->assertOk();
    }

    public function test_authenticated_legacy_read_api_routes_do_not_return_server_errors(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);

        foreach ([
            '/api/cases',
            '/api/cases/today',
            '/api/cases/metrics',
            '/api/cases/room-status',
            '/api/blocks',
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
            $response = $this->actingAs($user)->getJson($path);

            $this->assertLessThan(500, $response->status(), "{$path} returned {$response->status()}.");
        }
    }

    public function test_web_session_api_routes_do_not_return_server_errors_for_authorized_users(): void
    {
        $this->seed(RtdcSeeder::class);

        $user = User::factory()->create([
            'role' => 'bed_manager',
            'must_change_password' => false,
        ]);

        foreach ([
            '/api/cockpit/snapshot',
            '/api/cockpit/scopes',
            '/api/cockpit/face',
            '/api/cockpit/drill/rtdc',
            '/api/command-center/drilldown?domain=rtdc',
            '/api/facility/model/summary',
            '/api/patient-flow/summary',
            '/api/patient-flow/locations',
            '/api/patient-flow/ambient',
            '/api/patient-flow/demo-scenarios',
            '/api/patient-flow/projections',
            '/api/patient-flow/occupancy',
            '/api/patient-flow/occupancy/history',
            '/api/rtdc/units',
            '/api/rtdc/bed-meeting',
            '/api/rtdc/barriers',
            '/api/rtdc/bed-requests',
            '/api/radiology/flow-board',
            '/api/radiology/worklist',
            '/api/radiology/modality',
            '/api/radiology/reads',
            '/api/radiology/tat',
            '/api/radiology/ir-utilization',
            '/api/transport/overview',
            '/api/transport/regional-summary',
            '/api/transport/requests',
            '/api/transport/resources',
            '/api/transport/vendors',
            '/api/evs/overview',
            '/api/evs/requests',
            '/api/evs/resources',
            '/api/staffing/overview',
            '/api/staffing/plans',
            '/api/staffing/requests',
            '/api/staffing/resources',
            '/api/ops/graph/snapshot',
            '/api/ops/recommendations',
            '/api/ops/agent-inbox',
            '/api/ops/agents/definitions',
            '/api/eddy/actions/catalog',
            '/api/eddy/conversations',
            '/api/admin/integrations/health',
            '/api/admin/integrations/enterprise',
            '/api/analytics/overview',
            '/api/analytics/live',
            '/api/analytics/retrospective',
            '/api/analytics/predictive',
            '/api/analytics/process-intelligence',
            '/api/analytics/opportunities',
            '/api/analytics/workbench',
            '/api/analytics/data-quality',
        ] as $path) {
            $response = $this->actingAs($user)->getJson($path);

            $this->assertLessThan(500, $response->status(), "{$path} returned {$response->status()}.");
        }
    }

    public function test_privileged_deployment_api_routes_do_not_return_server_errors(): void
    {
        $superuser = User::factory()->create([
            'role' => 'superuser',
            'must_change_password' => false,
        ]);

        foreach ([
            '/api/deployment/service-lines',
            '/api/deployment/organizations',
            '/api/deployment/facilities',
            '/api/deployment/capability-matrix?facility=HOSP1',
            '/api/deployment/transfers',
            '/api/deployment/readiness/HOSP1',
            '/api/deployment/staffing/sources',
            '/api/deployment/staffing/rules',
            '/api/deployment/staffing/reference',
            '/api/deployment/staffing/coverage',
        ] as $path) {
            $response = $this->actingAs($superuser)->getJson($path);

            $this->assertLessThan(500, $response->status(), "{$path} returned {$response->status()}.");
        }
    }

    public function test_mobile_api_routes_do_not_return_server_errors_for_mobile_read_tokens(): void
    {
        $this->seed(RtdcSeeder::class);

        $user = User::factory()->create([
            'role' => 'bed_manager',
            'workflow_preference' => 'rtdc',
            'must_change_password' => false,
        ]);
        Sanctum::actingAs($user, ['mobile:read']);

        foreach ([
            '/api/mobile/v1/me',
            '/api/mobile/v1/realtime/config',
            '/api/mobile/v1/altitude/home?persona=bed_manager',
            '/api/mobile/v1/altitude/workspace/rtdc?persona=bed_manager',
            '/api/mobile/v1/activity?persona=bed_manager',
            '/api/mobile/v1/rtdc/census',
            '/api/mobile/v1/rtdc/house',
            '/api/mobile/v1/rtdc/bed-requests',
            '/api/mobile/v1/for-you?persona=bed_manager',
            '/api/mobile/v1/flow/floors',
            '/api/mobile/v1/flow/spaces3d',
            '/api/mobile/v1/flow/demo-scenarios?persona=bed_manager',
            '/api/mobile/v1/flow/occupancy/history?persona=bed_manager',
            '/api/mobile/v1/flow/window?persona=bed_manager',
            '/api/mobile/v1/transport/queue',
            '/api/mobile/v1/evs/queue',
            '/api/mobile/v1/command/house',
            '/api/mobile/v1/or/board',
            '/api/mobile/v1/ops/inbox',
            '/api/mobile/v1/staffing/overview',
            '/api/mobile/v1/improvement/pdsa',
            '/api/mobile/v1/improvement/opportunities',
            '/api/mobile/v1/eddy/conversations',
            '/api/mobile/v1/eddy/approvals',
            '/api/mobile/v1/eddy/context/house',
        ] as $path) {
            $response = $this->getJson($path);

            $this->assertLessThan(500, $response->status(), "{$path} returned {$response->status()}.");
        }
    }
}
