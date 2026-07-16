<?php

namespace App\Services\Admin;

use App\Authorization\AdminScope;
use App\Authorization\GovernedAction;
use App\Security\ClinicalPayloads\ClinicalPayloadLifecycleService;
use App\Security\ClinicalPayloads\ClinicalPayloadStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ClinicalPayloadProtectionService
{
    private const COVERAGE_TARGETS = [
        ['label' => 'Raw inbound bodies', 'table' => 'raw.inbound_messages', 'column' => 'payload', 'pointer' => 'payload_object_id', 'empty_object_is_clear' => false],
        ['label' => 'Normalized inbound bodies', 'table' => 'raw.inbound_messages', 'column' => 'normalized_payload', 'pointer' => 'normalized_payload_object_id', 'empty_object_is_clear' => false],
        ['label' => 'FHIR resource versions', 'table' => 'fhir.resource_versions', 'column' => 'resource_data', 'pointer' => 'payload_object_id', 'empty_object_is_clear' => true],
        ['label' => 'Canonical replay events', 'table' => 'integration.canonical_events', 'column' => 'payload', 'pointer' => 'payload_object_id', 'empty_object_is_clear' => true],
        ['label' => 'Outbound writeback drafts', 'table' => 'ops.writeback_drafts', 'column' => 'resource_payload', 'pointer' => 'payload_object_id', 'empty_object_is_clear' => true],
    ];

    public function __construct(
        private readonly ClinicalPayloadStore $payloads,
        private readonly ClinicalPayloadLifecycleService $lifecycle,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(?AdminScope $scope = null): array
    {
        $readiness = $this->payloads->readiness();
        $coverage = collect(self::COVERAGE_TARGETS)->map(function (array $target) use ($scope): array {
            $protectedQuery = $this->scoped(DB::table($target['table']), $scope)
                ->whereNotNull($target['pointer']);
            $legacyQuery = $this->scoped(DB::table($target['table']), $scope)
                ->whereNull($target['pointer'])
                ->whereNotNull($target['column']);
            if ($target['empty_object_is_clear']) {
                $legacyQuery->whereRaw($target['column']." <> '{}'::jsonb");
            }
            $protected = $protectedQuery->count();
            $legacy = $legacyQuery->count();
            $eligible = $protected + $legacy;

            return [
                'label' => $target['label'],
                'protected' => $protected,
                'legacy' => $legacy,
                'eligible' => $eligible,
                'coveragePercent' => $eligible === 0 ? 100 : (int) floor(($protected / $eligible) * 100),
            ];
        })->all();
        $protected = array_sum(array_column($coverage, 'protected'));
        $legacy = array_sum(array_column($coverage, 'legacy'));
        $eligible = $protected + $legacy;

        $objects = $this->scoped(DB::table('raw.payload_objects'), $scope);
        $quarantines = $this->scoped(DB::table('raw.payload_quarantines'), $scope);
        $backfillItems = $this->scoped(DB::table('raw.payload_backfill_items'), $scope);
        $deletionFailures = $this->scoped(
            DB::table('raw.payload_object_events as event')
                ->join('raw.payload_objects as object', 'object.payload_object_id', '=', 'event.payload_object_id'),
            $scope,
            'object.source_id',
        )->where('event.event_type', 'deletion_failed')->count();
        $lastVerifiedAt = $this->scoped(
            DB::table('raw.payload_object_events as event')
                ->join('raw.payload_objects as object', 'object.payload_object_id', '=', 'event.payload_object_id'),
            $scope,
            'object.source_id',
        )->whereIn('event.event_type', ['verified', 'integrity_recovered'])->max('event.occurred_at');
        $partitionedTables = collect([
            'raw.inbound_messages',
            'integration.canonical_events',
            'integration.provenance_records',
            'audit.user_events',
            'raw.payload_object_events',
        ])->filter(fn (string $table): bool => DB::table('pg_partitioned_table as partitioned')
            ->join('pg_class as relation', 'relation.oid', '=', 'partitioned.partrelid')
            ->join('pg_namespace as namespace', 'namespace.oid', '=', 'relation.relnamespace')
            ->whereRaw("namespace.nspname || '.' || relation.relname = ?", [$table])
            ->exists())->values()->all();

        return [
            'generatedAt' => now()->toIso8601String(),
            'scope' => $this->scopeContract($scope),
            'provider' => [
                'status' => $readiness['status'],
                'errorCode' => $readiness['errorCode'],
                'disk' => $readiness['disk'] ?? null,
                'driver' => $readiness['driver'] ?? null,
                'cipher' => $readiness['cipher'] ?? null,
                'compression' => $readiness['compression'] ?? null,
                'keyProviderScheme' => $readiness['keyProviderScheme'] ?? null,
                'keyProviderVersion' => $readiness['keyProviderVersion'] ?? null,
                'keyReferenceConfigured' => $readiness['keyReferenceConfigured'] ?? false,
                'providerReachable' => $readiness['providerReachable'] ?? false,
            ],
            'coverage' => [
                'protected' => $protected,
                'legacy' => $legacy,
                'eligible' => $eligible,
                'coveragePercent' => $eligible === 0 ? 100 : (int) floor(($protected / $eligible) * 100),
                'targets' => $coverage,
            ],
            'objects' => [
                'total' => (clone $objects)->count(),
                'ready' => (clone $objects)->where('status', 'ready')->count(),
                'integrityFailed' => (clone $objects)->where('status', 'integrity_failed')->count(),
                'legalHolds' => (clone $objects)->where('legal_hold', true)->count(),
                'items' => $this->objectItems($scope),
            ],
            'backfill' => [
                'pending' => (clone $backfillItems)->where('status', 'pending')->count(),
                'failed' => (clone $backfillItems)->where('status', 'failed')->count(),
                'mismatched' => (clone $backfillItems)->where('status', 'mismatch')->count(),
                'latestRuns' => $this->latestRuns($scope),
            ],
            'quarantine' => [
                'open' => (clone $quarantines)->where('status', 'open')->count(),
                'oldestOpenedAt' => (clone $quarantines)->where('status', 'open')->min('opened_at'),
                'byCategory' => (clone $quarantines)->where('status', 'open')
                    ->select('reason_category', DB::raw('count(*) as aggregate'))
                    ->groupBy('reason_category')->orderBy('reason_category')
                    ->pluck('aggregate', 'reason_category')->map(fn ($count): int => (int) $count)->all(),
                'items' => $this->quarantineItems($scope),
            ],
            'retention' => [
                'backlog' => (clone $objects)->whereIn('status', ['ready', 'retention_pending'])
                    ->where('legal_hold', false)->where('retain_until', '<=', now())->count(),
                'pendingDeletion' => (clone $objects)->whereIn('status', ['retention_pending', 'deletion_pending'])->count(),
                'deletionFailures' => $deletionFailures,
                'deletedTombstones' => (clone $objects)->where('status', 'deleted')->count(),
            ],
            'integrity' => [
                'lastVerifiedAt' => $lastVerifiedAt,
                'verifiedLast24Hours' => $this->scoped(
                    DB::table('raw.payload_object_events as event')
                        ->join('raw.payload_objects as object', 'object.payload_object_id', '=', 'event.payload_object_id'),
                    $scope,
                    'object.source_id',
                )->whereIn('event.event_type', ['verified', 'integrity_recovered'])
                    ->where('event.occurred_at', '>=', now()->subDay())->count(),
                'failures' => (clone $objects)->where('status', 'integrity_failed')->count(),
            ],
            'partitioning' => [
                'status' => count($partitionedTables) === 5 ? 'ready' : 'blocked',
                'partitionedCount' => count($partitionedTables),
                'requiredCount' => 5,
                'partitionedTables' => $partitionedTables,
                'remediation' => count($partitionedTables) === 5
                    ? null
                    : 'Approve and execute the online tenant/time partition migration after volume and retention benchmarks are captured.',
            ],
            'governance' => [
                'actionable' => $scope?->sourceId !== null,
                'changes' => $this->governedChanges($scope),
            ],
            'links' => [
                'sourceGovernance' => '/integrations?tab=sources',
                'governedChanges' => '/integrations?tab=audit&governance=pending',
                'systemHealth' => '/admin/system-health',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function objectItems(?AdminScope $scope): array
    {
        if ($scope?->sourceId === null) {
            return [];
        }

        return DB::table('raw.payload_objects')
            ->where('source_id', $scope->sourceId)
            ->where('status', '<>', 'deleted')
            ->orderByRaw("CASE status WHEN 'integrity_failed' THEN 0 WHEN 'quarantined' THEN 1 WHEN 'deletion_pending' THEN 2 ELSE 3 END")
            ->orderByDesc('payload_object_id')
            ->limit(50)
            ->get([
                'payload_object_id', 'payload_uuid', 'payload_kind', 'data_classification',
                'status', 'legal_hold', 'retention_policy_key', 'retain_until',
                'created_at', 'last_verified_at',
            ])->map(fn (object $object): array => [
                'id' => (int) $object->payload_object_id,
                'uuid' => (string) $object->payload_uuid,
                'kind' => (string) $object->payload_kind,
                'classification' => (string) $object->data_classification,
                'status' => (string) $object->status,
                'legalHold' => (bool) $object->legal_hold,
                'retentionPolicy' => (string) $object->retention_policy_key,
                'retainUntil' => $object->retain_until,
                'createdAt' => $object->created_at,
                'lastVerifiedAt' => $object->last_verified_at,
                'deletionBlockers' => $this->lifecycle->deletionBlockers((int) $object->payload_object_id),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function quarantineItems(?AdminScope $scope): array
    {
        if ($scope?->sourceId === null) {
            return [];
        }

        return DB::table('raw.payload_quarantines as quarantine')
            ->join('raw.payload_objects as object', 'object.payload_object_id', '=', 'quarantine.payload_object_id')
            ->where('quarantine.source_id', $scope->sourceId)
            ->where('quarantine.status', 'open')
            ->orderBy('quarantine.opened_at')
            ->limit(50)
            ->get([
                'quarantine.payload_quarantine_id', 'quarantine.quarantine_uuid',
                'quarantine.payload_object_id', 'object.payload_uuid', 'object.status as object_status',
                'object.legal_hold', 'quarantine.reason_category', 'quarantine.reason_code',
                'quarantine.detected_by', 'quarantine.opened_at',
            ])->map(fn (object $quarantine): array => [
                'id' => (int) $quarantine->payload_quarantine_id,
                'uuid' => (string) $quarantine->quarantine_uuid,
                'objectId' => (int) $quarantine->payload_object_id,
                'objectUuid' => (string) $quarantine->payload_uuid,
                'objectStatus' => (string) $quarantine->object_status,
                'legalHold' => (bool) $quarantine->legal_hold,
                'category' => (string) $quarantine->reason_category,
                'reasonCode' => (string) $quarantine->reason_code,
                'detectedBy' => (string) $quarantine->detected_by,
                'openedAt' => $quarantine->opened_at,
                'deletionBlockers' => $this->lifecycle->deletionBlockers((int) $quarantine->payload_object_id),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function governedChanges(?AdminScope $scope): array
    {
        if ($scope?->sourceId === null) {
            return [];
        }
        $actions = [
            GovernedAction::ReleaseQuarantinedPayload->value,
            GovernedAction::ApplyClinicalPayloadHold->value,
            GovernedAction::ReleaseClinicalPayloadHold->value,
            GovernedAction::PurgeClinicalPayload->value,
            GovernedAction::PurgeQuarantinedPayload->value,
            GovernedAction::RecoverClinicalPayloadIntegrity->value,
        ];

        return DB::table('governance.change_requests as request')
            ->leftJoin('governance.change_decisions as decision', 'decision.change_request_uuid', '=', 'request.change_request_uuid')
            ->whereIn('request.action_type', $actions)
            ->whereRaw("request.metadata->>'source_id' = ?", [(string) $scope->sourceId])
            ->select([
                'request.change_request_uuid', 'request.action_type', 'request.subject_type',
                'request.subject_id', 'request.author_user_id', 'request.requested_at',
                'request.expires_at', 'request.metadata', 'decision.decision',
                'decision.decided_by_user_id', 'decision.decided_at',
            ])
            ->selectRaw('(SELECT execution.outcome FROM governance.change_executions execution WHERE execution.change_request_uuid = request.change_request_uuid ORDER BY execution.change_execution_id DESC LIMIT 1) AS last_outcome')
            ->selectRaw("(SELECT MAX(execution.executed_at) FROM governance.change_executions execution WHERE execution.change_request_uuid = request.change_request_uuid AND execution.outcome = 'success') AS executed_at")
            ->orderByDesc('request.requested_at')
            ->limit(50)
            ->get()
            ->map(function (object $change): array {
                $metadata = is_string($change->metadata)
                    ? json_decode($change->metadata, true, 512, JSON_THROW_ON_ERROR)
                    : (array) $change->metadata;
                $status = $change->executed_at !== null
                    ? 'executed'
                    : ($change->decision === 'rejected'
                        ? 'rejected'
                        : (CarbonImmutable::parse((string) $change->expires_at)->isPast()
                            ? 'expired'
                            : ($change->last_outcome === 'failure'
                                ? 'execution_failed'
                                : ($change->decision === 'approved' ? 'approved' : 'pending_approval'))));

                return [
                    'uuid' => (string) $change->change_request_uuid,
                    'action' => (string) $change->action_type,
                    'subjectType' => (string) $change->subject_type,
                    'subjectId' => (string) $change->subject_id,
                    'status' => $status,
                    'operation' => (string) ($metadata['operation'] ?? ''),
                    'objectId' => isset($metadata['object_id']) ? (int) $metadata['object_id'] : null,
                    'quarantineId' => isset($metadata['quarantine_id']) ? (int) $metadata['quarantine_id'] : null,
                    'authorUserId' => (int) $change->author_user_id,
                    'decidedByUserId' => $change->decided_by_user_id === null ? null : (int) $change->decided_by_user_id,
                    'requestedAt' => $change->requested_at,
                    'expiresAt' => $change->expires_at,
                    'decidedAt' => $change->decided_at,
                    'executedAt' => $change->executed_at,
                ];
            })->all();
    }

    /** @return list<array<string, mixed>> */
    private function latestRuns(?AdminScope $scope): array
    {
        return $this->scoped(DB::table('raw.payload_backfill_runs'), $scope)
            ->orderByDesc('payload_backfill_run_id')
            ->limit(8)
            ->get([
                'payload_backfill_run_id', 'run_uuid', 'source_id', 'mode', 'status',
                'scanned_count', 'protected_count', 'skipped_count', 'failed_count',
                'mismatch_count', 'error_code', 'started_at', 'completed_at',
            ])->map(fn (object $run): array => [
                'runId' => (int) $run->payload_backfill_run_id,
                'runUuid' => (string) $run->run_uuid,
                'sourceId' => $run->source_id === null ? null : (int) $run->source_id,
                'mode' => (string) $run->mode,
                'status' => (string) $run->status,
                'scanned' => (int) $run->scanned_count,
                'protected' => (int) $run->protected_count,
                'skipped' => (int) $run->skipped_count,
                'failed' => (int) $run->failed_count,
                'mismatched' => (int) $run->mismatch_count,
                'errorCode' => $run->error_code,
                'startedAt' => $run->started_at,
                'completedAt' => $run->completed_at,
            ])->all();
    }

    private function scoped(Builder $query, ?AdminScope $scope, string $sourceColumn = 'source_id'): Builder
    {
        if ($scope === null) {
            return $query;
        }
        if ($scope->sourceId !== null) {
            return $query->where($sourceColumn, $scope->sourceId);
        }

        return $query->whereIn($sourceColumn, DB::table('integration.sources')
            ->select('source_id')
            ->where('organization_id', $scope->organizationId)
            ->when($scope->facilityId !== null, fn ($sources) => $sources->where('facility_id', $scope->facilityId)));
    }

    /** @return array<string, int|string|null> */
    private function scopeContract(?AdminScope $scope): array
    {
        return [
            'mode' => $scope === null ? 'capability_wide' : ($scope->sourceId !== null ? 'source' : ($scope->facilityId !== null ? 'facility' : 'organization')),
            'organizationId' => $scope?->organizationId,
            'facilityId' => $scope?->facilityId,
            'sourceId' => $scope?->sourceId,
            'label' => $scope?->sourceName ?? $scope?->facilityName ?? $scope?->organizationName ?? 'All authorized integration sources',
        ];
    }
}
