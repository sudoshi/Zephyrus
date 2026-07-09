<?php

namespace App\Services\PatientFlow;

use App\Models\PatientFlow\OccupancySnapshot;
use Carbon\CarbonImmutable;

class PatientFlowOccupancyHistoryService
{
    /**
     * @param  array<string, mixed>  $lens
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function history(array $lens, string $roleId, array $filters): array
    {
        $to = $this->time($filters['to'] ?? $filters['asOf'] ?? null, CarbonImmutable::now());
        $from = $this->time($filters['from'] ?? null, $to->subHours(24));
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $limit = max(1, min(500, (int) ($filters['limit'] ?? 120)));
        $scenario = is_string($filters['demo'] ?? null) && ($filters['demo'] ?? '') !== ''
            ? (string) $filters['demo']
            : (is_string($filters['scenario'] ?? null) ? (string) $filters['scenario'] : null);

        $query = OccupancySnapshot::query()
            ->with('facilitySpace')
            ->whereBetween('snapshot_at', [$from, $to])
            ->orderBy('snapshot_at')
            ->orderBy('facility_space_id')
            ->limit($limit);

        if (($filters['floor'] ?? null) !== null && $filters['floor'] !== '' && $filters['floor'] !== 'all') {
            $query->whereHas('facilitySpace', fn ($space) => $space->where('floor_number', (int) $filters['floor']));
        }

        if (($filters['service_line'] ?? null) !== null && $filters['service_line'] !== '' && $filters['service_line'] !== 'all') {
            $serviceLine = (string) $filters['service_line'];
            $query->whereHas('facilitySpace', fn ($space) => $space->where('service_line_code', $serviceLine));
        }

        $identityVisible = ($lens['patient_dots'] ?? null) === 'full';
        $snapshots = $query->get()->map(function (OccupancySnapshot $snapshot) use ($identityVisible, $scenario): array {
            $space = $snapshot->facilitySpace;
            $details = is_array($snapshot->occupancy_details) ? $snapshot->occupancy_details : [];

            return [
                'snapshot_at' => $snapshot->snapshot_at?->toIso8601String(),
                'facility_space' => [
                    'facility_space_id' => (int) $snapshot->facility_space_id,
                    'space_code' => $space?->space_code,
                    'space_name' => $space?->space_name,
                    'space_category' => $space?->space_category,
                    'floor_number' => $space?->floor_number,
                    'service_line' => $space?->service_line_code,
                ],
                'active_patient_count' => (int) $snapshot->active_patient_count,
                'service_line_counts' => $snapshot->service_line_counts ?: [],
                'acuity_counts' => $snapshot->acuity_counts ?: [],
                'occupancy_details' => $identityVisible ? $details : $this->redactDetails($details),
                'timer_status_counts' => $snapshot->timer_status_counts ?: [],
                'service_line_timer_counts' => $snapshot->service_line_timer_counts ?: [],
                'persona_timer_counts' => $snapshot->persona_timer_counts ?: [],
                'active_blocker_counts' => $snapshot->active_blocker_counts ?: [],
                'projection_window' => $snapshot->projection_window ?: [],
                'lineage' => [
                    'source_table' => 'flow_core.occupancy_snapshots',
                    'generated_from_event_id' => $snapshot->generated_from_event_id,
                    'source_mode' => $scenario ? 'synthetic_demo' : 'snapshot',
                    'scenario_key' => $scenario,
                ],
            ];
        })->values()->all();

        return [
            'window' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'limit' => $limit,
            ],
            'lens' => [
                'role_id' => $roleId,
                'patient_dots' => $lens['patient_dots'] ?? null,
                'projection_kinds' => $lens['projection_kinds'] ?? [],
            ],
            'scenario' => $scenario,
            'history' => $snapshots,
            'summary' => [
                'snapshots' => count($snapshots),
                'active_patient_count' => array_sum(array_map(
                    fn (array $snapshot): int => (int) $snapshot['active_patient_count'],
                    $snapshots,
                )),
                'source_mode' => $scenario ? 'synthetic_demo' : 'snapshot',
                'redacted' => ! $identityVisible,
            ],
            'generated_at' => now()->toJSON(),
        ];
    }

    private function time(mixed $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return $fallback;
        }

        return CarbonImmutable::parse($value);
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function redactDetails(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            if (in_array($key, ['patient_id', 'patient_display_id', 'encounter_id', 'patient_ref', 'mrn'], true)) {
                continue;
            }

            $redacted[$key] = $this->redactDetails($item);
        }

        return $redacted;
    }
}
