<?php

namespace App\Services\PatientFlow;

use Carbon\CarbonImmutable;

class PatientFlowDemoBarrierScenario
{
    public function __construct(private readonly PatientFlowScenarioRegistry $scenarios) {}

    /**
     * @param  array<string, array<string, mixed>>  $locations
     * @param  array<string, mixed>  $filters
     * @return array{
     *     events: list<array<string, mixed>>,
     *     projections: list<array<string, mixed>>,
     *     scenario: array<string, mixed>
     * }
     */
    public function build(array $locations, CarbonImmutable $time, array $filters): array
    {
        $used = [];
        $events = [];
        $projections = [];
        $templates = $this->templates();
        $scenarioKey = $this->scenarioKey($filters);

        foreach ($templates as $index => $template) {
            if ($scenarioKey !== 'rtdc_barriers' && ! in_array($scenarioKey, $template['scenario_keys'] ?? [], true)) {
                continue;
            }

            $picked = $this->pickLocation($locations, $template['criteria'], $used);
            if (! $picked) {
                continue;
            }

            $locationCode = $picked['code'];
            $location = $picked['location'];
            $serviceLine = (string) ($template['service_line'] ?? $location['service_line'] ?? 'capacity');

            if (! $this->passesFilters($location, $serviceLine, $filters)) {
                continue;
            }

            $patientRef = 'DEMO-FLOW-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
            $encounterRef = 'DEMO-VISIT-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
            $arrivedAt = $time->subMinutes((int) $template['stay_minutes']);

            $events[] = [
                'event_id' => 'demo-flow-current-'.$index,
                'event_category' => 'movement',
                'event_type' => (string) ($template['event_type'] ?? 'transfer'),
                'message_type' => 'ADT',
                'trigger_event' => 'A02',
                'patient_id' => $patientRef,
                'patient_display_id' => 'Demo '.$patientRef,
                'encounter_id' => $encounterRef,
                'occurred_at' => $arrivedAt->toJSON(),
                'recorded_at' => $arrivedAt->toJSON(),
                'from_location' => $template['came_from'] ?? null,
                'to_location' => $locationCode,
                'point_of_care' => $location['unit_code'] ?? null,
                'room' => $locationCode,
                'bed' => $locationCode,
                'patient_class' => 'I',
                'fhir_encounter_status' => 'in-progress',
                'fhir_encounter_class' => 'inpatient',
                'service_line' => $serviceLine,
                'priority' => $template['priority'] ?? 'routine',
                'diagnosis_codes' => [],
                'order_codes' => [],
                'observation_codes' => [],
                'medication_codes' => [],
                'facility_space_id' => $location['facility_space_id'] ?? null,
                'location_name' => $location['name'] ?? $locationCode,
                'location_category' => $location['category'] ?? null,
                'location_floor' => $location['floor'] ?? null,
                'location_service_line' => $location['service_line'] ?? $serviceLine,
                'position_ft' => $location['position_ft'] ?? null,
                'position_m' => $location['position_m'] ?? null,
                'unit_code' => $location['unit_code'] ?? null,
                'metadata' => [
                    'demo_scenario' => $scenarioKey,
                    'scenario_label' => $template['label'],
                    'rtdc_factor' => $template['rtdc_factor'],
                ],
            ];

            foreach ($template['timers'] as $timerIndex => $timer) {
                $projections[] = [
                    't' => $time->addMinutes((int) $timer['minutes'])->toJSON(),
                    'kind' => (string) $timer['projection_kind'],
                    'timer_kind' => $timer['timer_kind'] ?? null,
                    'confidence' => $timer['confidence'] ?? 'probable',
                    'unit_id' => null,
                    'bed_id' => null,
                    'room' => $locationCode,
                    'entity' => ['type' => 'patient', 'ref' => $encounterRef],
                    'patient_context_ref' => 'ptok_demo_'.$index,
                    'label' => (string) $timer['label'],
                    'value' => null,
                    'band' => null,
                    'ends_at' => null,
                    'derived' => true,
                    'reason' => (string) $timer['reason'],
                    'barrier_code' => (string) $timer['barrier_code'],
                    'owner_role' => (string) $timer['owner_role'],
                    'blocks' => (string) $timer['blocks'],
                    'impact' => (string) $timer['impact'],
                    'provenance' => [
                        'service' => (string) ($timer['source'] ?? 'RTDC demo barrier model'),
                        'reliability' => 0.84,
                    ],
                    '_patient_ref' => $patientRef,
                    '_demo_timer_id' => 'demo-flow-timer-'.$index.'-'.$timerIndex,
                ];
            }
        }

        return [
            'events' => $events,
            'projections' => $projections,
            'scenario' => [
                'key' => $scenarioKey,
                'label' => $this->scenarioLabel($scenarioKey),
                'enabled' => true,
                'patients' => count($events),
                'barrier_timers' => count($projections),
                'generated_at' => $time->toJSON(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function templates(): array
    {
        return [
            [
                'scenario_keys' => ['ed_boarding_surge'],
                'label' => 'ED boarded ICU admit',
                'criteria' => ['acuity' => 'emergency', 'category' => 'bay', 'code_prefix' => 'ED-'],
                'service_line' => 'emergency_medicine',
                'came_from' => 'ED-WAITING',
                'stay_minutes' => 780,
                'priority' => 'urgent',
                'rtdc_factor' => 'ED boarding compounds ICU and transport capacity.',
                'timers' => [
                    [
                        'projection_kind' => 'transport_due',
                        'timer_kind' => 'arrival_transport',
                        'barrier_code' => 'transport_oxygen_team_delayed',
                        'label' => 'ICU arrival transport',
                        'minutes' => -35,
                        'reason' => 'ICU bed is assigned but the oxygen-capable transport team is still tied up on CT and ED runs.',
                        'owner_role' => 'transport',
                        'blocks' => 'ED treatment room release and ICU admission start',
                        'impact' => 'ED boarding persists and one monitored ED room stays unavailable.',
                        'source' => 'Transport command queue',
                    ],
                    [
                        'projection_kind' => 'expected_discharge',
                        'timer_kind' => 'readiness',
                        'barrier_code' => 'icu_handoff_staffing_pending',
                        'label' => 'ICU acceptance',
                        'minutes' => -18,
                        'reason' => 'Receiving ICU nurse-to-nurse handoff is waiting on staffing confirmation.',
                        'owner_role' => 'charge_nurse',
                        'blocks' => 'Critical-care admit finalization',
                        'impact' => 'Critical-care demand cannot be absorbed on plan.',
                        'source' => 'Bed placement huddle',
                    ],
                ],
            ],
            [
                'scenario_keys' => ['or_pacu_hold', 'evs_backlog'],
                'label' => 'PACU hold for dirty inpatient bed',
                'criteria' => ['category' => 'bay', 'code_prefix' => 'PACU-'],
                'service_line' => 'perioperative',
                'came_from' => 'OR-12',
                'stay_minutes' => 255,
                'priority' => 'urgent',
                'rtdc_factor' => 'OR recovery capacity is constrained by downstream EVS readiness.',
                'timers' => [
                    [
                        'projection_kind' => 'evs_due',
                        'timer_kind' => 'evs',
                        'barrier_code' => 'evs_isolation_clean_delayed',
                        'label' => 'Receiving bed EVS turn',
                        'minutes' => -28,
                        'reason' => 'Isolation clean on the assigned surgical bed exceeded target after discharge transport arrived late.',
                        'owner_role' => 'evs',
                        'blocks' => 'PACU discharge and next OR recovery slot',
                        'impact' => 'PACU hold risks OR room recovery delay.',
                        'source' => 'EVS bed board',
                    ],
                    [
                        'projection_kind' => 'transport_due',
                        'timer_kind' => 'next_transport',
                        'barrier_code' => 'pacu_floor_transport_at_risk',
                        'label' => 'PACU to floor move',
                        'minutes' => 22,
                        'reason' => 'Transport can move as soon as EVS releases the bed.',
                        'owner_role' => 'transport',
                        'blocks' => 'Surgical floor placement',
                        'impact' => 'If EVS slips again, the transport slot is lost.',
                        'source' => 'Transport command queue',
                    ],
                ],
            ],
            [
                'scenario_keys' => ['post_acute_discharge_gridlock'],
                'label' => 'Medicine discharge barrier',
                'criteria' => ['service_line' => 'adult_med_surg', 'unit_prefix' => 'MS'],
                'service_line' => 'adult_med_surg',
                'came_from' => 'MS4A-HALL',
                'stay_minutes' => 2110,
                'priority' => 'routine',
                'rtdc_factor' => 'A discharge delay keeps an inpatient bed unavailable for ED demand.',
                'timers' => [
                    [
                        'projection_kind' => 'expected_discharge',
                        'timer_kind' => 'readiness',
                        'barrier_code' => 'discharge_dme_authorization_pending',
                        'label' => 'Discharge clearance',
                        'minutes' => -55,
                        'reason' => 'Home oxygen authorization and DME delivery confirmation are incomplete after discharge order.',
                        'owner_role' => 'hospitalist',
                        'blocks' => 'Bed release for next ED admit',
                        'impact' => 'One med-surg bed remains occupied beyond plan.',
                        'source' => 'Discharge barrier tracker',
                    ],
                ],
            ],
            [
                'scenario_keys' => ['critical_care_outflow', 'weekend_staffing_gap'],
                'label' => 'Critical-care stepdown hold',
                'criteria' => ['service_line' => 'critical_care', 'unit_prefix' => 'MICU'],
                'service_line' => 'critical_care',
                'came_from' => 'MICU3-RESUS',
                'stay_minutes' => 1530,
                'priority' => 'urgent',
                'rtdc_factor' => 'ICU outflow delay blocks the next high-acuity admission.',
                'timers' => [
                    [
                        'projection_kind' => 'transport_due',
                        'timer_kind' => 'next_transport',
                        'barrier_code' => 'stepdown_staffing_variance',
                        'label' => 'Stepdown move',
                        'minutes' => -42,
                        'reason' => 'Telemetry stepdown room is assigned but the receiving unit is holding for a 1:4 staffing variance.',
                        'owner_role' => 'bed_manager',
                        'blocks' => 'ICU bed release for ED admit',
                        'impact' => 'Critical-care capacity remains constrained.',
                        'source' => 'RTDC placement queue',
                    ],
                ],
            ],
            [
                'scenario_keys' => ['or_pacu_hold'],
                'label' => 'Cath lab pickup watch',
                'criteria' => ['service_line' => 'cardiology', 'unit_prefix' => 'TEL7'],
                'service_line' => 'cardiology',
                'came_from' => 'TEL7A-HALL',
                'stay_minutes' => 530,
                'priority' => 'time_sensitive',
                'rtdc_factor' => 'Procedure pickup timing competes with transport capacity.',
                'timers' => [
                    [
                        'projection_kind' => 'transport_due',
                        'timer_kind' => 'next_transport',
                        'barrier_code' => 'cath_pickup_handoff_pending',
                        'label' => 'Cath lab pickup',
                        'minutes' => 18,
                        'reason' => 'Nurse handoff and consent packet are due before the transport window closes.',
                        'owner_role' => 'transport',
                        'blocks' => 'Cath lab on-time start',
                        'impact' => 'A missed pickup shifts the cath schedule and downstream recovery demand.',
                        'source' => 'Cath lab schedule',
                    ],
                ],
            ],
            [
                'scenario_keys' => ['post_acute_discharge_gridlock'],
                'label' => 'Rehab placement expiration',
                'criteria' => ['service_line' => 'rehabilitation', 'unit_prefix' => 'AIR'],
                'service_line' => 'rehabilitation',
                'came_from' => 'AIR11-GYM',
                'stay_minutes' => 95,
                'priority' => 'routine',
                'rtdc_factor' => 'Post-acute acceptance timing affects bed release and avoidable days.',
                'timers' => [
                    [
                        'projection_kind' => 'expected_discharge',
                        'timer_kind' => 'readiness',
                        'barrier_code' => 'post_acute_packet_reconciliation_pending',
                        'label' => 'Post-acute acceptance',
                        'minutes' => 14,
                        'reason' => 'External rehab acceptance expires soon unless the packet is reconciled.',
                        'owner_role' => 'case_management',
                        'blocks' => 'Same-day discharge execution',
                        'impact' => 'Missed acceptance adds another avoidable bed day.',
                        'source' => 'Post-acute placement queue',
                    ],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $filters */
    private function scenarioKey(array $filters): string
    {
        $value = is_string($filters['demo'] ?? null) ? (string) $filters['demo'] : null;

        return $this->scenarios->canonicalKey($value) ?? 'rtdc_barriers';
    }

    private function scenarioLabel(string $scenarioKey): string
    {
        foreach ($this->scenarios->all() as $scenario) {
            if (($scenario['key'] ?? null) === $scenarioKey) {
                return (string) $scenario['label'];
            }
        }

        return 'RTDC barrier demonstration';
    }

    /**
     * @param  array<string, array<string, mixed>>  $locations
     * @param  array<string, mixed>  $criteria
     * @param  array<string, bool>  $used
     * @return array{code: string, location: array<string, mixed>}|null
     */
    private function pickLocation(array $locations, array $criteria, array &$used): ?array
    {
        $candidates = $this->candidateLocations($locations, $criteria, $used, false);
        if ($candidates === []) {
            $candidates = $this->candidateLocations($locations, $criteria, $used, true);
        }

        if ($candidates === []) {
            foreach ($locations as $code => $location) {
                if (! empty($location['position_m'])) {
                    $used[$code] = true;

                    return ['code' => $code, 'location' => $location];
                }
            }

            return null;
        }

        usort($candidates, fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['code'], $b['code']));
        $picked = $candidates[0];
        $used[$picked['code']] = true;

        return ['code' => $picked['code'], 'location' => $picked['location']];
    }

    /**
     * @param  array<string, array<string, mixed>>  $locations
     * @param  array<string, mixed>  $criteria
     * @param  array<string, bool>  $used
     * @return list<array{code: string, location: array<string, mixed>, score: int}>
     */
    private function candidateLocations(array $locations, array $criteria, array $used, bool $allowUsed): array
    {
        $candidates = [];
        foreach ($locations as $code => $location) {
            if ((! $allowUsed && isset($used[$code])) || empty($location['position_m'])) {
                continue;
            }

            $score = $this->scoreLocation($code, $location, $criteria);
            if ($score <= 0) {
                continue;
            }

            $candidates[] = ['code' => $code, 'location' => $location, 'score' => $score];
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $location
     * @param  array<string, mixed>  $criteria
     */
    private function scoreLocation(string $code, array $location, array $criteria): int
    {
        $score = 0;
        $metadata = is_array($location['metadata'] ?? null) ? $location['metadata'] : [];

        if (($criteria['service_line'] ?? null) && ($location['service_line'] ?? null) === $criteria['service_line']) {
            $score += 80;
        }
        if (($criteria['category'] ?? null) && ($location['category'] ?? null) === $criteria['category']) {
            $score += 40;
        }
        if (($criteria['acuity'] ?? null) && (($location['acuity'] ?? null) === $criteria['acuity'] || ($metadata['acuity'] ?? null) === $criteria['acuity'])) {
            $score += 35;
        }
        if (($criteria['unit_prefix'] ?? null) && str_starts_with((string) ($location['unit_code'] ?? ''), (string) $criteria['unit_prefix'])) {
            $score += 30;
        }
        if (($criteria['code_prefix'] ?? null) && str_starts_with($code, (string) $criteria['code_prefix'])) {
            $score += 30;
        }

        return $score;
    }

    /**
     * @param  array<string, mixed>  $location
     * @param  array<string, mixed>  $filters
     */
    private function passesFilters(array $location, string $serviceLine, array $filters): bool
    {
        if (isset($filters['floor']) && $filters['floor'] !== '' && $filters['floor'] !== 'all'
            && (string) ($location['floor'] ?? '') !== (string) $filters['floor']) {
            return false;
        }

        if (isset($filters['service_line']) && $filters['service_line'] !== '' && $filters['service_line'] !== 'all'
            && $serviceLine !== $filters['service_line']) {
            return false;
        }

        if (isset($filters['category']) && $filters['category'] !== '' && $filters['category'] !== 'all'
            && $filters['category'] !== 'movement') {
            return false;
        }

        return true;
    }
}
