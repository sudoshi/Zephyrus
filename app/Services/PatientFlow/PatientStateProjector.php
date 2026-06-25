<?php

namespace App\Services\PatientFlow;

use Carbon\CarbonImmutable;

class PatientStateProjector
{
    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, array<string, mixed>>
     */
    public function reconstruct(array $events, ?string $asOf = null): array
    {
        $cutoff = $asOf ? CarbonImmutable::parse($asOf)->utc() : null;
        usort($events, fn (array $a, array $b): int => strcmp((string) $a['occurred_at'], (string) $b['occurred_at']));

        $active = [];
        foreach ($events as $event) {
            if ($cutoff && CarbonImmutable::parse((string) $event['occurred_at'])->utc()->greaterThan($cutoff)) {
                continue;
            }

            $patientId = (string) $event['patient_id'];
            if (in_array($event['event_type'] ?? null, ['discharge', 'cancel_admit'], true)) {
                unset($active[$patientId]);

                continue;
            }

            if (! empty($event['to_location']) && in_array($event['event_category'] ?? null, ['movement', 'order', 'observation', 'medication', 'schedule'], true)) {
                $active[$patientId] = [
                    'patient_id' => $patientId,
                    'patient_display_id' => $event['patient_display_id'],
                    'encounter_id' => $event['encounter_id'],
                    'location' => $event['to_location'],
                    'event_type' => $event['event_type'],
                    'patient_class' => $event['patient_class'] ?? null,
                    'service_line' => $event['service_line'] ?? null,
                    'last_event_at' => $event['occurred_at'],
                    'facility_space_id' => $event['facility_space_id'] ?? null,
                    'location_name' => $event['location_name'] ?? null,
                    'location_floor' => $event['location_floor'] ?? null,
                ];
            }
        }

        return $active;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, int>
     */
    public function occupancyByLocation(array $events, ?string $asOf = null): array
    {
        $occupancy = [];
        foreach ($this->reconstruct($events, $asOf) as $state) {
            $location = $state['location'] ?? null;
            if ($location) {
                $occupancy[$location] = ($occupancy[$location] ?? 0) + 1;
            }
        }

        return $occupancy;
    }
}
