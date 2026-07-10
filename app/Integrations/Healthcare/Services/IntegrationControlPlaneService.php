<?php

namespace App\Integrations\Healthcare\Services;

use App\Support\Api\JsonMap;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IntegrationControlPlaneService
{
    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $sourceMetrics = $this->sourceMetrics();
        $sources = DB::table('integration.sources')
            ->orderBy('source_name')
            ->get()
            ->map(fn (object $source): array => $this->sourceSummary($source, $sourceMetrics))
            ->values();

        $counts = [
            'sources' => $sources->count(),
            'activeSources' => $sources->where('configuredStatus', 'active')->count(),
            'healthySources' => $sources->where('healthStatus', 'healthy')->count(),
            'degradedSources' => $sources->whereIn('healthStatus', ['degraded', 'unobserved'])->count(),
            'staleSources' => $sources->where('healthStatus', 'stale')->count(),
            'failedSources' => $sources->where('healthStatus', 'failed')->count(),
            'protocolHealthySources' => $sources->where('protocolHealthStatus', 'healthy')->count(),
            'protocolDegradedSources' => $sources->where('protocolHealthStatus', 'degraded')->count(),
            'protocolFailedSources' => $sources->where('protocolHealthStatus', 'failed')->count(),
            'endpoints' => DB::table('integration.source_endpoints')->count(),
            'capabilities' => DB::table('integration.source_capabilities')->where('supported', true)->count(),
            'credentials' => DB::table('integration.source_credentials')->count()
                + DB::table('integration.smart_backend_credentials')->count(),
            'interfaceEngines' => DB::table('integration.interface_engines')->count(),
            'fhirConnections' => DB::table('integration.fhir_client_connections')->count(),
            'smartCredentials' => DB::table('integration.smart_backend_credentials')->count(),
            'connectorTemplates' => DB::table('integration.connector_playbooks')->count(),
            'coexistenceAdapters' => DB::table('integration.coexistence_adapters')->count(),
            'ingestRuns' => DB::table('raw.ingest_runs')->count(),
            'inboundMessages' => DB::table('raw.inbound_messages')->count(),
            'canonicalEvents' => DB::table('integration.canonical_events')->count(),
            'pendingProjectionEvents' => DB::table('integration.canonical_events')->where('projection_status', 'pending')->count(),
            'openDeadLetters' => DB::table('raw.dead_letters')->where('status', 'open')->count(),
            'openProjectionErrors' => DB::table('integration.event_projection_errors')->where('status', 'open')->count(),
            'replayJobs' => DB::table('integration.event_replay_jobs')->count(),
            'terminologyMaps' => DB::table('integration.terminology_maps')->count(),
            'writebackDrafts' => DB::table('ops.writeback_drafts')->count(),
            'configurationAudits' => DB::table('integration.configuration_audits')->count(),
            'queuedJobs' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0,
            'failedQueueJobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
        ];

        return [
            'generatedAtIso' => now()->toIso8601String(),
            'status' => $this->overallStatus($sources),
            'freshnessPolicy' => [
                'freshAfterMinutes' => max(1, (int) config('integrations.fresh_after_minutes', 15)),
                'staleAfterMinutes' => max(1, (int) config('integrations.stale_after_minutes', 60)),
            ],
            'counts' => $counts,
            'sources' => $sources->all(),
            'endpoints' => $this->endpoints(),
            'fhirConnections' => $this->fhirConnections(),
            'hl7Interfaces' => $this->hl7Interfaces(),
            'connectorFamilies' => $this->connectorFamilies(),
            'mappings' => $this->mappings(),
            'runs' => $this->runs(),
            'watermarks' => $this->watermarks(),
            'deadLetters' => $this->deadLetters(),
            'projectionErrors' => $this->projectionErrors(),
            'replayJobs' => $this->replayJobs(),
            'writebackDrafts' => $this->writebackDrafts(),
            'credentials' => $this->credentials(),
            'templates' => $this->templates(),
            'configurationAudits' => $this->configurationAudits(),
            'audit' => [
                'provenanceRecords' => DB::table('integration.provenance_records')->count(),
                'identityLinks' => DB::table('integration.identity_links')->count(),
                'patientMergeEvents' => DB::table('integration.patient_merge_events')->count(),
                'changeAuditAvailable' => true,
            ],
        ];
    }

    /**
     * @param  array<string, Collection>  $metrics
     * @return array<string, mixed>
     */
    private function sourceSummary(object $source, array $metrics): array
    {
        $sourceId = (int) $source->source_id;
        $latestMessageAt = $metrics['latestMessages']->get($sourceId);
        $latestSuccessAt = $metrics['latestSuccesses']->get($sourceId);
        $latestRun = $metrics['latestRuns']->get($sourceId);
        $lastObserved = $this->latestTimestamp([
            $latestMessageAt,
            $latestSuccessAt,
            $latestRun?->completed_at,
        ]);
        $health = $this->healthStatus(
            (string) $source->active_status,
            $lastObserved,
            $latestRun?->status,
            $latestRun?->started_at,
            $latestSuccessAt,
        );
        $metadata = $this->decodeMap($source->metadata);

        return [
            'sourceId' => $sourceId,
            'sourceKey' => $source->source_key,
            'sourceName' => $source->source_name,
            'vendor' => $source->vendor,
            'systemClass' => $source->system_class,
            'environment' => $source->environment,
            'interfaceType' => $source->interface_type,
            'configuredStatus' => $source->active_status,
            'healthStatus' => $health,
            'protocolHealthStatus' => $source->protocol_health_status,
            'protocolHealthCheckedAtIso' => $this->iso($source->protocol_health_checked_at),
            'protocolHealthErrorCode' => $source->protocol_health_error,
            'goLiveStatus' => $source->go_live_status,
            'contractStatus' => $source->contract_status,
            'baaStatus' => $source->baa_status,
            'phiAllowed' => (bool) $source->phi_allowed,
            'baseUrlConfigured' => filled($source->base_url),
            'baseUrlOrigin' => $this->urlOrigin($source->base_url),
            'owner' => $metadata['owner'] ?? null,
            'expectedCadenceMinutes' => isset($metadata['expected_cadence_minutes']) ? (int) $metadata['expected_cadence_minutes'] : null,
            'lastObservedAtIso' => $this->iso($lastObserved),
            'ageMinutes' => $lastObserved ? max(0, (int) $lastObserved->diffInMinutes(now())) : null,
            'lastRunStatus' => $latestRun?->status,
            'counts' => [
                'endpoints' => $metrics['endpoints']->get($sourceId, 0),
                'capabilities' => $metrics['capabilities']->get($sourceId, 0),
                'runs' => $metrics['runs']->get($sourceId, 0),
                'inboundMessages' => $metrics['inboundMessages']->get($sourceId, 0),
                'canonicalEvents' => $metrics['canonicalEvents']->get($sourceId, 0),
                'openDeadLetters' => $metrics['openDeadLetters']->get($sourceId, 0),
            ],
        ];
    }

    /** @return array<string, Collection> */
    private function sourceMetrics(): array
    {
        $latestRunIds = DB::table('raw.ingest_runs')
            ->selectRaw('source_id, max(ingest_run_id) as ingest_run_id')
            ->whereNot('run_type', 'protocol_health')
            ->groupBy('source_id');

        $latestRuns = DB::table('raw.ingest_runs as run')
            ->joinSub($latestRunIds, 'latest', 'latest.ingest_run_id', '=', 'run.ingest_run_id')
            ->get(['run.source_id', 'run.status', 'run.started_at', 'run.completed_at'])
            ->keyBy('source_id');

        return [
            'latestMessages' => DB::table('raw.inbound_messages')
                ->selectRaw('source_id, max(received_at) as observed_at')
                ->groupBy('source_id')
                ->pluck('observed_at', 'source_id'),
            'latestSuccesses' => DB::table('integration.connector_watermarks')
                ->selectRaw('source_id, max(last_success_at) as observed_at')
                ->groupBy('source_id')
                ->pluck('observed_at', 'source_id'),
            'latestRuns' => $latestRuns,
            'endpoints' => $this->groupedSourceCount('integration.source_endpoints'),
            'capabilities' => $this->groupedSourceCount(
                'integration.source_capabilities',
                fn (Builder $query): Builder => $query->where('supported', true),
            ),
            'runs' => $this->groupedSourceCount('raw.ingest_runs'),
            'inboundMessages' => $this->groupedSourceCount('raw.inbound_messages'),
            'canonicalEvents' => $this->groupedSourceCount('integration.canonical_events'),
            'openDeadLetters' => $this->groupedSourceCount(
                'raw.dead_letters',
                fn (Builder $query): Builder => $query->where('status', 'open'),
            ),
        ];
    }

    /** @param  null|callable(Builder): Builder  $scope */
    private function groupedSourceCount(string $table, ?callable $scope = null): Collection
    {
        $query = DB::table($table)
            ->selectRaw('source_id, count(*) as aggregate')
            ->whereNotNull('source_id');

        if ($scope) {
            $query = $scope($query);
        }

        return $query
            ->groupBy('source_id')
            ->pluck('aggregate', 'source_id')
            ->map(fn (mixed $count): int => (int) $count);
    }

    /** @return list<array<string, mixed>> */
    private function endpoints(): array
    {
        return DB::table('integration.source_endpoints as endpoint')
            ->join('integration.sources as source', 'source.source_id', '=', 'endpoint.source_id')
            ->orderBy('source.source_name')
            ->orderBy('endpoint.endpoint_type')
            ->get([
                'endpoint.source_endpoint_id',
                'endpoint.endpoint_type',
                'endpoint.auth_type',
                'endpoint.tls_mode',
                'endpoint.is_active',
                'endpoint.url',
                'endpoint.source_id',
                'endpoint.metadata',
                'source.source_name',
            ])
            ->map(function (object $row): array {
                $metadata = $this->decodeMap($row->metadata);

                return [
                    'endpointId' => (int) $row->source_endpoint_id,
                    'sourceId' => (int) $row->source_id,
                    'sourceName' => $row->source_name,
                    'endpointType' => $row->endpoint_type,
                    'authType' => $row->auth_type,
                    'tlsMode' => $row->tls_mode,
                    'isActive' => (bool) $row->is_active,
                    'urlConfigured' => filled($row->url),
                    'urlOrigin' => $this->urlOrigin($row->url),
                    'owner' => $metadata['owner'] ?? null,
                    'expectedCadenceMinutes' => isset($metadata['expected_cadence_minutes']) ? (int) $metadata['expected_cadence_minutes'] : null,
                ];
            })->all();
    }

    /** @return list<array<string, mixed>> */
    private function fhirConnections(): array
    {
        $resourceCounts = DB::table('integration.source_capabilities')
            ->selectRaw('source_id, count(distinct resource_type) as aggregate')
            ->where('capability_type', 'fhir_resource')
            ->where('supported', true)
            ->groupBy('source_id')
            ->pluck('aggregate', 'source_id');

        return DB::table('integration.fhir_client_connections as connection')
            ->join('integration.sources as source', 'source.source_id', '=', 'connection.source_id')
            ->orderBy('source.source_name')
            ->get([
                'connection.fhir_client_connection_id',
                'connection.source_id',
                'connection.connection_key',
                'connection.status',
                'connection.fhir_version',
                'connection.capability_checked_at',
                'connection.health_status',
                'connection.health_checked_at',
                'connection.last_health_error',
                'connection.base_url',
                'source.source_name',
            ])
            ->map(fn (object $row): array => [
                'connectionId' => (int) $row->fhir_client_connection_id,
                'sourceId' => (int) $row->source_id,
                'sourceName' => $row->source_name,
                'connectionKey' => $row->connection_key,
                'status' => $row->status,
                'fhirVersion' => $row->fhir_version,
                'capabilityCheckedAtIso' => $this->iso($row->capability_checked_at),
                'healthStatus' => $row->health_status,
                'healthCheckedAtIso' => $this->iso($row->health_checked_at),
                'healthErrorCode' => $row->last_health_error,
                'baseUrlConfigured' => filled($row->base_url),
                'supportedResourceCount' => (int) $resourceCounts->get($row->source_id, 0),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function hl7Interfaces(): array
    {
        return DB::table('integration.interface_engines')
            ->orderBy('label')
            ->get()
            ->map(fn (object $row): array => [
                'interfaceEngineId' => (int) $row->interface_engine_id,
                'engineKey' => $row->engine_key,
                'label' => $row->label,
                'engineType' => $row->engine_type,
                'environment' => $row->environment,
                'status' => $this->templateSafeStatus((string) $row->status),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function connectorFamilies(): array
    {
        return [
            ['priority' => 1, 'family' => 'EHR and registration', 'protocols' => ['HL7 v2 ADT', 'FHIR R4'], 'operationalValue' => 'Identity, census, movement, and merge events'],
            ['priority' => 1, 'family' => 'Bed and patient flow', 'protocols' => ['ADT', 'FHIR Encounter/Location/Task', 'Vendor API'], 'operationalValue' => 'Placement, bed state, and verified barriers'],
            ['priority' => 1, 'family' => 'Workforce', 'protocols' => ['FHIR PractitionerRole/Schedule', 'HL7 MFN', 'Vendor API/files'], 'operationalValue' => 'Current staffing and capacity constraints'],
            ['priority' => 1, 'family' => 'Transport and EVS', 'protocols' => ['REST/webhooks', 'FHIR Task/ServiceRequest'], 'operationalValue' => 'Work queues, delays, and handoffs'],
            ['priority' => 2, 'family' => 'Orders and results', 'protocols' => ['HL7 ORM/OML/ORU', 'FHIR ServiceRequest/Observation'], 'operationalValue' => 'Ancillary readiness and delay milestones'],
            ['priority' => 2, 'family' => 'Perioperative', 'protocols' => ['HL7 SIU/ORM/ORU', 'FHIR Appointment/Procedure'], 'operationalValue' => 'OR status and downstream pressure'],
            ['priority' => 2, 'family' => 'Pharmacy and MAR', 'protocols' => ['HL7 RDE/RDS/RAS', 'FHIR Medication resources'], 'operationalValue' => 'Medication and discharge barriers'],
            ['priority' => 2, 'family' => 'Imaging and PACS', 'protocols' => ['HL7 ORM/ORU', 'DICOMweb'], 'operationalValue' => 'Imaging work and result milestones'],
            ['priority' => 3, 'family' => 'EMS and care transitions', 'protocols' => ['REST/webhooks', 'ADT', 'FHIR Task/Communication'], 'operationalValue' => 'External transfers and discharge movement'],
            ['priority' => 3, 'family' => 'Facilities, RTLS, and nurse call', 'protocols' => ['REST/webhooks', 'MQTT/gateway'], 'operationalValue' => 'Bed, equipment, and resource readiness'],
            ['priority' => 3, 'family' => 'ERP and supply chain', 'protocols' => ['Vendor API', 'FHIR SupplyRequest', 'GS1'], 'operationalValue' => 'Supply constraints and shortage signals'],
            ['priority' => 3, 'family' => 'Payer and prior authorization', 'protocols' => ['X12', 'Da Vinci APIs'], 'operationalValue' => 'Authorization barriers and avoidable days'],
            ['priority' => 4, 'family' => 'HIE, documents, and public health', 'protocols' => ['C-CDA', 'Direct', 'FHIR'], 'operationalValue' => 'External context and transition evidence'],
        ];
    }

    /** @return array<string, mixed> */
    private function mappings(): array
    {
        return [
            'approved' => DB::table('integration.terminology_maps')->where('review_status', 'approved')->count(),
            'pendingReview' => DB::table('integration.terminology_maps')->whereNot('review_status', 'approved')->count(),
            'byType' => DB::table('integration.terminology_maps')
                ->selectRaw('map_type, count(*) as aggregate')
                ->groupBy('map_type')
                ->orderBy('map_type')
                ->get()
                ->map(fn (object $row): array => ['mapType' => $row->map_type, 'count' => (int) $row->aggregate])
                ->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function runs(): array
    {
        return DB::table('raw.ingest_runs as run')
            ->join('integration.sources as source', 'source.source_id', '=', 'run.source_id')
            ->orderByDesc('run.started_at')
            ->limit(25)
            ->get()
            ->map(fn (object $row): array => [
                'runId' => (int) $row->ingest_run_id,
                'sourceName' => $row->source_name,
                'connectorKey' => $row->connector_key,
                'runType' => $row->run_type,
                'status' => $row->status,
                'startedAtIso' => $this->iso($row->started_at),
                'completedAtIso' => $this->iso($row->completed_at),
                'messagesReceived' => (int) $row->messages_received,
                'messagesSucceeded' => (int) $row->messages_succeeded,
                'messagesFailed' => (int) $row->messages_failed,
                'messagesSkipped' => (int) $row->messages_skipped,
                'hasError' => filled($row->error_summary),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function watermarks(): array
    {
        return DB::table('integration.connector_watermarks as watermark')
            ->join('integration.sources as source', 'source.source_id', '=', 'watermark.source_id')
            ->orderByDesc('watermark.last_success_at')
            ->limit(50)
            ->get()
            ->map(fn (object $row): array => [
                'watermarkId' => (int) $row->connector_watermark_id,
                'sourceName' => $row->source_name,
                'connectorKey' => $row->connector_key,
                'scopeType' => $row->scope_type,
                'scopeKeyConfigured' => filled($row->scope_key),
                'watermarkKind' => $row->watermark_kind,
                'cursorStored' => filled($row->watermark_value),
                'lastSuccessAtIso' => $this->iso($row->last_success_at),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function deadLetters(): array
    {
        return DB::table('raw.dead_letters as dead_letter')
            ->leftJoin('integration.sources as source', 'source.source_id', '=', 'dead_letter.source_id')
            ->orderByDesc('dead_letter.created_at')
            ->limit(50)
            ->get()
            ->map(fn (object $row): array => [
                'deadLetterId' => (int) $row->dead_letter_id,
                'sourceName' => $row->source_name,
                'failureStage' => $row->failure_stage,
                'reasonCode' => $row->reason_code,
                'status' => $row->status,
                'createdAtIso' => $this->iso($row->created_at),
                'resolvedAtIso' => $this->iso($row->resolved_at),
                'replayedAtIso' => $this->iso($row->replayed_at),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function projectionErrors(): array
    {
        return DB::table('integration.event_projection_errors')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (object $row): array => [
                'projectionErrorId' => (int) $row->event_projection_error_id,
                'projectorKey' => $row->projector_key,
                'errorCode' => $row->error_code,
                'status' => $row->status,
                'createdAtIso' => $this->iso($row->created_at),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function replayJobs(): array
    {
        return DB::table('integration.event_replay_jobs')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (object $row): array => [
                'replayJobId' => (int) $row->event_replay_job_id,
                'replayType' => $row->replay_type,
                'sourceId' => $row->source_id ? (int) $row->source_id : null,
                'status' => $row->status,
                'dryRun' => (bool) $row->dry_run,
                'eventsReplayed' => (int) $row->events_replayed,
                'eventsFailed' => (int) $row->events_failed,
                'startedAtIso' => $this->iso($row->started_at),
                'completedAtIso' => $this->iso($row->completed_at),
                'createdAtIso' => $this->iso($row->created_at),
                'hasError' => filled($row->error_summary),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function writebackDrafts(): array
    {
        return DB::table('ops.writeback_drafts')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (object $row): array => [
                'writebackDraftId' => (int) $row->writeback_draft_id,
                'targetSystem' => $row->target_system,
                'resourceType' => $row->resource_type,
                'draftType' => $row->draft_type,
                'status' => $row->status,
                'approvalId' => $row->approval_id ? (int) $row->approval_id : null,
                'createdAtIso' => $this->iso($row->created_at),
                'approvedAtIso' => $this->iso($row->approved_at),
                'sentAtIso' => $this->iso($row->sent_at),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function credentials(): array
    {
        $sourceCredentials = DB::table('integration.source_credentials as credential')
            ->join('integration.sources as source', 'source.source_id', '=', 'credential.source_id')
            ->get([
                'credential.source_credential_id', 'credential.source_id', 'credential.credential_key',
                'credential.credential_type', 'credential.secret_ref', 'credential.certificate_ref',
                'credential.jwks_uri', 'credential.rotates_at', 'credential.is_active',
                'credential.metadata', 'source.source_name',
            ])
            ->map(fn (object $row): array => [
                'credentialId' => 'source:'.$row->source_credential_id,
                'sourceCredentialId' => (int) $row->source_credential_id,
                'sourceId' => (int) $row->source_id,
                'sourceName' => $row->source_name,
                'credentialKey' => $row->credential_key,
                'credentialType' => $row->credential_type,
                'status' => $row->is_active ? 'configured' : 'disabled',
                'secretReferenceConfigured' => filled($row->secret_ref),
                'certificateReferenceConfigured' => filled($row->certificate_ref),
                'jwksConfigured' => filled($row->jwks_uri),
                'clientIdConfigured' => false,
                'tokenEndpointConfigured' => false,
                'rotatesAtIso' => $this->iso($row->rotates_at),
                'owner' => $this->decodeMap($row->metadata)['owner'] ?? null,
            ]);

        $smartCredentials = DB::table('integration.smart_backend_credentials as credential')
            ->join('integration.sources as source', 'source.source_id', '=', 'credential.source_id')
            ->get([
                'credential.smart_backend_credential_id', 'credential.source_id', 'credential.credential_key',
                'credential.status', 'credential.jwks_secret_ref', 'credential.client_id',
                'credential.token_url', 'credential.rotates_at', 'credential.metadata', 'source.source_name',
            ])
            ->map(fn (object $row): array => [
                'credentialId' => 'smart:'.$row->smart_backend_credential_id,
                'sourceCredentialId' => null,
                'sourceId' => (int) $row->source_id,
                'sourceName' => $row->source_name,
                'credentialKey' => $row->credential_key,
                'credentialType' => 'smart_backend_services',
                'status' => $row->status,
                'secretReferenceConfigured' => filled($row->jwks_secret_ref),
                'certificateReferenceConfigured' => false,
                'jwksConfigured' => filled($row->jwks_secret_ref),
                'clientIdConfigured' => filled($row->client_id),
                'tokenEndpointConfigured' => filled($row->token_url),
                'rotatesAtIso' => $this->iso($row->rotates_at),
                'owner' => $this->decodeMap($row->metadata)['owner'] ?? null,
            ]);

        return $sourceCredentials
            ->concat($smartCredentials)
            ->sortBy([['sourceName', 'asc'], ['credentialKey', 'asc']])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function templates(): array
    {
        return [
            'playbooks' => DB::table('integration.connector_playbooks')
                ->orderBy('vendor_key')
                ->get()
                ->map(fn (object $row): array => [
                    'vendorKey' => $row->vendor_key,
                    'label' => $row->label,
                    'systemClass' => $row->system_class,
                    'status' => $this->templateSafeStatus((string) $row->status),
                    'capabilities' => JsonMap::from(json_decode($row->capability_payload ?? '{}', true) ?: []),
                    'implementationSteps' => json_decode($row->implementation_steps ?? '[]', true) ?: [],
                ])->all(),
            'coexistenceAdapters' => DB::table('integration.coexistence_adapters')
                ->orderBy('adapter_key')
                ->get()
                ->map(fn (object $row): array => [
                    'adapterKey' => $row->adapter_key,
                    'label' => $row->label,
                    'vendorKey' => $row->vendor_key,
                    'status' => $this->templateSafeStatus((string) $row->status),
                ])->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function configurationAudits(): array
    {
        return DB::table('integration.configuration_audits')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (object $row): array => [
                'auditId' => (int) $row->configuration_audit_id,
                'actorUserId' => $row->actor_user_id ? (int) $row->actor_user_id : null,
                'action' => $row->action,
                'entityType' => $row->entity_type,
                'entityId' => $row->entity_id ? (int) $row->entity_id : null,
                'entityKey' => $row->entity_key,
                'correlationId' => $row->correlation_id,
                'createdAtIso' => $this->iso($row->created_at),
            ])->all();
    }

    private function healthStatus(
        string $configuredStatus,
        ?CarbonImmutable $lastObserved,
        ?string $latestRunStatus,
        mixed $latestRunAt,
        mixed $latestSuccessAt,
    ): string {
        if (in_array($configuredStatus, ['disabled', 'inactive'], true)) {
            return 'disabled';
        }

        if (! in_array($configuredStatus, ['active', 'enabled'], true)) {
            return 'unconfigured';
        }

        $runAt = $this->carbon($latestRunAt);
        $successAt = $this->carbon($latestSuccessAt);
        if ($latestRunStatus === 'failed' && $runAt && (! $successAt || $runAt->greaterThan($successAt))) {
            return 'failed';
        }

        if (! $lastObserved) {
            return 'unobserved';
        }

        $age = max(0, (int) $lastObserved->diffInMinutes(now()));
        $freshAfter = max(1, (int) config('integrations.fresh_after_minutes', 15));
        $staleAfter = max($freshAfter, (int) config('integrations.stale_after_minutes', 60));

        if ($age <= $freshAfter) {
            return 'healthy';
        }

        return $age <= $staleAfter ? 'degraded' : 'stale';
    }

    /** @param Collection<int, array<string, mixed>> $sources */
    private function overallStatus(Collection $sources): string
    {
        if ($sources->isEmpty()) {
            return 'not_configured';
        }

        foreach (['failed', 'stale', 'degraded', 'unobserved', 'healthy'] as $status) {
            if ($sources->contains('healthStatus', $status)) {
                return $status;
            }
        }

        return 'not_configured';
    }

    /** @param list<mixed> $timestamps */
    private function latestTimestamp(array $timestamps): ?CarbonImmutable
    {
        return collect($timestamps)
            ->map(fn (mixed $timestamp): ?CarbonImmutable => $this->carbon($timestamp))
            ->filter()
            ->sortDesc()
            ->first();
    }

    private function carbon(mixed $value): ?CarbonImmutable
    {
        return filled($value) ? CarbonImmutable::parse($value) : null;
    }

    private function iso(mixed $value): ?string
    {
        return $this->carbon($value)?->toIso8601String();
    }

    private function templateSafeStatus(string $status): string
    {
        return $status;
    }

    /** @return array<string, mixed> */
    private function decodeMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) ? json_decode($value, true) : [];

        return is_array($decoded) ? $decoded : [];
    }

    private function urlOrigin(?string $url): ?string
    {
        if (! filled($url)) {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        return is_string($scheme) && is_string($host)
            ? strtolower($scheme).'://'.strtolower($host).($port ? ':'.$port : '')
            : null;
    }
}
