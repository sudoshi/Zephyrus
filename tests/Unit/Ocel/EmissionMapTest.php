<?php

namespace Tests\Unit\Ocel;

use App\Domain\Ocel\EmissionMap;
use App\Domain\Ocel\EmittedEvent;
use PHPUnit\Framework\TestCase;

/**
 * Zephyrus 2.0 — Part X (X0). The emission map is the one place a wrong qualifier
 * or a missed O2O link would corrupt every downstream OCPM map (§X.3 risk X-R1),
 * so it is unit-tested against fixture rows with asserted OCEL output — pure, no
 * DB, no Laravel boot. These tests also pin the PHI-safety contract (§X.3.4): a
 * raw clinical reference must never survive into an object id.
 */
class EmissionMapTest extends TestCase
{
    private function flowRow(array $overrides = []): object
    {
        return (object) array_merge([
            'flow_event_id' => 'CP-SEP-0048-05',
            'occurred_at' => '2026-05-21T16:08:20-04:00',
            'event_type' => 'medication',
            'event_category' => 'medication',
            'encounter_ref' => 'ENC-REAL-12345',
            'patient_ref' => 'MRN-REAL-98765',
            'service_line' => 'trauma_surgery',
            'patient_class' => 'I',
            'priority' => null,
            'to_source_location_code' => null,
            'point_of_care' => null,
            'bed' => null,
            'diagnosis_codes' => ['A41.9', 'R65.20'],
            'order_codes' => [],
            'observation_codes' => [],
            'medication_codes' => ['2193', '11124'],
            'metadata' => [
                'seeder' => 'clinical_pathways',
                'pathway' => 'sepsis',
                'activity' => 'antibiotic_administration',
                'within_3hr' => true,
                'minutes_from_recognition' => 128,
            ],
        ], $overrides);
    }

    public function test_flow_event_prefers_granular_pathway_activity_and_deterministic_id(): void
    {
        $e = EmissionMap::forFlowEvent($this->flowRow());

        $this->assertInstanceOf(EmittedEvent::class, $e);
        $this->assertSame('antibiotic_administration', $e->activity, 'granular metadata.activity wins over event_type');
        $this->assertSame('fe-CP-SEP-0048-05', $e->id);
        $this->assertSame('flow_core.flow_events', $e->sourceSystem);
        $this->assertSame('sepsis', $e->attrs['pathway']);
        $this->assertTrue($e->attrs['within_3hr']);
        $this->assertSame(['2193', '11124'], $e->attrs['medication_codes'], 'coded clinical flags carry through for X.7 conformance');
    }

    public function test_flow_event_deidentifies_patient_and_encounter_refs(): void
    {
        $row = $this->flowRow();
        $e = EmissionMap::forFlowEvent($row);

        $serialized = json_encode($e->objects + $e->o2o + [$e->id]);
        $this->assertStringNotContainsString('MRN-REAL-98765', $serialized, 'raw MRN must never reach an object id');
        $this->assertStringNotContainsString('ENC-REAL-12345', $serialized, 'raw encounter ref must never reach an object id');

        $encId = 'enc-'.EmissionMap::hashRef('ENC-REAL-12345');
        $patId = 'patient-'.EmissionMap::hashRef('MRN-REAL-98765');

        $enc = $this->objectByType($e, 'Encounter');
        $pat = $this->objectByType($e, 'Patient');
        $this->assertSame($encId, $enc['id']);
        $this->assertSame('subject', $enc['qualifier']);
        $this->assertSame($patId, $pat['id']);
        $this->assertSame('patient', $pat['qualifier']);

        // Encounter *of* Patient O2O binding.
        $this->assertContains(
            ['from' => $encId, 'to' => $patId, 'qualifier' => 'of'],
            $e->o2o
        );
    }

    public function test_flow_event_binds_bed_and_unit_when_present(): void
    {
        $e = EmissionMap::forFlowEvent($this->flowRow([
            'event_type' => 'transfer',
            'metadata' => [],
            'to_source_location_code' => '5West',
            'bed' => '12',
        ]));

        $this->assertSame('transfer', $e->activity, 'falls back to event_type when no granular activity');
        $bed = $this->objectByType($e, 'Bed');
        $unit = $this->objectByType($e, 'Unit');
        $this->assertSame('resource', $bed['qualifier']);
        $this->assertSame('location', $unit['qualifier']);
        $this->assertSame('unit-5west', $unit['id']);
        $this->assertSame('bed-5west-12', $bed['id']);

        // Bed *in* Unit and Encounter *occupies* Bed.
        $this->assertContains(['from' => $bed['id'], 'to' => $unit['id'], 'qualifier' => 'in'], $e->o2o);
        $encId = 'enc-'.EmissionMap::hashRef('ENC-REAL-12345');
        $this->assertContains(['from' => $encId, 'to' => $bed['id'], 'qualifier' => 'occupies'], $e->o2o);
    }

    public function test_flow_event_without_timestamp_is_skipped(): void
    {
        $this->assertNull(EmissionMap::forFlowEvent($this->flowRow(['occurred_at' => null])));
    }

    public function test_milestone_binds_safety_check_to_or_case_and_suite(): void
    {
        $row = (object) [
            'milestone_id' => 7788,
            'case_id' => 4242,
            'milestone_type' => 'Safety_Check',
            'status' => 'Alert',
            'required' => true,
            'completed_at' => '2026-06-30T09:15:00-04:00',
            'created_at' => '2026-06-30T08:00:00-04:00',
            'room_id' => 9,
            'patient_id' => 'ORPT-REAL-555',
            'safety_status' => 'Alert',
            'journey_progress' => 60,
            'surgery_date' => '2026-06-30',
        ];

        $e = EmissionMap::forMilestone($row);
        $this->assertSame('Safety_Check', $e->activity);
        $this->assertSame('mil-7788', $e->id);
        $this->assertSame('orcase-4242', $this->objectByType($e, 'OR Case')['id']);
        $this->assertSame('resource', $this->objectByType($e, 'OR Suite')['qualifier']);
        $this->assertSame('orsuite-9', $this->objectByType($e, 'OR Suite')['id']);
        $this->assertSame('Alert', $e->attrs['status']);
        $this->assertStringNotContainsString('ORPT-REAL-555', json_encode($e->objects), 'OR patient ref de-identified');
        $this->assertContains(['from' => 'orcase-4242', 'to' => 'orsuite-9', 'qualifier' => 'in'], $e->o2o);
    }

    public function test_transport_row_fans_into_phase_events(): void
    {
        $row = (object) [
            'transport_request_id' => 321,
            'encounter_ref' => 'ENC-REAL-777',
            'destination' => 'PACU',
            'request_type' => 'bed-to-bed',
            'priority' => 'routine',
            'status' => 'completed',
            'transport_mode' => 'wheelchair',
            'clinical_service' => 'periop',
            'origin' => 'OR-3',
            'requested_at' => '2026-06-29T10:00:00-04:00',
            'assigned_at' => '2026-06-29T10:05:00-04:00',
            'dispatched_at' => '2026-06-29T10:07:00-04:00',
            'completed_at' => '2026-06-29T10:25:00-04:00',
        ];

        $events = EmissionMap::forTransport($row);
        $this->assertCount(3, $events);
        $this->assertSame(['transport-request', 'transport-pickup', 'transport-dropoff'], array_map(fn ($e) => $e->activity, $events));
        $this->assertSame(['tr-321-request', 'tr-321-pickup', 'tr-321-dropoff'], array_map(fn ($e) => $e->id, $events));
        foreach ($events as $e) {
            $this->assertSame('transport-321', $this->objectByType($e, 'Transport Job')['id']);
        }
    }

    public function test_transport_row_emits_only_populated_phases(): void
    {
        $row = (object) [
            'transport_request_id' => 99,
            'encounter_ref' => null,
            'destination' => null,
            'requested_at' => '2026-06-29T10:00:00-04:00',
            'assigned_at' => null,
            'dispatched_at' => null,
            'completed_at' => null,
        ];

        $events = EmissionMap::forTransport($row);
        $this->assertCount(1, $events);
        $this->assertSame('transport-request', $events[0]->activity);
    }

    public function test_hash_ref_is_deterministic_and_phi_free(): void
    {
        $this->assertSame(EmissionMap::hashRef('MRN-123'), EmissionMap::hashRef('MRN-123'));
        $this->assertNotSame(EmissionMap::hashRef('MRN-123'), EmissionMap::hashRef('MRN-124'));
        $this->assertSame(12, strlen(EmissionMap::hashRef('MRN-123')));
        $this->assertNull(EmissionMap::hashRef(''));
        $this->assertStringNotContainsString('MRN-123', EmissionMap::hashRef('MRN-123'));
    }

    /** @return array{id: string, type: string, qualifier: string, attrs?: array} */
    private function objectByType(EmittedEvent $e, string $type): array
    {
        foreach ($e->objects as $o) {
            if ($o['type'] === $type) {
                return $o;
            }
        }
        $this->fail("no object of type {$type} on event {$e->id}");
    }
}
