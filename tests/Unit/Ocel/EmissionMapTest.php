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

    public function test_barrier_fans_into_opened_and_resolved_events(): void
    {
        $row = (object) [
            'barrier_id' => 55,
            'encounter_id' => 12,
            'unit_id' => 7,
            'category' => 'placement',
            'reason_code' => 'no_bed',
            'status' => 'resolved',
            'opened_at' => '2026-06-29T10:00:00-04:00',
            'resolved_at' => '2026-06-29T14:30:00-04:00',
            'unit_abbreviation' => '5West',
        ];

        $events = EmissionMap::forBarrier($row);
        $this->assertCount(2, $events);
        $this->assertSame(['barrier_opened', 'barrier_resolved'], array_map(fn ($e) => $e->activity, $events));
        $this->assertSame(['bar-55-opened', 'bar-55-resolved'], array_map(fn ($e) => $e->id, $events));

        // One first-class Barrier subject, linked to its unit via O2O `in`.
        $this->assertSame('barrier-55', $this->objectByType($events[0], 'Barrier')['id']);
        $this->assertSame('unit-5west', $this->objectByType($events[0], 'Unit')['id']);
        $this->assertContains(['from' => 'barrier-55', 'to' => 'unit-5west', 'qualifier' => 'in'], $events[0]->o2o);

        // status carried as a time-varying object_change: open → resolved.
        $this->assertSame('status', $events[0]->changes[0]['attr']);
        $this->assertSame('open', $events[0]->changes[0]['value']);
        $this->assertSame('resolved', $events[1]->changes[0]['value']);

        // PHI: the numeric encounter_id is hashed to enc-<hash>, never surfaced raw.
        $barrier = $this->objectByType($events[0], 'Barrier');
        $this->assertSame('enc-'.EmissionMap::hashRef('12'), $barrier['attrs']['encounter_ref']);
        $this->assertArrayNotHasKey('encounter_id', $barrier['attrs']);
    }

    public function test_unresolved_barrier_emits_only_opened(): void
    {
        $row = (object) [
            'barrier_id' => 88, 'encounter_id' => null, 'unit_id' => 3,
            'category' => 'logistical', 'reason_code' => null, 'status' => 'open',
            'opened_at' => '2026-06-29T09:00:00-04:00', 'resolved_at' => null,
            'unit_abbreviation' => '3E',
        ];

        $events = EmissionMap::forBarrier($row);
        $this->assertCount(1, $events);
        $this->assertSame('barrier_opened', $events[0]->activity);
        $this->assertSame('bar-88-opened', $events[0]->id);
    }

    public function test_house_level_barrier_skips_the_unit_link(): void
    {
        $row = (object) [
            'barrier_id' => 90, 'encounter_id' => null, 'unit_id' => null,
            'category' => 'social', 'reason_code' => null, 'status' => 'open',
            'opened_at' => '2026-06-29T09:00:00-04:00', 'resolved_at' => null,
            'unit_abbreviation' => null,
        ];

        $events = EmissionMap::forBarrier($row);
        $this->assertCount(1, $events);
        $this->assertCount(1, $events[0]->objects); // only the Barrier itself
        $this->assertSame('Barrier', $events[0]->objects[0]['type']);
        $this->assertSame([], $events[0]->o2o);
    }

    public function test_barrier_without_opened_at_is_skipped(): void
    {
        $this->assertSame([], EmissionMap::forBarrier((object) ['barrier_id' => 1, 'opened_at' => null]));
    }

    public function test_hash_ref_is_deterministic_and_phi_free(): void
    {
        $this->assertSame(EmissionMap::hashRef('MRN-123'), EmissionMap::hashRef('MRN-123'));
        $this->assertNotSame(EmissionMap::hashRef('MRN-123'), EmissionMap::hashRef('MRN-124'));
        $this->assertSame(12, strlen(EmissionMap::hashRef('MRN-123')));
        $this->assertNull(EmissionMap::hashRef(''));
        $this->assertStringNotContainsString('MRN-123', EmissionMap::hashRef('MRN-123'));
    }

    public function test_placement_records_bed_status_occupied_change(): void
    {
        $e = EmissionMap::forFlowEvent($this->flowRow([
            'event_type' => 'admit',
            'metadata' => [],
            'to_source_location_code' => '3East',
            'bed' => '7',
        ]));

        $this->assertCount(1, $e->changes);
        $this->assertSame('bed-3east-7', $e->changes[0]['object_id']);
        $this->assertSame('status', $e->changes[0]['attr']);
        $this->assertSame('occupied', $e->changes[0]['value']);
    }

    public function test_discharge_vacates_the_bed(): void
    {
        $e = EmissionMap::forFlowEvent($this->flowRow([
            'event_type' => 'discharge',
            'metadata' => [],
            'to_source_location_code' => '3East',
            'bed' => '7',
        ]));

        $this->assertSame('vacated', $e->changes[0]['value']);
    }

    public function test_non_placement_activity_records_no_bed_change(): void
    {
        // A medication event that happens to carry a bed must NOT move bed status.
        $e = EmissionMap::forFlowEvent($this->flowRow([
            'event_type' => 'medication',
            'to_source_location_code' => '3East',
            'bed' => '7',
        ]));

        $this->assertSame([], $e->changes);
    }

    public function test_case_timing_emits_phase_event_with_object_changes(): void
    {
        $row = (object) [
            'timing_id' => 5501,
            'case_id' => 4242,
            'phase' => 'Procedure',
            'planned_start' => '2026-06-30T07:30:00-04:00',
            'actual_start' => '2026-06-30T07:37:00-04:00',
            'planned_duration' => 212,
            'actual_duration' => 212,
            'variance' => 0,
            'room_id' => 9,
        ];

        $e = EmissionMap::forCaseTiming($row);
        $this->assertSame('Procedure', $e->activity);
        $this->assertSame('ct-5501', $e->id);
        $this->assertSame('orcase-4242', $this->objectByType($e, 'OR Case')['id']);
        $this->assertSame('orsuite-9', $this->objectByType($e, 'OR Suite')['id']);

        $byObject = [];
        foreach ($e->changes as $c) {
            $byObject[$c['object_id']] = $c;
        }
        $this->assertSame('Procedure', $byObject['orcase-4242']['value'], 'OR Case phase change');
        $this->assertSame('phase', $byObject['orcase-4242']['attr']);
        $this->assertSame('running', $byObject['orsuite-9']['value'], 'OR Suite status while a Procedure runs');
    }

    public function test_case_timing_without_any_start_is_skipped(): void
    {
        $row = (object) ['timing_id' => 1, 'case_id' => 2, 'phase' => 'Recovery', 'planned_start' => null, 'actual_start' => null, 'room_id' => 3];
        $this->assertNull(EmissionMap::forCaseTiming($row));
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
