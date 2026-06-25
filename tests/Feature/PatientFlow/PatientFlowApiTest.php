<?php

namespace Tests\Feature\PatientFlow;

use App\Models\User;
use App\Services\PatientFlow\FlowEventNormalizer;
use App\Services\PatientFlow\FlowEventRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/patient-flow/summary')
            ->assertOk()
            ->assertJsonPath('normalized_events', 1)
            ->assertJsonPath('patients', 1)
            ->assertJsonPath('facility_code', 'ZEPHYRUS-500');

        $this->actingAs($user)
            ->getJson('/api/patient-flow/locations')
            ->assertOk()
            ->assertJsonStructure(['TICU-B001' => ['facility_space_id', 'position_m', 'name']]);

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
}
