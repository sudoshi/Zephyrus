<?php

namespace App\Services\PatientFlow;

use App\Models\Evs\EvsRequest;
use App\Models\PatientFlow\OccupancySnapshot;
use App\Models\Transport\TransportRequest;
use App\Models\User;
use App\Services\Mobile\MobilePatientContextService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

class PatientFlowOccupancyHistoryService
{
    private const DETAIL_IDENTIFIER_KEYS = [
        'patient_ref',
        '_patient_ref',
        'patient_id',
        'patient_display_id',
        'patient_name',
        'encounter_id',
        'encounter_ref',
        'downstream_patient_refs',
        'mrn',
        'medical_record_number',
    ];

    public function __construct(
        private readonly PatientFlowScenarioRegistry $scenarios,
        private readonly MobilePatientContextService $patients,
    ) {}

    /**
     * @param  array<string, mixed>  $lens
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function history(array $lens, string $roleId, array $filters, ?User $user = null): array
    {
        $to = $this->time('to', $filters['to'] ?? $filters['asOf'] ?? null, CarbonImmutable::now());
        $from = $this->time('from', $filters['from'] ?? null, $to->subHours(24));
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $limit = max(1, min(500, (int) ($filters['limit'] ?? 120)));
        $scenario = $this->scenarioKey($filters);
        $depth = (string) ($lens['patient_dots'] ?? 'none');
        $unitIds = $depth === 'unit' ? $this->visibleUnitIds($user) : [];
        $taskPatientRefs = $depth === 'task' ? $this->taskPatientRefs($roleId) : [];

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

        $snapshots = $query->get()->map(function (OccupancySnapshot $snapshot) use ($depth, $scenario, $taskPatientRefs, $unitIds): array {
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
                'occupancy_details' => $this->detailsForDepth($details, $depth, $unitIds, $taskPatientRefs),
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
                'patient_dots' => $depth,
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
                'redacted' => $depth !== 'full',
            ],
            'generated_at' => now()->toJSON(),
        ];
    }

    private function time(string $field, mixed $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return $fallback;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw new InvalidArgumentException("Invalid {$field} timestamp.");
        }
    }

    /**
     * @param  array<int, mixed>  $details
     * @param  list<int>  $unitIds
     * @param  list<string>  $taskPatientRefs
     * @return list<array<string, mixed>>
     */
    private function detailsForDepth(array $details, string $depth, array $unitIds, array $taskPatientRefs): array
    {
        if ($depth === 'full') {
            return $this->redactDetails(array_values(array_filter($details, 'is_array')));
        }

        if ($depth === 'unit') {
            return $this->redactDetails(array_values(array_filter(
                $details,
                fn (mixed $detail): bool => is_array($detail)
                    && isset($detail['unit_id'])
                    && in_array((int) $detail['unit_id'], $unitIds, true),
            )));
        }

        if ($depth === 'task') {
            return $this->redactDetails(array_values(array_filter(
                $details,
                fn (mixed $detail): bool => is_array($detail)
                    && isset($detail['patient_ref'])
                    && in_array((string) $detail['patient_ref'], $taskPatientRefs, true),
            )));
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $details
     * @return list<array<string, mixed>>
     */
    private function redactDetails(array $details): array
    {
        return array_map(fn (array $detail): array => $this->redactDetail($detail), $details);
    }

    /** @param array<string, mixed> $detail */
    private function redactDetail(array $detail): array
    {
        $patientRef = $this->patientRefForDetail($detail);

        $detail = $this->stripIdentifierKeys($detail);

        if ($patientRef !== null) {
            $detail['patient_context_ref'] = $this->patients->contextRefFor($patientRef);
        }

        return $detail;
    }

    /** @param array<string, mixed> $detail */
    private function patientRefForDetail(array $detail): ?string
    {
        foreach (['patient_ref', '_patient_ref', 'patient_id'] as $key) {
            $value = $detail[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /** @param array<array-key, mixed> $value */
    private function stripIdentifierKeys(array $value): array
    {
        $redacted = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::DETAIL_IDENTIFIER_KEYS, true)) {
                continue;
            }

            $redacted[$key] = is_array($item) ? $this->stripIdentifierKeys($item) : $item;
        }

        return $redacted;
    }

    /** @param array<string, mixed> $filters */
    private function scenarioKey(array $filters): ?string
    {
        foreach (['scenario', 'demo'] as $key) {
            $value = $filters[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $this->scenarios->canonicalKey($value);
            }
        }

        return null;
    }

    /** @return list<int> */
    private function visibleUnitIds(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return $user->units()
            ->pluck('prod.units.unit_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function taskPatientRefs(string $roleId): array
    {
        $query = match ($roleId) {
            'transport' => TransportRequest::query()
                ->where('is_deleted', false)
                ->whereNotIn('status', ['completed', 'cancelled', 'canceled']),
            'evs' => EvsRequest::query()
                ->where('is_deleted', false)
                ->whereNotIn('status', ['completed', 'cancelled', 'canceled']),
            default => null,
        };

        if ($query === null) {
            return [];
        }

        return $query
            ->whereNotNull('patient_ref')
            ->pluck('patient_ref')
            ->map(fn ($ref): string => (string) $ref)
            ->unique()
            ->values()
            ->all();
    }
}
