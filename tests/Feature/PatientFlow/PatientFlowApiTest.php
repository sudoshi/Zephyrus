<?php

namespace Tests\Feature\PatientFlow;

use App\Models\User;
use App\Services\PatientFlow\FlowEventNormalizer;
use App\Services\PatientFlow\FlowEventRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatientFlowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_flow_api_returns_navigator_contract(): void
    {
        $facilityCode = 'ZEPHYRUS-500';

        $this->assertSame(0, Artisan::call('facility:import-catalog', [
            'path' => base_path('tests/Fixtures/facility/model_catalog_fixture.json'),
            '--facility-code' => $facilityCode,
            '--facility-name' => 'Navigator Test Facility',
            '--source-name' => 'navigator-fixture-catalog',
            '--map-operational' => true,
        ]));

        $raw = implode("\r", [
            'MSH|^~\\&|EHR|AMC|FLOW|AMC|20260625010100||ADT^A01|MSG1|P|2.5.1',
            'EVN|A01|20260625010100',
            'PID|||SYN000001^^^AMC^MR||FLOW^SYN000001',
            'PV1||I|TICU^TICU-R001^TICU-B001^ZEPHYRUS||||99001^ATTENDING^SYNTHETIC|||critical_care|||||||||VIS000001^^^AMC^VN',
            '',
        ]);

        $event = app(FlowEventNormalizer::class)->normalize($raw);
        app(FlowEventRepository::class)->upsertNormalizedEvent($event, null, null, null, $facilityCode);
        $spaceId = (int) DB::table('hosp_space.facility_spaces')
            ->where('space_code', "{$facilityCode}:TICU-B001")
            ->value('facility_space_id');
        DB::table('ops.nodes')->insert([
            'node_uuid' => (string) Str::uuid(),
            'node_type' => 'facility_space',
            'canonical_key' => "facility_space:{$spaceId}",
            'display_name' => 'TICU bed graph node',
            'source_schema' => 'hosp_space',
            'source_table' => 'facility_spaces',
            'source_pk' => (string) $spaceId,
            'status' => 'active',
            'current_state' => json_encode(['overlay' => 'capacity']),
            'metadata' => json_encode([]),
            'last_observed_at' => now(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Patient-level endpoints are persona-lensed (FLOW-WINDOW-PLAN §6.4):
        // this user's `role` grants the bed_manager lens (patient_dots: full).
        $user = User::factory()->create(['role' => 'bed_manager']);

        $this->actingAs($user)
            ->getJson('/api/patient-flow/summary')
            ->assertOk()
            ->assertJsonPath('normalized_events', 1)
            ->assertJsonPath('patients', 1)
            ->assertJsonPath('ambient_signals', 4)
            ->assertJsonPath('ambient_confidence_level', 'medium')
            ->assertJsonPath('facility_code', 'ZEPHYRUS-500');

        $this->actingAs($user)
            ->getJson('/api/patient-flow/locations')
            ->assertOk()
            ->assertJsonStructure(['TICU-B001' => ['facility_space_id', 'position_m', 'name', 'ops_graph_nodes']])
            ->assertJsonPath('TICU-B001.ops_graph_nodes.0.canonicalKey', "facility_space:{$spaceId}");

        $this->actingAs($user)
            ->getJson('/api/patient-flow/events')
            ->assertOk()
            ->assertJsonPath('0.event_type', 'admit')
            ->assertJsonPath('0.to_location', 'TICU-B001')
            ->assertJsonPath('0.location_name', 'Test Surgical Trauma ICU bed 001');

        $this->actingAs($user)
            ->getJson('/api/patient-flow/state')
            ->assertOk()
            ->assertJsonPath('activePatients', 1)
            ->assertJsonPath('patients.0.location', 'TICU-B001');

        $ambient = $this->actingAs($user)
            ->getJson('/api/patient-flow/ambient')
            ->assertOk()
            ->assertJsonPath('summary.adapterCount', 4)
            ->assertJsonPath('summary.eventCount', 4)
            ->assertJsonPath('adapters.0.enabled', true)
            ->json();

        $this->assertContains('fixture_rtls', array_column($ambient['adapters'], 'key'));
        $this->assertContains('fixture_nurse_call', array_column($ambient['adapters'], 'key'));
        $this->assertContains('zone_presence', array_column($ambient['events'], 'signalType'));
        $this->assertGreaterThanOrEqual(0.70, $ambient['summary']['averageConfidence']);

        $this->assertDatabaseHas('flow_realtime.ambient_signal_adapters', [
            'adapter_key' => 'fixture_rtls',
            'source_type' => 'rtls',
        ]);
        $this->assertDatabaseHas('flow_realtime.ambient_signal_events', [
            'signal_type' => 'zone_presence',
            'confidence_level' => 'high',
        ]);

        $this->actingAs($user)
            ->getJson('/api/patient-flow/fhir/bundle?event_id='.$event['event_id'])
            ->assertOk()
            ->assertJsonPath('resourceType', 'Bundle')
            ->assertJsonPath('entry.0.resource.resourceType', 'Encounter');
    }

    public function test_patient_flow_api_requires_authentication(): void
    {
        $this->getJson('/api/patient-flow/summary')->assertUnauthorized();
    }

    public function test_patient_level_flow_endpoints_require_a_patient_capable_lens(): void
    {
        // No Hummingbird persona at all → explicit 403 unauthorized state.
        $rolelessUser = User::factory()->create(['role' => 'user']);
        foreach (['/api/patient-flow/events', '/api/patient-flow/tracks', '/api/patient-flow/state', '/api/patient-flow/fhir/bundle?event_id=x'] as $path) {
            $this->actingAs($rolelessUser)
                ->getJson($path)
                ->assertForbidden()
                ->assertJsonPath('error.unauthorized_state', true);
        }

        // patient_dots: none personas (executive) are equally shut out …
        $executive = User::factory()->create(['role' => 'executive']);
        $this->actingAs($executive)
            ->getJson('/api/patient-flow/state')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'flow_lens_forbidden');

        // … but aggregate endpoints stay open to any authenticated user.
        $this->actingAs($rolelessUser)->getJson('/api/patient-flow/summary')->assertOk();

        // And the executive lens still gets the (aggregate) projection stream.
        $this->actingAs($executive)
            ->getJson('/api/patient-flow/projections')
            ->assertOk()
            ->assertJsonPath('lens.patient_dots', 'none');
    }
}
