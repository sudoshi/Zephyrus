<?php

namespace App\Integrations\Healthcare\Services;

use App\Jobs\PollEpicFhirResource;
use App\Jobs\ReplayPendingIntegrationEvents;
use App\Jobs\RunIntegrationProtocolHealthCheck;
use App\Models\Ops\Approval;
use App\Models\Ops\OperationalAction;
use App\Models\Ops\Recommendation;
use App\Support\Api\JsonMap;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnterpriseConnectorControlService
{
    public function __construct(private readonly IntegrationConfigurationAuditService $audit) {}

    /** @return array<string,mixed> */
    public function summary(): array
    {
        return [
            'generatedAtIso' => now()->toIso8601String(),
            'counts' => [
                'interfaceEngines' => (int) DB::table('integration.interface_engines')->count(),
                'fhirConnections' => (int) DB::table('integration.fhir_client_connections')->count(),
                'smartCredentials' => (int) DB::table('integration.smart_backend_credentials')->count(),
                'connectorPlaybooks' => (int) DB::table('integration.connector_playbooks')->count(),
                'coexistenceAdapters' => (int) DB::table('integration.coexistence_adapters')->count(),
                'writebackDrafts' => (int) DB::table('ops.writeback_drafts')->count(),
            ],
            'playbooks' => DB::table('integration.connector_playbooks')->orderBy('vendor_key')->get()->map(fn ($row): array => [
                'vendorKey' => $row->vendor_key,
                'label' => $row->label,
                'systemClass' => $row->system_class,
                'status' => $this->templateSafeStatus((string) $row->status),
                'capabilities' => JsonMap::from(json_decode($row->capability_payload ?? '{}', true) ?: []),
                'implementationSteps' => json_decode($row->implementation_steps ?? '[]', true) ?: [],
            ])->all(),
            'coexistenceAdapters' => DB::table('integration.coexistence_adapters')->orderBy('adapter_key')->get()->map(fn ($row): array => [
                'adapterKey' => $row->adapter_key,
                'label' => $row->label,
                'vendorKey' => $row->vendor_key,
                'status' => $this->templateSafeStatus((string) $row->status),
                'coexistence' => JsonMap::from(json_decode($row->coexistence_payload ?? '{}', true) ?: []),
            ])->all(),
        ];
    }

    /** @param array<string,mixed> $payload */
    public function createWritebackDraft(array $payload, ?int $userId): array
    {
        $resourceType = (string) $payload['resource_type'];
        $targetSystem = (string) ($payload['target_system'] ?? 'fhir');
        $draftType = (string) ($payload['draft_type'] ?? 'fhir_writeback');

        $recommendation = Recommendation::create([
            'recommendation_uuid' => (string) Str::uuid(),
            'recommendation_type' => 'writeback_draft',
            'scope_type' => 'integration',
            'scope_key' => $targetSystem,
            'title' => "Approve {$resourceType} writeback draft",
            'rationale' => 'External-system writeback must be reviewed before transmission.',
            'confidence' => 0.8000,
            'risk_level' => 'medium',
            'status' => 'draft',
            'expected_impact' => [
                'resource_type' => $resourceType,
                'target_system' => $targetSystem,
            ],
            'evidence' => [
                'source_tables' => ['ops.writeback_drafts', 'ops.actions', 'ops.approvals'],
                'approval_required' => true,
            ],
            'created_by_source' => 'enterprise_connector_control',
        ]);

        $action = OperationalAction::create([
            'action_uuid' => (string) Str::uuid(),
            'recommendation_id' => $recommendation->recommendation_id,
            'action_type' => 'approve_writeback_draft',
            'status' => 'draft',
            'owner_name' => 'Integration governance',
            'payload' => [
                'owner' => 'Integration governance',
                'route' => '/integrations',
                'instruction' => "Review {$resourceType} {$targetSystem} writeback draft before sending.",
                'resourceType' => $resourceType,
                'targetSystem' => $targetSystem,
            ],
            'expires_at' => now()->addHours(12),
        ]);

        $approval = Approval::create([
            'approval_uuid' => (string) Str::uuid(),
            'action_id' => $action->action_id,
            'status' => 'pending',
            'requested_by_user_id' => $userId,
            'reason' => 'External-system writeback requires explicit human approval.',
            'requested_at' => now(),
        ]);

        $sourceId = null;
        if (! empty($payload['source_key'])) {
            $sourceId = $this->sourceFor((string) $payload['source_key'], (string) ($payload['vendor'] ?? $targetSystem))->source_id;
        }

        $draftId = DB::table('ops.writeback_drafts')->insertGetId([
            'writeback_draft_uuid' => (string) Str::uuid(),
            'source_id' => $sourceId,
            'action_id' => $action->action_id,
            'approval_id' => $approval->approval_id,
            'target_system' => $targetSystem,
            'resource_type' => $resourceType,
            'draft_type' => $draftType,
            'status' => 'pending_approval',
            'resource_payload' => json_encode($payload['resource_payload'] ?? []),
            'routing_payload' => json_encode([
                'delivery_mode' => 'draft_only',
                'approval_required' => true,
            ]),
            'created_by_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'writeback_draft_id');

        return [
            'writebackDraftId' => $draftId,
            'resourceType' => $resourceType,
            'targetSystem' => $targetSystem,
            'status' => 'pending_approval',
            'actionId' => $action->action_id,
            'approvalId' => $approval->approval_id,
            'approvalStatus' => $approval->status,
        ];
    }

    /** @return array<string, mixed> */
    public function queueHealthCheck(int $sourceId, ?int $userId, string $correlationId): array
    {
        return DB::transaction(function () use ($sourceId, $userId, $correlationId): array {
            $source = DB::table('integration.sources')->where('source_id', $sourceId)->lockForUpdate()->first();
            abort_unless($source, 404);
            $interface = strtolower((string) $source->interface_type);
            if (! str_contains($interface, 'fhir') && ! str_contains($interface, 'hl7')) {
                abort(422, 'Protocol health checks support FHIR and HL7 v2 sources.');
            }

            $existing = DB::table('raw.ingest_runs')
                ->where('source_id', $sourceId)
                ->where('run_type', 'protocol_health')
                ->whereIn('status', ['queued', 'running', 'retrying'])
                ->where('created_at', '>=', now()->subMinutes(10))
                ->orderByDesc('ingest_run_id')
                ->first();
            if ($existing) {
                return $this->healthRunPayload($existing, false);
            }

            $runUuid = (string) Str::uuid();
            $runId = DB::table('raw.ingest_runs')->insertGetId([
                'run_uuid' => $runUuid,
                'source_id' => $sourceId,
                'connector_key' => 'integration.protocol-health',
                'run_type' => 'protocol_health',
                'status' => 'queued',
                'started_at' => now(),
                'metadata' => json_encode([
                    'protocol' => $interface,
                    'correlation_id' => $correlationId,
                    'requested_by_user_id' => $userId,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ], 'ingest_run_id');

            $this->audit->record($userId, 'queued', 'protocol_health_check', (int) $runId, $source->source_key, [], [
                'runUuid' => $runUuid,
                'sourceId' => $sourceId,
                'status' => 'queued',
            ], $correlationId);
            RunIntegrationProtocolHealthCheck::dispatch((int) $runId, $userId, $correlationId)->afterCommit();

            return $this->healthRunPayload(DB::table('raw.ingest_runs')->where('ingest_run_id', $runId)->first(), true);
        });
    }

    /** @return array<string, mixed> */
    public function queueFhirPoll(int $sourceId, string $resourceType, ?int $userId, string $correlationId): array
    {
        if (! in_array($resourceType, ['Encounter', 'Location'], true)) {
            abort(422, 'The FHIR resource type is not enabled for this operational slice.');
        }

        return DB::transaction(function () use ($sourceId, $resourceType, $userId, $correlationId): array {
            $source = DB::table('integration.sources')->where('source_id', $sourceId)->lockForUpdate()->first();
            abort_unless($source, 404);
            if ($source->active_status !== 'active' || ! str_contains(strtolower((string) $source->interface_type), 'fhir')) {
                abort(422, 'The FHIR source must be active before polling.');
            }
            $connectionReady = DB::table('integration.fhir_client_connections')
                ->where('source_id', $sourceId)->where('health_status', 'healthy')->exists();
            $credentialReady = DB::table('integration.smart_backend_credentials')
                ->where('source_id', $sourceId)->whereIn('status', ['ready', 'active'])->exists();
            if (! $connectionReady || ! $credentialReady) {
                abort(422, 'Live discovery and resolved SMART credentials are required before polling.');
            }

            $connectorKey = 'epic.fhir-r4.'.strtolower($resourceType);
            $existing = DB::table('raw.ingest_runs')
                ->where('source_id', $sourceId)
                ->where('connector_key', $connectorKey)
                ->where('run_type', 'fhir_poll')
                ->whereIn('status', ['queued', 'running', 'retrying'])
                ->where('created_at', '>=', now()->subMinutes(30))
                ->orderByDesc('ingest_run_id')->first();
            if ($existing) {
                return $this->fhirRunPayload($existing, $resourceType, false);
            }

            $runUuid = (string) Str::uuid();
            $runId = DB::table('raw.ingest_runs')->insertGetId([
                'run_uuid' => $runUuid,
                'source_id' => $sourceId,
                'connector_key' => $connectorKey,
                'run_type' => 'fhir_poll',
                'status' => 'queued',
                'started_at' => now(),
                'metadata' => json_encode([
                    'resource_type' => $resourceType,
                    'correlation_id' => $correlationId,
                    'requested_by_user_id' => $userId,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ], 'ingest_run_id');
            $this->audit->record($userId, 'queued', 'fhir_poll', (int) $runId, $source->source_key, [], [
                'runUuid' => $runUuid,
                'sourceId' => $sourceId,
                'resourceType' => $resourceType,
                'status' => 'queued',
            ], $correlationId);
            PollEpicFhirResource::dispatch((int) $runId, $resourceType, $userId, $correlationId)->afterCommit();

            return $this->fhirRunPayload(DB::table('raw.ingest_runs')->where('ingest_run_id', $runId)->first(), $resourceType, true);
        });
    }

    /** @param array<string, mixed> $scope @return array<string, mixed> */
    public function previewReplay(array $scope): array
    {
        $normalized = $this->normalizedReplayScope($scope);
        $query = $this->replayQuery($normalized);
        $total = (clone $query)->count();
        $bounds = (clone $query)->selectRaw('min(occurred_at) as oldest, max(occurred_at) as newest')->first();
        $byType = (clone $query)->selectRaw('event_type, count(*) as aggregate')
            ->groupBy('event_type')->orderBy('event_type')->get()
            ->map(fn (object $row): array => ['eventType' => $row->event_type, 'count' => (int) $row->aggregate])->all();

        return [
            'eligibleEvents' => min($total, $normalized['limit']),
            'totalMatchingEvents' => $total,
            'truncated' => $total > $normalized['limit'],
            'oldestAtIso' => $bounds?->oldest ? CarbonImmutable::parse($bounds->oldest)->toIso8601String() : null,
            'newestAtIso' => $bounds?->newest ? CarbonImmutable::parse($bounds->newest)->toIso8601String() : null,
            'byEventType' => $byType,
            'scope' => $normalized,
            'mutation' => false,
        ];
    }

    /** @param array<string, mixed> $scope @return array<string, mixed> */
    public function queueReplay(array $scope, string $idempotencyKey, ?int $userId, string $correlationId): array
    {
        $normalized = $this->normalizedReplayScope($scope);
        $requestHash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($normalized, $idempotencyKey, $requestHash, $userId, $correlationId): array {
            $existing = DB::table('integration.event_replay_jobs')->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                    abort(409, 'The idempotency key was already used with a different replay scope.');
                }

                return $this->replayRunPayload($existing, false);
            }

            $replayUuid = (string) Str::uuid();
            $replayId = DB::table('integration.event_replay_jobs')->insertGetId([
                'replay_uuid' => $replayUuid,
                'replay_type' => 'pending_canonical_projection',
                'status' => 'queued',
                'scope' => json_encode($normalized, JSON_THROW_ON_ERROR),
                'source_id' => $normalized['sourceId'],
                'requested_by_user_id' => $userId,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'dry_run' => false,
                'metadata' => json_encode(['correlation_id' => $correlationId], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ], 'event_replay_job_id');
            $this->audit->record($userId, 'queued', 'canonical_event_replay', (int) $replayId, $replayUuid, [], [
                'replayUuid' => $replayUuid,
                'scope' => $normalized,
                'status' => 'queued',
            ], $correlationId);
            ReplayPendingIntegrationEvents::dispatch((int) $replayId, $userId, $correlationId)->afterCommit();

            return $this->replayRunPayload(DB::table('integration.event_replay_jobs')->where('event_replay_job_id', $replayId)->first(), true);
        });
    }

    private function sourceFor(string $sourceKey, string $vendor): object
    {
        DB::table('integration.sources')->updateOrInsert(
            ['source_key' => $sourceKey],
            [
                'source_uuid' => (string) Str::uuid(),
                'tenant_key' => 'default',
                'facility_key' => 'main',
                'source_name' => "{$vendor} FHIR Sandbox",
                'vendor' => $vendor,
                'system_class' => 'ehr',
                'environment' => 'sandbox',
                'base_url' => "https://example.invalid/{$sourceKey}",
                'interface_type' => 'fhir_r4',
                'active_status' => 'planned',
                'fhir_version' => '4.0.1',
                'smart_supported' => true,
                'bulk_supported' => true,
                'subscriptions_supported' => true,
                'contract_status' => 'planning',
                'baa_status' => 'planning',
                'phi_allowed' => false,
                'go_live_status' => 'not_started',
                'metadata' => json_encode(['managed_by' => 'enterprise_connector_control']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return DB::table('integration.sources')->where('source_key', $sourceKey)->first();
    }

    private function templateSafeStatus(string $status): string
    {
        return $status;
    }

    /** @return array<string, mixed> */
    private function healthRunPayload(object $run, bool $created): array
    {
        return [
            'runId' => (int) $run->ingest_run_id,
            'runUuid' => $run->run_uuid,
            'status' => $run->status,
            'created' => $created,
        ];
    }

    /** @return array<string, mixed> */
    private function fhirRunPayload(object $run, string $resourceType, bool $created): array
    {
        return array_merge($this->healthRunPayload($run, $created), ['resourceType' => $resourceType]);
    }

    /** @param array<string, mixed> $scope @return array<string, mixed> */
    private function normalizedReplayScope(array $scope): array
    {
        $from = CarbonImmutable::parse((string) $scope['from']);
        $to = CarbonImmutable::parse((string) $scope['to']);
        if ($to->lessThan($from) || $from->diffInDays($to) > 7) {
            abort(422, 'Replay windows must be ordered and no longer than seven days.');
        }
        $supported = ['EncounterStarted', 'EncounterTransferred', 'EncounterDischarged', 'BedStatusChanged', 'AcuityChanged'];
        $requested = array_values(array_unique(array_map('strval', $scope['event_types'] ?? $supported)));
        if (array_diff($requested, $supported) !== []) {
            abort(422, 'The replay includes an unsupported canonical event type.');
        }
        sort($requested);

        return [
            'sourceId' => isset($scope['source_id']) ? (int) $scope['source_id'] : null,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'eventTypes' => $requested,
            'projectionStatuses' => ['pending', 'failed'],
            'limit' => min(1000, max(1, (int) ($scope['limit'] ?? 500))),
        ];
    }

    /** @param array<string, mixed> $scope */
    private function replayQuery(array $scope): \Illuminate\Database\Query\Builder
    {
        return DB::table('integration.canonical_events')
            ->when($scope['sourceId'], fn ($query, $sourceId) => $query->where('source_id', $sourceId))
            ->whereBetween('occurred_at', [$scope['from'], $scope['to']])
            ->whereIn('event_type', $scope['eventTypes'])
            ->whereIn('projection_status', $scope['projectionStatuses']);
    }

    /** @return array<string, mixed> */
    private function replayRunPayload(object $row, bool $created): array
    {
        return [
            'replayJobId' => (int) $row->event_replay_job_id,
            'replayUuid' => $row->replay_uuid,
            'status' => $row->status,
            'created' => $created,
        ];
    }
}
