<?php

namespace App\Services\PatientFlow;

class PatientFlowScenarioRegistry
{
    /** @var array<string, array<string, mixed>> */
    private const SCENARIOS = [
        'rtdc_barriers' => [
            'label' => 'RTDC barrier demonstration',
            'prd_demo_id' => 'PRD-PF-001',
            'enabled' => true,
            'source_mode' => 'synthetic_demo',
            'owning_phase' => 'B4',
            'description' => 'Composite Patient Flow 4D barrier overlay for the house-wide navigator.',
            'aliases' => ['barriers', 'rtdc', 'replace'],
            'query' => ['demo' => 'barriers', 'include' => 'eddy_context'],
            'handoff_surfaces' => ['patient_flow_4d', 'rtdc', 'eddy'],
            'personas' => ['bed_manager', 'capacity_lead', 'house_supervisor'],
        ],
        'ed_boarding_surge' => [
            'label' => 'ED boarder to inpatient bed',
            'prd_demo_id' => 'PRD-DEMO-002',
            'enabled' => true,
            'source_mode' => 'synthetic_demo',
            'owning_phase' => 'B4/B7/B8',
            'description' => 'ED boarded admit creates placement, transport, and critical-care pressure.',
            'aliases' => ['ed_boarder_to_bed'],
            'query' => ['demo' => 'ed_boarding_surge', 'include' => 'eddy_context'],
            'handoff_surfaces' => ['patient_flow_4d', 'rtdc', 'transport', 'hummingbird', 'eddy'],
            'personas' => ['bed_manager', 'transport', 'charge_nurse'],
        ],
        'critical_care_outflow' => [
            'label' => 'ICU downgrade unlocks capacity',
            'prd_demo_id' => 'PRD-DEMO-003',
            'enabled' => true,
            'source_mode' => 'synthetic_demo',
            'owning_phase' => 'B4/B8',
            'description' => 'Critical-care stepdown delay blocks the next high-acuity admission.',
            'aliases' => ['icu_downgrade_capacity'],
            'query' => ['demo' => 'critical_care_outflow', 'include' => 'eddy_context'],
            'handoff_surfaces' => ['patient_flow_4d', 'rtdc', 'staffing', 'eddy'],
            'personas' => ['capacity_lead', 'bed_manager', 'charge_nurse'],
        ],
        'or_pacu_hold' => [
            'label' => 'OR delay and PACU hold',
            'prd_demo_id' => 'PRD-DEMO-004',
            'enabled' => true,
            'source_mode' => 'synthetic_demo',
            'owning_phase' => 'B4/B7/B8',
            'description' => 'PACU hold exposes downstream bed, EVS, and transport pressure.',
            'aliases' => ['or_delay_pacu_hold'],
            'query' => ['demo' => 'or_pacu_hold', 'include' => 'eddy_context'],
            'handoff_surfaces' => ['patient_flow_4d', 'perioperative', 'evs', 'transport', 'eddy'],
            'personas' => ['periop_manager', 'evs', 'transport'],
        ],
        'evs_backlog' => [
            'label' => 'EVS backlog delays bed release',
            'prd_demo_id' => 'PRD-DEMO-005',
            'enabled' => true,
            'source_mode' => 'synthetic_demo',
            'owning_phase' => 'B4/B6/B8',
            'description' => 'Dirty bed and isolation-clean backlog delays placement and discharge flow.',
            'aliases' => ['discharge_barrier_resolution'],
            'query' => ['demo' => 'evs_backlog', 'include' => 'eddy_context'],
            'handoff_surfaces' => ['patient_flow_4d', 'evs', 'transport', 'hummingbird', 'eddy'],
            'personas' => ['evs', 'bed_manager', 'transport'],
        ],
        'weekend_staffing_gap' => [
            'label' => 'Weekend staffing gap and safe capacity',
            'prd_demo_id' => 'PRD-DEMO-006',
            'enabled' => true,
            'source_mode' => 'synthetic_demo',
            'owning_phase' => 'B4/B6/B7/B8',
            'description' => 'Receiving-unit staffing variance blocks safe stepdown and capacity relief.',
            'aliases' => ['staffing_gap_safe_capacity'],
            'query' => ['demo' => 'weekend_staffing_gap', 'include' => 'eddy_context'],
            'handoff_surfaces' => ['patient_flow_4d', 'staffing', 'hummingbird', 'eddy'],
            'personas' => ['staffing_coordinator', 'charge_nurse', 'capacity_lead'],
        ],
        'post_acute_discharge_gridlock' => [
            'label' => 'Post-acute discharge gridlock',
            'prd_demo_id' => 'PRD-DEMO-005',
            'enabled' => true,
            'source_mode' => 'synthetic_demo',
            'owning_phase' => 'B4/B8',
            'description' => 'Authorization, DME, and post-acute packet delays add avoidable bed days.',
            'aliases' => ['post_acute_gridlock'],
            'query' => ['demo' => 'post_acute_discharge_gridlock', 'include' => 'eddy_context'],
            'handoff_surfaces' => ['patient_flow_4d', 'discharge', 'case_management', 'eddy'],
            'personas' => ['hospitalist', 'bed_manager', 'pi_lead'],
        ],
    ];

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_values(array_map(
            fn (string $key, array $scenario): array => $this->decorate($key, $scenario),
            array_keys(self::SCENARIOS),
            self::SCENARIOS,
        ));
    }

    public function canonicalKey(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = strtolower(trim($value));
        if (isset(self::SCENARIOS[$normalized])) {
            return $normalized;
        }

        foreach (self::SCENARIOS as $key => $scenario) {
            if (in_array($normalized, $scenario['aliases'] ?? [], true)) {
                return $key;
            }
        }

        return null;
    }

    public function isDemoRequestValue(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true)
            || $this->canonicalKey($value) !== null;
    }

    /** @return list<string> */
    public function enabledKeys(): array
    {
        return array_values(array_map(
            'strval',
            array_keys(array_filter(self::SCENARIOS, fn (array $scenario): bool => (bool) $scenario['enabled'])),
        ));
    }

    /** @param array<string, mixed> $scenario */
    private function decorate(string $key, array $scenario): array
    {
        return $scenario + [
            'key' => $key,
            'status' => $scenario['enabled'] ? 'enabled' : 'disabled',
            'history_supported' => true,
            'live_supported' => false,
            'limitations' => $scenario['source_mode'] === 'synthetic_demo'
                ? ['Synthetic demo overlay; not proof of a live external integration.']
                : [],
        ];
    }
}
