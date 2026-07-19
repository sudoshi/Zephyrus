<?php

namespace Tests\Feature\PatientFlow;

use App\Models\PatientFlow\FlowEvent;
use App\Services\PatientFlow\FlowEventRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit-id resolution bridge (4D navigator lens-collapse fix, 2026-07-19):
 * facility spaces speak the CAD vocabulary (MICU3, MS5B, ED-TRIAGE-002)
 * while prod.units speaks operational abbreviations (MICU, 5E, ED). Without
 * the manifest bridge and the scene-code-prefix fallback, ~78% of flow
 * events serialized unit_id = null and were dropped by every unit-depth
 * lens (rowInScope / canViewPatientRow).
 */
class FlowEventUnitBridgeTest extends TestCase
{
    use RefreshDatabase;

    private function seedUnit(string $abbreviation, string $name): int
    {
        return (int) DB::table('prod.units')->insertGetId([
            'name' => $name,
            'abbreviation' => $abbreviation,
            'type' => 'med_surg',
            'staffed_bed_count' => 10,
            'ratio_floor' => 4,
            'access_standard_minutes' => 120,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'unit_id');
    }

    private function seedSpace(string $sceneCode, ?string $unitCode, int $floor): int
    {
        return (int) DB::table('hosp_space.facility_spaces')->insertGetId([
            'space_code' => 'ZEPHYRUS-500:'.$sceneCode,
            'space_name' => $sceneCode,
            'space_category' => 'bed',
            'floor_number' => $floor,
            'status' => 'active',
            'geometry' => json_encode([
                'source_object_code' => $sceneCode,
                'position_ft' => ['x' => 10.0, 'z' => 20.0, 'level' => $floor * 14],
            ]),
            'attributes' => json_encode($unitCode !== null ? ['unit_code' => $unitCode] : []),
        ], 'facility_space_id');
    }

    private function seedEvent(string $id, int $spaceId, string $sourceCode): FlowEvent
    {
        DB::table('flow_core.patient_identities')->insert([
            'patient_ref' => 'pat-'.$id,
            'patient_display_ref' => 'PT-'.$id,
            'identifier_hash' => hash('sha256', $id),
            'deidentified' => true,
            'metadata' => '{}',
        ]);

        return FlowEvent::create([
            'flow_event_id' => $id,
            'event_category' => 'movement',
            'event_type' => 'transfer',
            'patient_ref' => 'pat-'.$id,
            'patient_display_ref' => 'PT-'.$id,
            'occurred_at' => now()->subHour(),
            'to_source_location_code' => $sourceCode,
            'to_facility_space_id' => $spaceId,
        ]);
    }

    public function test_cad_unit_code_resolves_through_the_manifest_bridge(): void
    {
        // Manifest pairs MICU (prod.units) ↔ MICU3 (CAD attribute).
        $unitId = $this->seedUnit('MICU', '3 West — Medical ICU (MICU)');
        $spaceId = $this->seedSpace('MICU3-B001', 'MICU3', 3);
        $event = $this->seedEvent('evt-micu-1', $spaceId, 'MICU3-B001');

        $row = app(FlowEventRepository::class)->serializeEvent($event->fresh(['toFacilitySpace']));

        $this->assertSame($unitId, $row['unit_id']);
    }

    public function test_scene_code_prefix_resolves_spaces_without_a_unit_code(): void
    {
        // ED bays carry no unit_code attribute; their scene codes lead with 'ED-'.
        $unitId = $this->seedUnit('ED', '1 — Emergency Department');
        $spaceId = $this->seedSpace('ED-TRIAGE-002', null, 1);
        $event = $this->seedEvent('evt-ed-1', $spaceId, 'ED-TRIAGE-002');

        $row = app(FlowEventRepository::class)->serializeEvent($event->fresh(['toFacilitySpace']));

        $this->assertSame($unitId, $row['unit_id']);
    }

    public function test_unbridged_spaces_still_serialize_null_unit_id(): void
    {
        // PACU support spaces belong to no nursing unit — null is correct.
        $this->seedUnit('MICU', '3 West — Medical ICU (MICU)');
        $spaceId = $this->seedSpace('PACU-02', null, 2);
        $event = $this->seedEvent('evt-pacu-1', $spaceId, 'PACU-02');

        $row = app(FlowEventRepository::class)->serializeEvent($event->fresh(['toFacilitySpace']));

        $this->assertNull($row['unit_id']);
    }
}
