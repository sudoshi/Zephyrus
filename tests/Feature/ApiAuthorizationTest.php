<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
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
            '/api/radiology/flow-board',
            '/api/radiology/worklist',
            '/api/radiology/modality',
            '/api/radiology/reads',
            '/api/radiology/tat',
            '/api/radiology/ir-utilization',
            '/api/pharmacy/flow-board',
            '/api/pharmacy/discharge-readiness',
            '/api/pharmacy/iv-room',
            '/api/pharmacy/dispense',
            '/api/pharmacy/controlled',
            '/api/pharmacy/tat',
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

    public function test_legacy_clinical_and_reference_routes_reject_anonymous_requests(): void
    {
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
            $this->getJson($path)->assertUnauthorized();
        }

        $this->postJson('/api/blocks', [])->assertUnauthorized();
        $this->getJson('/api/health')->assertOk();
    }

    public function test_every_api_route_except_the_reviewed_public_allowlist_requires_authentication(): void
    {
        $publicAllowlist = [
            'api/health',
            'api/auth/token',
            // Reviewed public credential-entry routes. Both remain behind the
            // independent patient product/feature gates and named throttles.
            'api/patient/v1/auth/enroll/challenge/verify',
            'api/patient/v1/auth/token',
        ];

        $unprotected = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (IlluminateRoute $route): bool => str_starts_with($route->uri(), 'api/'))
            ->reject(fn (IlluminateRoute $route): bool => in_array($route->uri(), $publicAllowlist, true))
            ->reject(function (IlluminateRoute $route): bool {
                return collect($route->gatherMiddleware())->contains(
                    fn (string $middleware): bool => $middleware === 'auth'
                        || str_starts_with($middleware, 'auth:')
                        || str_contains($middleware, 'Authenticate'),
                );
            })
            ->map(fn (IlluminateRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $unprotected, 'API routes without an explicit authentication boundary.');
    }

    public function test_every_api_route_has_an_explicit_rate_limit(): void
    {
        $unthrottled = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (IlluminateRoute $route): bool => str_starts_with($route->uri(), 'api/'))
            ->reject(function (IlluminateRoute $route): bool {
                return collect($route->gatherMiddleware())->contains(
                    fn (string $middleware): bool => str_starts_with($middleware, 'throttle:')
                        || str_contains($middleware, 'ThrottleRequests'),
                );
            })
            ->map(fn (IlluminateRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $unthrottled, 'API routes without an explicit rate-limit class.');
    }

    public function test_admin_middleware_and_deployment_gates_are_distinct(): void
    {
        $frontline = User::factory()->create(['role' => 'user', 'must_change_password' => false]);
        $fieldAdmin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        $superuser = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $spatieAdmin = $this->spatieAdmin();

        $this->actingAs($frontline)->getJson('/api/cockpit/kpi-definitions')->assertForbidden();
        $this->actingAs($fieldAdmin)->getJson('/api/cockpit/kpi-definitions')->assertOk();
        $this->actingAs($spatieAdmin)->getJson('/api/cockpit/kpi-definitions')->assertOk();

        $this->actingAs($frontline)->getJson('/api/deployment/service-lines')->assertForbidden();
        $this->actingAs($fieldAdmin)->getJson('/api/deployment/service-lines')->assertOk();
        $this->actingAs($superuser)->getJson('/api/deployment/service-lines')->assertOk();

        $this->actingAs($fieldAdmin)->getJson('/api/deployment/staffing/reference')->assertForbidden();
        $this->actingAs($superuser)->getJson('/api/deployment/staffing/reference')->assertOk();

        $this->actingAs($frontline)->getJson('/api/admin/integrations/health')->assertForbidden();
        $this->actingAs($fieldAdmin)->getJson('/api/admin/integrations/health')->assertForbidden();
        $this->actingAs($superuser)->getJson('/api/admin/integrations/health')->assertOk();
        $this->actingAs($fieldAdmin)->postJson('/api/admin/integrations/enterprise/fhir/capability-discovery', [
            'source_key' => 'epic.fhir.sandbox',
        ])->assertForbidden();
        $this->actingAs($superuser)->postJson('/api/admin/integrations/enterprise/fhir/capability-discovery', [
            'source_key' => 'epic.fhir.sandbox',
        ])->assertConflict()
            ->assertJsonPath('error.code', 'admin_scope_required');
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

        $user = User::factory()->create(['role' => 'bed_manager', 'must_change_password' => false]);

        $wildcard = $user->createToken('human-admin', ['*'])->plainTextToken;
        $this->withToken($wildcard)
            ->postJson('/api/eddy/agent/actions/propose', $payload)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'machine_ability_required');
        $this->app['auth']->forgetGuards();

        $readOnly = $user->createToken('eddy-reader', ['ops:read'])->plainTextToken;
        $this->withToken($readOnly)
            ->postJson('/api/eddy/agent/actions/propose', $payload)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'machine_ability_required');
        $this->app['auth']->forgetGuards();

        $draft = $user->createToken('eddy-agent', ['ops:read', 'ops:draft'])->plainTextToken;
        $this->withToken($draft)
            ->postJson('/api/eddy/agent/actions/propose', $payload)
            ->assertCreated();
    }

    public function test_eddy_agent_callback_rejects_anonymous_and_browser_session_callers(): void
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

        $user = User::factory()->create(['role' => 'bed_manager', 'must_change_password' => false]);
        $this->actingAs($user)
            ->postJson('/api/eddy/agent/actions/propose', $payload)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'machine_token_required');
    }

    public function test_legacy_case_writes_require_web_authentication(): void
    {
        $this->postJson('/api/cases', [])->assertUnauthorized();
        $this->putJson('/api/cases/1', [])->assertUnauthorized();
    }

    public function test_legacy_case_writes_require_or_case_authorization(): void
    {
        $frontline = User::factory()->create(['role' => 'bed_manager', 'must_change_password' => false]);

        $this->actingAs($frontline)->postJson('/api/cases', [])->assertForbidden();
    }

    public function test_authenticated_case_write_maps_legacy_payload_to_or_case_schema(): void
    {
        $or = $this->seedOrWriteReferences();
        $operator = User::factory()->create(['role' => 'periop_manager', 'must_change_password' => false]);

        $this->actingAs($operator)->postJson('/api/cases', [
            'patient_name' => 'Schema Mapped Patient',
            'mrn' => 'ORWRITE-001',
            'procedure_name' => 'Appendectomy',
            'service_id' => $or['serviceId'],
            'room_id' => $or['roomId'],
            'primary_surgeon_id' => $or['surgeonId'],
            'surgery_date' => '2026-07-09',
            'scheduled_start_time' => '08:30',
            'estimated_duration' => 90,
            'case_class' => 'Elective',
        ])->assertCreated()
            ->assertJsonPath('procedure_name', 'Appendectomy');

        $this->assertDatabaseHas('prod.or_cases', [
            'patient_id' => 'ORWRITE-001',
            'room_id' => $or['roomId'],
            'location_id' => $or['locId'],
            'primary_surgeon_id' => $or['surgeonId'],
            'case_service_id' => $or['serviceId'],
            'scheduled_duration' => 90,
            'status_id' => $or['statusId'],
            'asa_rating_id' => $or['asaId'],
            'case_type_id' => $or['caseTypeId'],
            'case_class_id' => $or['caseClassId'],
            'patient_class_id' => $or['patientClassId'],
        ]);

        $caseId = (int) DB::table('prod.or_cases')
            ->where('patient_id', 'ORWRITE-001')
            ->value('case_id');

        $this->assertDatabaseHas('prod.or_logs', [
            'case_id' => $caseId,
            'tracking_date' => '2026-07-09',
            'primary_procedure' => 'Appendectomy',
            'is_deleted' => false,
        ]);
    }

    public function test_eddy_governance_write_routes_require_operator_roles(): void
    {
        $payload = [
            'action_type' => 'flag_barrier',
            'title' => 'Imaging delay blocking discharges',
            'surface' => 'rtdc',
            'rationale' => 'Two discharges are held on pending CT reads.',
            'runner_up' => 'Escalate to the radiology charge instead.',
            'params' => ['unit' => '3W', 'barrier' => 'imaging'],
        ];

        $frontline = User::factory()->create(['role' => 'user', 'must_change_password' => false]);
        $operator = User::factory()->create(['role' => 'bed_manager', 'must_change_password' => false]);
        $opsLeader = User::factory()->create(['role' => 'ops-leader', 'must_change_password' => false]);

        $this->actingAs($frontline)->postJson('/api/eddy/actions/propose', $payload)->assertForbidden();
        $this->actingAs($frontline)->postJson('/api/eddy/agent/token')->assertForbidden();

        $this->actingAs($operator)->postJson('/api/eddy/actions/propose', $payload)->assertCreated();
        $this->actingAs($operator)->postJson('/api/eddy/agent/token')->assertOk();
        $this->actingAs($opsLeader)->postJson('/api/eddy/agent/token')->assertOk();
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

    /** @return array{locId:int, roomId:int, surgeonId:int, serviceId:int, statusId:int, asaId:int, caseTypeId:int, caseClassId:int, patientClassId:int} */
    private function seedOrWriteReferences(): array
    {
        $locId = (int) DB::table('prod.locations')->insertGetId([
            'name' => 'Write Test OR Suite',
            'abbreviation' => 'WTOR',
            'type' => 'surgical',
            'pos_type' => 'inpatient',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'location_id');

        $specialtyId = (int) DB::table('prod.specialties')->insertGetId([
            'name' => 'Write Test Surgery',
            'code' => 'WTGS',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'specialty_id');

        $surgeonId = (int) DB::table('prod.providers')->insertGetId([
            'name' => 'Dr. Write Test',
            'npi' => '9191919191',
            'specialty_id' => $specialtyId,
            'type' => 'surgeon',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'provider_id');

        $serviceId = (int) DB::table('prod.services')->insertGetId([
            'name' => 'Write Test Service',
            'code' => 'WTSVC',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'service_id');

        $roomId = (int) DB::table('prod.rooms')->insertGetId([
            'location_id' => $locId,
            'name' => 'OR-WT1',
            'type' => 'OR',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'room_id');

        $statusId = (int) DB::table('prod.case_statuses')->insertGetId([
            'name' => 'Scheduled',
            'code' => 'SCHED',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'status_id');

        $asaId = (int) DB::table('prod.asa_ratings')->insertGetId([
            'name' => 'ASA II',
            'code' => 'ASA2',
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'asa_id');

        $caseTypeId = (int) DB::table('prod.case_types')->insertGetId([
            'name' => 'Elective',
            'code' => 'ELEC',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'case_type_id');

        $caseClassId = (int) DB::table('prod.case_classes')->insertGetId([
            'name' => 'Inpatient',
            'code' => 'INP',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'case_class_id');

        $patientClassId = (int) DB::table('prod.patient_classes')->insertGetId([
            'name' => 'Inpatient',
            'code' => 'INP',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'patient_class_id');

        return compact('locId', 'roomId', 'surgeonId', 'serviceId', 'statusId', 'asaId', 'caseTypeId', 'caseClassId', 'patientClassId');
    }
}
