<?php

namespace Tests\Feature;

use App\Models\BedRequest;
use App\Models\Eddy\EddyConversation;
use App\Models\Evs\EvsRequest;
use App\Models\Ops\Approval;
use App\Models\Ops\OperationalAction;
use App\Models\Ops\Recommendation;
use App\Models\PdsaCycle;
use App\Models\Staffing\StaffingRequest;
use App\Models\Transport\TransportRequest;
use App\Models\User;
use App\Services\Mobile\MobilePatientContextService;
use App\Services\Mobile\OperationalActivityLedger;
use Database\Seeders\RtdcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Conformance tests for the Hummingbird mobile BFF (/api/mobile/v1/*): every read endpoint is
 * auth-gated (Sanctum + `mobile:read`) and returns the uniform envelope. The endpoints are
 * defensive (they return the envelope even against a sparse DB), so this stays fast without the
 * full demo seed while still exercising routing, middleware, and the controllers' shape.
 */
class MobileBffTest extends TestCase
{
    use RefreshDatabase;

    /** Operational writes that must be blocked unless the token has mobile:act. */
    private const MOBILE_ACT_ENDPOINTS = [
        ['POST', '/api/mobile/v1/activity/00000000-0000-0000-0000-000000000000/ack', []],
        ['POST', '/api/mobile/v1/rtdc/bed-requests/1/decision', ['action' => 'rejected']],
        ['POST', '/api/mobile/v1/rtdc/barriers/1/resolve', []],
        ['POST', '/api/mobile/v1/transport/requests/1/status', ['status' => 'assigned']],
        ['POST', '/api/mobile/v1/transport/requests/1/handoff', ['handoff_to' => '3 West']],
        ['POST', '/api/mobile/v1/evs/requests/1/status', ['status' => 'assigned']],
        ['POST', '/api/mobile/v1/ops/approvals/00000000-0000-0000-0000-000000000000/decision', ['decision' => 'approved']],
        ['POST', '/api/mobile/v1/staffing/requests/1/fill', ['assigned_source' => 'float_pool']],
        ['POST', '/api/mobile/v1/eddy/approvals/00000000-0000-0000-0000-000000000000/decision', ['decision' => 'approved']],
    ];

    private const MOBILE_ROUTE_PREFIX = 'api/mobile/v1';

    private const OPENAPI_CONTRACT = 'docs/hummingbird/api-contract/hummingbird-bff.v1.yaml';

    public function test_read_endpoints_require_authentication(): void
    {
        foreach ($this->openApiMobileReadPaths() as $path) {
            $this->getJson('/api/mobile/v1'.$this->authGateSamplePath($path))
                ->assertUnauthorized(); // 401 — no bearer token
        }
    }

    public function test_laravel_mobile_routes_match_the_openapi_contract_inventory(): void
    {
        $laravel = $this->laravelMobileRouteInventory();
        $openapi = $this->openApiMobileRouteInventory();

        $this->assertSame(
            $laravel,
            $openapi,
            "The /api/mobile/v1 route inventory drifted from the OpenAPI contract.\n".
            'Laravel only: '.json_encode(array_values(array_diff($laravel, $openapi)))."\n".
            'OpenAPI only: '.json_encode(array_values(array_diff($openapi, $laravel))),
        );
    }

    public function test_a_token_without_mobile_read_ability_is_rejected(): void
    {
        Sanctum::actingAs($this->user(), ['password:change']); // the scoped must-change token

        $this->getJson('/api/mobile/v1/rtdc/census')->assertForbidden(); // 403 — missing mobile:read
        $this->postJson('/api/mobile/v1/rtdc/barriers/1/resolve')->assertForbidden();
    }

    public function test_read_endpoints_return_the_uniform_envelope(): void
    {
        $user = $this->user();
        $this->seed(RtdcSeeder::class); // units + beds spine, so census/house/command have context
        Sanctum::actingAs($user, ['mobile:read']);

        $endpoints = $this->documentedReadEnvelopeEndpoints($user);
        ksort($endpoints);

        $this->assertSame(
            $this->openApiMobileReadPaths(),
            array_keys($endpoints),
            'Every documented mobile GET route must have an envelope fixture in MobileBffTest.',
        );

        foreach ($endpoints as $contractPath => $url) {
            $response = $this->getJson($url)
                ->assertOk()
                ->assertJsonStructure([
                    'data',
                    'meta' => ['as_of', 'stale', 'version'],
                    'links',
                ]);

            $this->assertIsArray($response->json('links'), "{$contractPath} must expose a links object.");
            if (! in_array($contractPath, $this->readEndpointsWithoutWebHandoff(), true)) {
                $this->assertIsString($response->json('links.web'), "{$contractPath} must expose links.web for web handoff.");
                $this->assertNotSame('', $response->json('links.web'), "{$contractPath} must expose a non-empty links.web.");
            }
        }
    }

    public function test_mobile_error_statuses_are_covered_by_current_routes(): void
    {
        $this->getJson('/api/mobile/v1/rtdc/census')->assertUnauthorized();

        $user = $this->user();
        Sanctum::actingAs($user, ['password:change']);
        $this->getJson('/api/mobile/v1/rtdc/census')->assertForbidden();

        Sanctum::actingAs($user, ['mobile:read']);
        $this->getJson('/api/mobile/v1/rtdc/bed-requests/999999/recommendations')->assertNotFound();

        Sanctum::actingAs($user, ['mobile:read', 'mobile:act']);
        $bedRequest = BedRequest::create([
            'patient_ref' => 'SECRET-MRN-BFF-ERRORS',
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 1,
            'status' => 'pending',
        ]);

        $this->seed(RtdcSeeder::class);
        $unavailableBedId = DB::table('prod.beds')->value('bed_id');
        DB::table('prod.beds')
            ->where('bed_id', $unavailableBedId)
            ->update(['status' => 'occupied']);

        $this->postJson("/api/mobile/v1/rtdc/bed-requests/{$bedRequest->bed_request_id}/decision", [
            'action' => 'accepted',
            'chosen_bed_id' => $unavailableBedId,
        ])
            ->assertStatus(409)
            ->assertJsonStructure([
                'data' => ['error'],
                'meta' => ['as_of', 'stale', 'version'],
                'links',
            ]);

        $this->postJson("/api/mobile/v1/rtdc/bed-requests/{$bedRequest->bed_request_id}/decision", [
            'action' => 'invalid-action',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['action']);
    }

    public function test_mobile_act_is_required_for_writes(): void
    {
        Sanctum::actingAs($this->user(), ['mobile:read']); // read-only token

        foreach (self::MOBILE_ACT_ENDPOINTS as [$method, $path, $body]) {
            $this->json($method, $path, $body)->assertForbidden();
        }
    }

    public function test_high_value_mobile_reads_return_seeded_shapes(): void
    {
        $user = $this->user();
        $this->seed(RtdcSeeder::class);
        Sanctum::actingAs($user, ['mobile:read']);

        $fixtures = $this->seedHighValueReadFixtures($user);

        $this->getJson('/api/mobile/v1/transport/queue')
            ->assertOk()
            ->assertJsonPath('data.jobs.0.id', $fixtures['transport']->transport_request_id)
            ->assertJsonStructure(['data' => ['metrics' => ['active', 'stat', 'at_risk', 'completed_today'], 'jobs' => [['tier', 'visual_status', 'sla' => ['at_risk', 'label']]]]]);

        $this->getJson('/api/mobile/v1/evs/queue')
            ->assertOk()
            ->assertJsonPath('data.turns.0.id', $fixtures['evs']->evs_request_id)
            ->assertJsonStructure(['data' => ['metrics' => ['pending', 'overdue', 'isolation', 'completed_today'], 'turns' => [['tier', 'visual_status', 'sla' => ['at_risk', 'label']]]]]);

        $this->getJson('/api/mobile/v1/rtdc/house')
            ->assertOk()
            ->assertJsonStructure(['data' => ['occupancy' => ['occupied', 'staffed', 'percent'], 'net_bed_need', 'pending_placements', 'ed_boarding', 'units']]);

        $this->getJson('/api/mobile/v1/rtdc/bed-requests')
            ->assertOk()
            ->assertJsonPath('data.0.id', $fixtures['bed_request']->bed_request_id)
            ->assertJsonStructure(['data' => [['id', 'source', 'service', 'acuity_tier', 'tier', 'visual_status', 'required_unit_type', 'at']]]);

        $this->getJson('/api/mobile/v1/for-you?persona=bed_manager')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'tier', 'visual_status', 'status_detail' => ['value', 'label']]]]);

        $orBoard = $this->getJson('/api/mobile/v1/or/board')
            ->assertOk()
            ->assertJsonStructure(['data' => ['rooms', 'metrics' => ['running', 'turnover', 'available', 'total', 'avg_turnover_min']]]);
        if ($orBoard->json('data.rooms') !== []) {
            $this->assertArrayHasKey('visual_status', $orBoard->json('data.rooms.0'));
        }

        $this->getJson('/api/mobile/v1/command/house')
            ->assertOk()
            ->assertJsonStructure(['data' => ['strain', 'hero', 'generated_at']]);

        $this->getJson('/api/mobile/v1/ops/inbox')
            ->assertOk()
            ->assertJsonPath('data.0.approval_uuid', $fixtures['approval']->approval_uuid)
            ->assertJsonStructure(['data' => [['approval_uuid', 'title', 'tier', 'visual_status', 'requested_at']]]);

        $this->getJson('/api/mobile/v1/staffing/overview')
            ->assertOk()
            ->assertJsonPath('data.queue.0.staffing_request_id', $fixtures['staffing']->staffing_request_id)
            ->assertJsonStructure(['data' => ['metrics' => ['open_requests', 'at_risk_units', 'critical_gaps', 'coverage_pct', 'stat_requests', 'total_gap_headcount'], 'queue']]);

        $this->getJson('/api/mobile/v1/improvement/pdsa')
            ->assertOk()
            ->assertJsonPath('data.0.id', $fixtures['pdsa']->pdsa_cycle_id)
            ->assertJsonStructure(['data' => [['id', 'title', 'status', 'owner', 'objective', 'target_date']]]);

        $this->getJson('/api/mobile/v1/altitude/home?persona=bed_manager')
            ->assertOk()
            ->assertJsonPath('data.altitude', 'A0')
            ->assertJsonPath('data.persona.role_id', 'bed_manager')
            ->assertJsonStructure(['data' => ['status' => ['value', 'label'], 'tiles', 'for_you_head', 'activity']]);

        $this->getJson('/api/mobile/v1/activity?persona=bed_manager')
            ->assertOk()
            ->assertJsonPath('data.0.event_uuid', $fixtures['activity']['event_uuid'])
            ->assertJsonStructure(['data' => [['event_uuid', 'event_type', 'occurred_at', 'domain', 'phi_policy']]]);
    }

    /**
     * Every surface must read the same occupancy. With no census_snapshots rows (fresh
     * dataset / snapshot pipeline not running), the executive brief must fall back to the
     * live bed board instead of reporting 0% while /rtdc/house reports the real number.
     */
    public function test_command_house_occupancy_matches_the_live_census_when_snapshots_are_absent(): void
    {
        $this->seed(RtdcSeeder::class); // units + beds spine, deliberately no census_snapshots
        Sanctum::actingAs($this->user(), ['mobile:read']);

        // Occupy some beds so 0% (empty house) can't trivially satisfy the comparison.
        DB::table('prod.beds')->whereIn(
            'bed_id',
            DB::table('prod.beds')->where('is_deleted', false)->limit(40)->pluck('bed_id')
        )->update(['status' => 'occupied']);

        $livePercent = $this->getJson('/api/mobile/v1/rtdc/house')
            ->assertOk()
            ->json('data.occupancy.percent');

        $hero = collect($this->getJson('/api/mobile/v1/command/house')->assertOk()->json('data.hero'));
        $occupancy = $hero->firstWhere('key', 'occupancy');

        $this->assertNotNull($occupancy, 'command/house hero metrics must include occupancy');
        $this->assertGreaterThan(0, $livePercent, 'seeded census should not be empty');
        $this->assertSame($livePercent, $occupancy['value'],
            'executive occupancy must agree with the live RTDC census');
    }

    private function user(): User
    {
        $user = new User;
        $user->name = 'BFF Test';
        $user->email = 'bfftest@example.com';
        $user->username = 'bfftest';
        $user->password = bcrypt('secret-test-password');
        $user->role = 'admin';
        $user->workflow_preference = 'rtdc';
        $user->save();

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function documentedReadEnvelopeEndpoints(User $user): array
    {
        $bedRequest = BedRequest::create([
            'patient_ref' => 'SECRET-MRN-BFF-ENVELOPE',
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 1,
            'status' => 'pending',
        ]);
        $patientContextRef = app(MobilePatientContextService::class)->contextRefFor($bedRequest->patient_ref);
        $activityEvent = app(OperationalActivityLedger::class)->record('barrier.resolved', [
            'actor_user_id' => $user->id,
            'actor_role' => 'charge_nurse',
            'domain' => 'rtdc',
            'scope' => ['barrier_id' => 321],
            'status' => ['previous' => 'open', 'current' => 'resolved', 'severity' => 'info'],
            'entities' => [['entity_type' => 'barrier', 'entity_ref' => '321']],
        ]);
        $approval = $this->eddyApproval($user);
        $conversation = EddyConversation::create([
            'eddy_conversation_uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'title' => 'Mobile BFF fixture',
            'surface' => 'rtdc',
            'origin' => 'hummingbird',
            'pinned_context' => [],
        ]);

        return [
            '/activity' => '/api/mobile/v1/activity?persona=bed_manager',
            '/altitude/home' => '/api/mobile/v1/altitude/home?persona=bed_manager',
            '/altitude/workspace/{domain}' => '/api/mobile/v1/altitude/workspace/rtdc?persona=bed_manager',
            '/command/house' => '/api/mobile/v1/command/house',
            '/drills/{itemUuid}' => "/api/mobile/v1/drills/bedreq-{$bedRequest->bed_request_id}?persona=bed_manager",
            '/eddy/approvals' => '/api/mobile/v1/eddy/approvals',
            '/eddy/approvals/{uuid}' => "/api/mobile/v1/eddy/approvals/{$approval->approval_uuid}",
            '/eddy/context/{scopeRef}' => "/api/mobile/v1/eddy/context/{$activityEvent['event_uuid']}?persona=bed_manager",
            '/eddy/conversations' => '/api/mobile/v1/eddy/conversations',
            '/eddy/conversations/{uuid}' => "/api/mobile/v1/eddy/conversations/{$conversation->eddy_conversation_uuid}",
            '/evs/queue' => '/api/mobile/v1/evs/queue',
            '/for-you' => '/api/mobile/v1/for-you?persona=bed_manager',
            '/improvement/opportunities' => '/api/mobile/v1/improvement/opportunities',
            '/improvement/pdsa' => '/api/mobile/v1/improvement/pdsa',
            '/me' => '/api/mobile/v1/me',
            '/ops/inbox' => '/api/mobile/v1/ops/inbox',
            '/or/board' => '/api/mobile/v1/or/board',
            '/patients/{contextRef}/operational-context' => "/api/mobile/v1/patients/{$patientContextRef}/operational-context?persona=bed_manager",
            '/realtime/config' => '/api/mobile/v1/realtime/config',
            '/rtdc/bed-requests' => '/api/mobile/v1/rtdc/bed-requests',
            '/rtdc/bed-requests/{id}/recommendations' => "/api/mobile/v1/rtdc/bed-requests/{$bedRequest->bed_request_id}/recommendations",
            '/rtdc/census' => '/api/mobile/v1/rtdc/census',
            '/rtdc/house' => '/api/mobile/v1/rtdc/house',
            '/staffing/overview' => '/api/mobile/v1/staffing/overview',
            '/transport/queue' => '/api/mobile/v1/transport/queue',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function seedHighValueReadFixtures(User $user): array
    {
        $bedRequest = BedRequest::create([
            'patient_ref' => 'SECRET-MRN-BFF-SHAPE',
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 1,
            'required_unit_type' => 'med_surg',
            'status' => 'pending',
        ]);
        $transport = TransportRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'inpatient',
            'priority' => 'stat',
            'status' => 'requested',
            'patient_ref' => 'SECRET-MRN-BFF-TRANSPORT',
            'encounter_ref' => 'SECRET-ENC-BFF-TRANSPORT',
            'origin' => 'ED',
            'destination' => '3 West',
            'transport_mode' => 'wheelchair',
            'requested_at' => now(),
            'needed_at' => now()->addMinutes(10),
            'is_deleted' => false,
        ]);
        $evs = EvsRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'bed_clean',
            'priority' => 'urgent',
            'status' => 'requested',
            'patient_ref' => 'SECRET-MRN-BFF-EVS',
            'encounter_ref' => 'SECRET-ENC-BFF-EVS',
            'location_label' => '3W-12',
            'turn_type' => 'isolation',
            'isolation_required' => true,
            'requested_at' => now(),
            'needed_at' => now()->subMinutes(5),
            'is_deleted' => false,
        ]);
        $staffing = StaffingRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'unit_label' => '3 West',
            'role' => 'rn',
            'shift_date' => now()->toDateString(),
            'shift' => 'day',
            'request_type' => 'fill_gap',
            'priority' => 'stat',
            'status' => 'requested',
            'headcount_needed' => 1,
            'needed_by' => now()->addHour(),
            'is_deleted' => false,
        ]);
        $pdsa = PdsaCycle::create([
            'title' => 'Discharge-before-noon pull',
            'status' => 'active',
            'owner' => 'PI Lead',
            'objective' => 'Move discharge readiness earlier.',
            'started_at' => now()->subDay(),
            'target_date' => now()->addWeek()->toDateString(),
            'is_deleted' => false,
        ]);
        DB::table('prod.improvement_opportunities')->insert([
            'title' => 'Transport SLA Compliance',
            'description' => 'Reduce avoidable waits for patient movement.',
            'department' => 'Transport',
            'priority' => 'High',
            'status' => 'Open',
            'estimated_impact' => 70,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $approval = $this->eddyApproval($user);
        $activity = app(OperationalActivityLedger::class)->record('bed_request.created', [
            'actor_user_id' => $user->id,
            'actor_role' => 'bed_manager',
            'domain' => 'rtdc',
            'scope' => ['bed_request_id' => $bedRequest->bed_request_id, 'patient_ref' => $bedRequest->patient_ref],
            'status' => ['previous' => 'none', 'current' => 'pending', 'severity' => 'warning'],
            'entities' => [[
                'entity_type' => 'bed_request',
                'entity_ref' => (string) $bedRequest->bed_request_id,
                'patient_ref' => $bedRequest->patient_ref,
            ]],
        ]);

        return [
            'bed_request' => $bedRequest,
            'transport' => $transport,
            'evs' => $evs,
            'staffing' => $staffing,
            'pdsa' => $pdsa,
            'approval' => $approval,
            'activity' => $activity,
        ];
    }

    private function eddyApproval(User $user): Approval
    {
        $recommendation = Recommendation::create([
            'recommendation_uuid' => (string) Str::uuid(),
            'recommendation_type' => 'eddy_barrier',
            'scope_type' => 'rtdc',
            'title' => 'Imaging delay blocking discharges',
            'rationale' => 'Two discharges held on pending CT reads.',
            'risk_level' => 'low',
            'status' => 'draft',
            'created_by_source' => 'eddy',
            'expected_impact' => [],
            'evidence' => ['runner_up' => 'Escalate to radiology charge.', 'tier' => 'T1'],
        ]);

        $action = OperationalAction::create([
            'action_uuid' => (string) Str::uuid(),
            'recommendation_id' => $recommendation->recommendation_id,
            'action_type' => 'flag_barrier',
            'status' => 'draft',
            'payload' => ['unit' => '3W', 'barrier' => 'imaging'],
        ]);

        return Approval::create([
            'approval_uuid' => (string) Str::uuid(),
            'action_id' => $action->action_id,
            'status' => 'pending',
            'requested_by_user_id' => $user->id,
            'reason' => 'Mobile BFF route fixture.',
        ]);
    }

    private function authGateSamplePath(string $path): string
    {
        return strtr($path, [
            '{domain}' => 'rtdc',
            '{itemUuid}' => 'bedreq-1',
            '{contextRef}' => 'ptok_missing',
            '{scopeRef}' => 'house',
            '{uuid}' => '00000000-0000-0000-0000-000000000000',
            '{id}' => '1',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function readEndpointsWithoutWebHandoff(): array
    {
        return [
            '/realtime/config',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function laravelMobileRouteInventory(): array
    {
        return collect(Route::getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), self::MOBILE_ROUTE_PREFIX.'/'))
            ->flatMap(function ($route) {
                $path = substr($route->uri(), strlen(self::MOBILE_ROUTE_PREFIX));

                return collect($route->methods())
                    ->reject(fn (string $method): bool => $method === 'HEAD')
                    ->map(fn (string $method): string => "{$method} {$path}");
            })
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function openApiMobileRouteInventory(): array
    {
        $contract = Yaml::parseFile(base_path(self::OPENAPI_CONTRACT));
        $operations = ['get', 'post', 'put', 'patch', 'delete'];

        return collect($contract['paths'] ?? [])
            ->reject(fn (array $pathItem, string $path): bool => str_starts_with($path, '/auth/'))
            ->flatMap(function (array $pathItem, string $path) use ($operations) {
                return collect($pathItem)
                    ->keys()
                    ->filter(fn (string $method): bool => in_array($method, $operations, true))
                    ->map(fn (string $method): string => strtoupper($method).' '.$path);
            })
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function openApiMobileReadPaths(): array
    {
        $contract = Yaml::parseFile(base_path(self::OPENAPI_CONTRACT));

        return collect($contract['paths'] ?? [])
            ->reject(fn (array $pathItem, string $path): bool => str_starts_with($path, '/auth/'))
            ->filter(fn (array $pathItem): bool => isset($pathItem['get']))
            ->keys()
            ->sort()
            ->values()
            ->all();
    }
}
