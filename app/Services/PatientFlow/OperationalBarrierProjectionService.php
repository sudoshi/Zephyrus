<?php

namespace App\Services\PatientFlow;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OperationalBarrierProjectionService
{
    private const TRANSPORT_TERMINAL_STATUSES = ['completed', 'canceled', 'failed'];

    public function __construct(private readonly BarrierTaxonomyService $taxonomy) {}

    /**
     * @return array{
     *     records: list<array<string, mixed>>,
     *     sources: list<array<string, mixed>>
     * }
     */
    public function collect(CarbonImmutable $asOf): array
    {
        if (! config('patient_flow.operational_barriers.enabled', true)) {
            return [
                'records' => [],
                'sources' => [
                    $this->sourceStatus('prod.barriers', false, 'disabled', 0, null),
                    $this->sourceStatus('prod.transport_requests', false, 'disabled', 0, null),
                ],
            ];
        }

        [$barriers, $barrierSource] = $this->barrierRecords($asOf);
        [$transport, $transportSource] = $this->transportRecords($asOf);

        return [
            'records' => [...$barriers, ...$transport],
            'sources' => [$barrierSource, $transportSource],
        ];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function barrierRecords(CarbonImmutable $asOf): array
    {
        if (! Schema::hasTable('prod.barriers')) {
            return [[], $this->sourceStatus('prod.barriers', false, 'missing', 0, null)];
        }

        $hasProdEncounters = Schema::hasTable('prod.encounters');
        $hasFlowEncounters = Schema::hasTable('flow_core.encounters')
            && Schema::hasColumn('flow_core.encounters', 'prod_encounter_id');

        try {
            $query = DB::table('prod.barriers as barriers')
                ->where('barriers.is_deleted', false)
                ->where('barriers.opened_at', '<=', $asOf)
                ->where(function ($query) use ($asOf): void {
                    $query->where('barriers.status', 'open')
                        ->orWhere(function ($resolved) use ($asOf): void {
                            $resolved->where('barriers.status', 'resolved')
                                ->where('barriers.resolved_at', '>', $asOf);
                        });
                })
                ->select([
                    'barriers.barrier_id',
                    'barriers.encounter_id',
                    'barriers.unit_id as barrier_unit_id',
                    'barriers.category',
                    'barriers.reason_code',
                    'barriers.description',
                    'barriers.owner',
                    'barriers.status',
                    'barriers.opened_at',
                    'barriers.resolved_at',
                    'barriers.updated_at',
                ]);

            if ($hasProdEncounters) {
                $query->leftJoin('prod.encounters as encounters', 'encounters.encounter_id', '=', 'barriers.encounter_id')
                    ->addSelect([
                        'encounters.patient_ref as prod_patient_ref',
                        'encounters.unit_id as encounter_unit_id',
                    ]);
            } else {
                $query->addSelect([
                    DB::raw('NULL as prod_patient_ref'),
                    DB::raw('NULL as encounter_unit_id'),
                ]);
            }

            if ($hasFlowEncounters) {
                $query->leftJoin('flow_core.encounters as flow_encounters', 'flow_encounters.prod_encounter_id', '=', 'barriers.encounter_id')
                    ->addSelect([
                        'flow_encounters.encounter_ref as flow_encounter_ref',
                        'flow_encounters.patient_ref as flow_patient_ref',
                    ]);
            } else {
                $query->addSelect([
                    DB::raw('NULL as flow_encounter_ref'),
                    DB::raw('NULL as flow_patient_ref'),
                ]);
            }

            $rows = $query->orderBy('barriers.barrier_id')->get();
            $unitCodes = $this->unitCodes($rows->map(
                fn (object $row): ?int => $this->integerOrNull($row->barrier_unit_id ?? null)
                    ?? $this->integerOrNull($row->encounter_unit_id ?? null),
            ));

            $records = $rows
                ->unique('barrier_id')
                ->map(function (object $row) use ($unitCodes): array {
                    $unitId = $this->integerOrNull($row->barrier_unit_id ?? null)
                        ?? $this->integerOrNull($row->encounter_unit_id ?? null);
                    $category = $this->normalizedCategory((string) $row->category);
                    $barrierCode = $this->canonicalBarrierCode($category, $row->reason_code ?? null);
                    $openedAt = CarbonImmutable::parse((string) $row->opened_at);

                    return [
                        'record_type' => 'rtdc_barrier',
                        'source_table' => 'prod.barriers',
                        'source_record_id' => (string) $row->barrier_id,
                        'barrier_code' => $barrierCode,
                        'source_reason_code' => $this->nullableString($row->reason_code ?? null),
                        'category' => $category,
                        'description' => $this->nullableString($row->description ?? null),
                        'owner' => $this->nullableString($row->owner ?? null),
                        'status' => 'delayed',
                        'opened_at' => $openedAt->toJSON(),
                        'due_at' => null,
                        'minutes_remaining' => null,
                        'prod_encounter_id' => $this->integerOrNull($row->encounter_id ?? null),
                        'flow_encounter_ref' => $this->nullableString($row->flow_encounter_ref ?? null),
                        'flow_patient_ref' => $this->nullableString($row->flow_patient_ref ?? null),
                        'patient_ref' => $this->nullableString($row->prod_patient_ref ?? null),
                        'unit_id' => $unitId,
                        'unit_code' => $unitId ? ($unitCodes[$unitId] ?? null) : null,
                        'encounter_specific' => $row->encounter_id !== null,
                        'verification' => [
                            'status' => 'verified',
                            'assertion' => 'open_as_of',
                            'source_status' => (string) $row->status,
                            'asserted_at' => $openedAt->toJSON(),
                            'observed_at' => $row->updated_at
                                ? CarbonImmutable::parse((string) $row->updated_at)->toJSON()
                                : $openedAt->toJSON(),
                        ],
                    ];
                })
                ->values()
                ->all();

            return [
                $records,
                $this->sourceStatus(
                    'prod.barriers',
                    true,
                    'available',
                    count($records),
                    $this->latestObservedAt($records),
                ),
            ];
        } catch (QueryException) {
            return [[], $this->sourceStatus('prod.barriers', false, 'query_error', 0, null)];
        }
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function transportRecords(CarbonImmutable $asOf): array
    {
        if (! Schema::hasTable('prod.transport_requests')) {
            return [[], $this->sourceStatus('prod.transport_requests', false, 'missing', 0, null)];
        }

        try {
            $rows = DB::table('prod.transport_requests')
                ->where('is_deleted', false)
                ->where('requested_at', '<=', $asOf)
                ->whereNotNull('needed_at')
                ->where('needed_at', '<', $asOf)
                ->whereNotIn('status', self::TRANSPORT_TERMINAL_STATUSES)
                ->orderBy('transport_request_id')
                ->get([
                    'transport_request_id',
                    'request_type',
                    'priority',
                    'status',
                    'patient_ref',
                    'encounter_ref',
                    'origin',
                    'destination',
                    'needed_at',
                    'requested_at',
                    'assigned_team',
                    'assigned_vendor',
                    'updated_at',
                ]);

            $records = $rows->map(function (object $row) use ($asOf): array {
                $neededAt = CarbonImmutable::parse((string) $row->needed_at);
                $overdueMinutes = max(1, (int) round($neededAt->diffInSeconds($asOf, false))) / 60;
                $barrierCode = match ((string) $row->status) {
                    'patient_not_ready' => 'transport_patient_not_ready',
                    'escalated' => 'transport_request_escalated',
                    default => 'transport_request_overdue',
                };

                return [
                    'record_type' => 'transport_delay',
                    'source_table' => 'prod.transport_requests',
                    'source_record_id' => (string) $row->transport_request_id,
                    'barrier_code' => $barrierCode,
                    'category' => 'transport',
                    'status' => 'delayed',
                    'transport_status' => (string) $row->status,
                    'request_type' => (string) $row->request_type,
                    'priority' => (string) $row->priority,
                    'patient_ref' => $this->nullableString($row->patient_ref ?? null),
                    'encounter_ref' => $this->nullableString($row->encounter_ref ?? null),
                    'origin' => $this->nullableString($row->origin ?? null),
                    'destination' => $this->nullableString($row->destination ?? null),
                    'assigned_team' => $this->nullableString($row->assigned_team ?? null),
                    'assigned_vendor' => $this->nullableString($row->assigned_vendor ?? null),
                    'opened_at' => CarbonImmutable::parse((string) $row->requested_at)->toJSON(),
                    'due_at' => $neededAt->toJSON(),
                    'minutes_remaining' => $overdueMinutes * -1,
                    'encounter_specific' => true,
                    'verification' => [
                        'status' => 'verified',
                        'assertion' => 'active_and_overdue_as_of',
                        'source_status' => (string) $row->status,
                        'asserted_at' => $neededAt->toJSON(),
                        'observed_at' => $row->updated_at
                            ? CarbonImmutable::parse((string) $row->updated_at)->toJSON()
                            : $neededAt->toJSON(),
                    ],
                ];
            })->values()->all();

            return [
                $records,
                $this->sourceStatus(
                    'prod.transport_requests',
                    true,
                    'available',
                    count($records),
                    $this->latestObservedAt($records),
                ),
            ];
        } catch (QueryException) {
            return [[], $this->sourceStatus('prod.transport_requests', false, 'query_error', 0, null)];
        }
    }

    /** @param Collection<int, int|null> $unitIds */
    private function unitCodes(Collection $unitIds): array
    {
        if (! Schema::hasTable('prod.units')) {
            return [];
        }

        $ids = $unitIds->filter()->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        try {
            return DB::table('prod.units')
                ->whereIn('unit_id', $ids)
                ->pluck('abbreviation', 'unit_id')
                ->map(fn (mixed $value): ?string => $this->nullableString($value))
                ->filter()
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    private function canonicalBarrierCode(string $category, mixed $sourceReasonCode): string
    {
        $sourceCode = $this->normalizeCode($sourceReasonCode);
        if ($sourceCode && ($this->taxonomy->definition($sourceCode)['known'] ?? false)) {
            return $sourceCode;
        }

        return "rtdc_{$category}_barrier";
    }

    private function normalizedCategory(string $category): string
    {
        return in_array($category, ['medical', 'logistical', 'placement', 'social'], true)
            ? $category
            : 'logistical';
    }

    private function normalizeCode(mixed $value): ?string
    {
        $value = $this->nullableString($value);
        if (! $value) {
            return null;
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?: '';

        return trim($value, '_') ?: null;
    }

    /** @param list<array<string, mixed>> $records */
    private function latestObservedAt(array $records): ?string
    {
        return collect($records)
            ->pluck('verification.observed_at')
            ->filter()
            ->sortDesc()
            ->first();
    }

    /** @return array<string, mixed> */
    private function sourceStatus(
        string $sourceTable,
        bool $available,
        string $status,
        int $records,
        ?string $lastObservedAt,
    ): array {
        return [
            'source_table' => $sourceTable,
            'available' => $available,
            'status' => $status,
            'verified_records' => $records,
            'last_observed_at' => $lastObservedAt,
        ];
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
