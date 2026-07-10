<?php

namespace App\Services\PatientFlow;

class FhirBundleFactory
{
    /**
     * Build only from a payload that has already crossed its authorization and
     * redaction boundary. Patient Flow web callers must use this method.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function makeFromPayload(array $payload): array
    {
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
