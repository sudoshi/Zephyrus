<?php

namespace App\Services\PatientFlow;

use App\Models\Bed;
use App\Models\PatientFlow\OccupancySnapshot;
use App\Models\Unit;
use App\Models\User;
use App\Services\Flow\FlowLensService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

class PatientFlowOccupancyHistoryService
{
    public function __construct(
        private readonly PatientFlowScenarioRegistry $scenarios,
        private readonly FlowLensService $flowLens,
    ) {}

    /**
     * @param  array<string, mixed>  $lens
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function history(
        array $lens,
        string $roleId,
        array $filters,
        ?User $user = null,
        ?array $scope = null,
        ?string $effectiveDepth = null,
        array $taskPatientRefs = [],
        array $visibleUnitIds = [],
    ): array {
        $to = $this->time('to', $filters['to'] ?? $filters['asOf'] ?? null, CarbonImmutable::now());
        $from = $this->time('from', $filters['from'] ?? null, $to->subHours(24));
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $limit = max(1, min(500, (int) ($filters['limit'] ?? 120)));
        $scenario = $this->scenarioKey($filters);
        $scope ??= ['type' => 'house', 'floor' => null, 'unit_id' => null, 'patient_ref' => null, 'patient_context_ref' => null, 'label' => 'House'];
        $depth = $effectiveDepth ?? (string) ($lens['patient_dots'] ?? 'none');
        $unitIds = $depth === 'unit'
            ? ($visibleUnitIds ?: $this->flowLens->visibleUnitIds($user))
            : [];
        $taskPatientRefs = $depth === 'task'
            ? ($taskPatientRefs ?: $this->flowLens->taskPatientRefs($roleId))
            : [];

        $query = OccupancySnapshot::query()
            ->with('facilitySpace')
            ->whereBetween('snapshot_at', [$from, $to])
            ->orderBy('snapshot_at')
            ->orderBy('facility_space_id')
            ->limit($limit);

        $floor = $scope['type'] === 'floor'
            ? $scope['floor']
            : (($filters['floor'] ?? null) !== null && $filters['floor'] !== '' && $filters['floor'] !== 'all'
                ? (int) $filters['floor']
                : null);
        if ($floor !== null) {
            $query->whereHas('facilitySpace', fn ($space) => $space->where('floor_number', (int) $floor));
        }

        if ($scope['type'] === 'unit') {
            $spaceIds = $this->spaceIdsForUnit((int) $scope['unit_id']);
            $spaceIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('facility_space_id', $spaceIds);
        }

        if (($filters['service_line'] ?? null) !== null && $filters['service_line'] !== '' && $filters['service_line'] !== 'all') {
            $serviceLine = (string) $filters['service_line'];
            $query->whereHas('facilitySpace', fn ($space) => $space->where('service_line_code', $serviceLine));
        }

        $snapshots = $query->get()->map(function (OccupancySnapshot $snapshot) use ($depth, $scenario, $scope, $taskPatientRefs, $unitIds): array {
            $space = $snapshot->facilitySpace;
            $details = is_array($snapshot->occupancy_details) ? $snapshot->occupancy_details : [];

            $details = array_map(function (mixed $detail) use ($space): mixed {
                if (! is_array($detail)) {
                    return $detail;
                }

                $detail['location_floor'] ??= $space?->floor_number;

                return $detail;
            }, $details);

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
                'occupancy_details' => $this->detailsForDepth($details, $depth, $scope, $unitIds, $taskPatientRefs),
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
            'scope' => [
                'type' => $scope['type'],
                'floor' => $scope['floor'],
                'unit_id' => $scope['unit_id'],
                'patient_context_ref' => $scope['patient_context_ref'] ?? null,
                'label' => $scope['label'] ?? ucfirst((string) $scope['type']),
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
     * @param  array<string, mixed>  $scope
     * @param  list<int>  $unitIds
     * @param  list<string>  $taskPatientRefs
     * @return list<array<string, mixed>>
     */
    private function detailsForDepth(array $details, string $depth, array $scope, array $unitIds, array $taskPatientRefs): array
    {
        $redacted = [];
        foreach ($details as $detail) {
            if (! is_array($detail)) {
                continue;
            }

            $patientRef = $this->patientRefForDetail($detail);
            if ($patientRef === null) {
                continue;
            }

            $detail['_patient_ref'] = $patientRef;
            unset($detail['patient_ref'], $detail['patient_id'], $detail['patient_display_id'], $detail['encounter_id']);

            if (! $this->flowLens->canViewPatientRow($detail, $depth, $scope, $unitIds, $taskPatientRefs)) {
                continue;
            }

            $redacted[] = $this->flowLens->redactRow($detail, $depth, $scope, $taskPatientRefs, $unitIds);
        }

        return $redacted;
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
    private function spaceIdsForUnit(int $unitId): array
    {
        $spaceIds = Bed::query()
            ->where('is_deleted', false)
            ->where('unit_id', $unitId)
            ->whereNotNull('facility_space_id')
            ->pluck('facility_space_id');

        $unitSpaceId = Unit::query()
            ->where('is_deleted', false)
            ->whereKey($unitId)
            ->value('facility_space_id');
        if ($unitSpaceId !== null) {
            $spaceIds->push($unitSpaceId);
        }

        return $spaceIds->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }
}
