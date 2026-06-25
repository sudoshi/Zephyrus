<?php

namespace App\Services\PatientFlow;

use App\Models\PatientFlow\FlowEvent;

class FhirBundleFactory
{
    public function __construct(private readonly FlowEventRepository $events) {}

    /**
     * @return array<string, mixed>
     */
    public function make(FlowEvent $event): array
    {
        $payload = $this->events->serializeEvent($event);
        $locationId = $payload['to_location'] ?: 'unknown';

        $encounter = [
            'resourceType' => 'Encounter',
            'id' => $payload['encounter_id'],
            'identifier' => [[
                'system' => 'urn:zephyrus:encounter',
                'value' => $payload['encounter_id'],
            ]],
            'status' => $payload['fhir_encounter_status'] ?: 'in-progress',
            'class' => [
                'system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode',
                'code' => $payload['fhir_encounter_class'] ?: 'unknown',
            ],
            'subject' => ['reference' => 'Patient/'.$payload['patient_id']],
            'period' => ['start' => $payload['occurred_at']],
            'location' => [[
                'location' => ['reference' => 'Location/'.$locationId],
                'status' => $payload['event_type'] === 'discharge' ? 'completed' : 'active',
                'period' => ['start' => $payload['occurred_at']],
            ]],
        ];

        $patient = [
            'resourceType' => 'Patient',
            'id' => $payload['patient_id'],
            'identifier' => [[
                'system' => 'urn:zephyrus:synthetic-patient',
                'value' => $payload['patient_display_id'],
            ]],
        ];

        $location = [
            'resourceType' => 'Location',
            'id' => $locationId,
            'name' => $payload['location_name'] ?: ($payload['to_location'] ?: 'Unknown location'),
            'physicalType' => [
                'coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/location-physical-type',
                    'code' => $payload['bed'] ? 'bd' : 'ro',
                ]],
            ],
        ];

        return [
            'resourceType' => 'Bundle',
            'type' => 'message',
            'timestamp' => $payload['recorded_at'],
            'entry' => [
                ['resource' => $encounter],
                ['resource' => $patient],
                ['resource' => $location],
            ],
        ];
    }
}
