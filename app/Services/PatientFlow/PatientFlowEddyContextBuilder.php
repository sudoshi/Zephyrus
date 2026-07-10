<?php

namespace App\Services\PatientFlow;

use Carbon\CarbonImmutable;

class PatientFlowEddyContextBuilder
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $lens
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>|null  $demoScenario
     * @param  array<string, mixed>|null  $scope
     * @return array<string, mixed>
     */
    public function build(
        array $payload,
        array $lens,
        string $roleId,
        CarbonImmutable $time,
        array $filters,
        ?array $demoScenario,
        ?array $scope = null,
    ): array {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $occupancy = is_array($payload['occupancy'] ?? null) ? $payload['occupancy'] : [];
        $topBarriers = array_values(array_map(
            fn (array $barrier): array => $this->barrierForContext($barrier),
            is_array($summary['top_barriers'] ?? null) ? $summary['top_barriers'] : [],
        ));

        return [
            'surface' => 'patient_flow_4d',
            'role' => [
                'id' => $roleId,
                'patient_dots' => (string) ($lens['patient_dots'] ?? 'none'),
                'scope_default' => (string) ($lens['scope_default'] ?? 'house'),
            ],
            'scope' => $scope ?? [
                'type' => 'house',
                'filters' => $this->contextFilters($filters),
            ],
            'as_of' => $time->toJSON(),
            'redaction' => [
                'patient_dots' => (string) ($lens['patient_dots'] ?? 'none'),
                'patient_identifiers_included' => ($lens['patient_dots'] ?? null) === 'full',
                'aggregate_only' => ($lens['patient_dots'] ?? null) === 'none',
            ],
            'current_metrics' => [
                'active' => (int) ($summary['active'] ?? 0),
                'delayed' => (int) ($summary['delayed'] ?? 0),
                'watch' => (int) ($summary['watch'] ?? 0),
                'ready_to_move' => (int) ($summary['ready_to_move'] ?? 0),
                'transport_delays' => (int) ($summary['transport_delays'] ?? 0),
                'evs_delays' => (int) ($summary['evs_delays'] ?? 0),
                'avg_stay_minutes' => (int) ($summary['avg_stay_minutes'] ?? 0),
                'timer_status_counts' => $summary['timer_status_counts'] ?? ['ok' => 0, 'watch' => 0, 'delayed' => 0],
                'persona' => $summary['persona'] ?? [],
            ],
            'affected_service_lines' => array_values($summary['service_lines'] ?? []),
            'top_barriers' => $topBarriers,
            'barrier_owner_map' => $this->barrierOwnerMap($topBarriers),
            'recommended_focus_areas' => $this->recommendedFocusAreas($topBarriers),
            'source_lineage' => [
                'timer_sources' => $this->timerSourceCounts($occupancy),
                'demo_scenario' => $demoScenario,
                'generated_from' => 'patient-flow occupancy projection',
            ],
            'action_allowlist' => $this->actionAllowlist($lens, $roleId),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function contextFilters(array $filters): array
    {
        return [
            'floor' => $filters['floor'] ?? null,
            'service_line' => $filters['service_line'] ?? null,
            'category' => $filters['category'] ?? null,
        ];
    }

    /** @param array<string, mixed> $barrier */
    private function barrierForContext(array $barrier): array
    {
        return [
            'barrier_code' => $barrier['barrier_code'] ?? null,
            'label' => (string) ($barrier['label'] ?? 'Barrier'),
            'category' => $barrier['barrier_category'] ?? null,
            'reason' => $barrier['reason'] ?? null,
            'owner_role' => $barrier['owner_role'] ?? null,
            'count' => (int) ($barrier['count'] ?? 0),
            'service_lines' => array_values($barrier['service_lines'] ?? []),
            'rtdc_metrics' => array_values($barrier['rtdc_metrics'] ?? []),
            'eddy_summary' => $barrier['eddy_summary'] ?? null,
            'recommended_focus' => $barrier['recommended_focus'] ?? null,
        ];
    }

    /** @param list<array<string, mixed>> $topBarriers */
    private function barrierOwnerMap(array $topBarriers): array
    {
        $map = [];
        foreach ($topBarriers as $barrier) {
            $code = $barrier['barrier_code'] ?? null;
            if (! is_string($code) || $code === '') {
                continue;
            }

            $map[$code] = [
                'label' => $barrier['label'] ?? null,
                'owner_role' => $barrier['owner_role'] ?? null,
                'count' => $barrier['count'] ?? 0,
            ];
        }

        return $map;
    }

    /** @param list<array<string, mixed>> $topBarriers */
    private function recommendedFocusAreas(array $topBarriers): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (array $barrier): ?string => is_string($barrier['recommended_focus'] ?? null) ? $barrier['recommended_focus'] : null,
            $topBarriers,
        ))));
    }

    /** @param list<array<string, mixed>> $occupancy */
    private function timerSourceCounts(array $occupancy): array
    {
        $counts = [];
        foreach ($occupancy as $item) {
            foreach (is_array($item['timers'] ?? null) ? $item['timers'] : [] as $timer) {
                if (($timer['status'] ?? 'ok') === 'ok') {
                    continue;
                }

                $source = is_string($timer['source'] ?? null) && $timer['source'] !== ''
                    ? $timer['source']
                    : 'unknown';
                $counts[$source] = ($counts[$source] ?? 0) + 1;
            }
        }

        ksort($counts);

        return $counts;
    }

    /** @param array<string, mixed> $lens */
    private function actionAllowlist(array $lens, string $roleId): array
    {
        $actions = array_values(array_filter(array_map('strval', is_array($lens['actions'] ?? null) ? $lens['actions'] : [])));
        $actions[] = 'summarize_barriers';

        if (in_array($roleId, ['bed_manager', 'house_supervisor', 'capacity_lead'], true)) {
            $actions[] = 'draft_huddle_summary';
        }

        return array_values(array_unique($actions));
    }
}
