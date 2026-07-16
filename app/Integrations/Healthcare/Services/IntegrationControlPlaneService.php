<?php

namespace App\Integrations\Healthcare\Services;

use App\Security\Secrets\SecretProviderRegistry;
use App\Support\Api\JsonMap;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IntegrationControlPlaneService
{
    public function __construct(private readonly SecretProviderRegistry $secretProviders) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $sourceMetrics = $this->sourceMetrics();
        $sources = DB::table('integration.sources as source')
            ->leftJoin('hosp_org.organizations as organization', 'organization.organization_id', '=', 'source.organization_id')
            ->leftJoin('hosp_org.facilities as facility', 'facility.facility_id', '=', 'source.facility_id')
            ->leftJoin(
                'integration.source_configuration_versions as configuration_version',
                'configuration_version.source_configuration_version_id',
                '=',
                'source.current_configuration_version_id',
            )
            ->select([
                'source.*',
                'organization.name as organization_name',
                'facility.facility_name',
                'configuration_version.version_number as configuration_version_number',
                'configuration_version.configuration_sha256',
                'configuration_version.created_at as configuration_version_created_at',
            ])
            ->orderBy('source.source_name')
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
                + DB::table('integration.smart_backend_credentials')->whereNull('source_credential_id')->count(),
            'credentialVersions' => DB::table('integration.source_credential_versions')->count(),
            'credentialValidations' => DB::table('integration.credential_validation_observations')->count(),
            'networkRoutes' => DB::table('integration.source_network_routes')->where('status', '<>', 'retired')->count(),
            'blockedNetworkRoutes' => DB::table('integration.source_network_routes')->where('status', 'blocked')->count(),
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
            'configurationVersions' => DB::table('integration.source_configuration_versions')->count(),
            'lifecycleEvents' => DB::table('integration.source_lifecycle_events')->count(),
            'healthObservations' => Schema::hasTable('integration.health_observations')
                ? DB::table('integration.health_observations')->count()
                : 0,
            'openSloBreaches' => Schema::hasTable('integration.slo_breach_events')
                ? $sourceMetrics['openSloBreaches']->sum()
                : 0,
            'queuedJobs' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0,
            'failedQueueJobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
            'pendingGovernedChanges' => $this->pendingGovernedChangeCount(),
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
            'secretProviders' => $this->secretProviders->capabilities(),
            'networkRoutes' => $this->networkRoutes(),
            'templates' => $this->templates(),
            'configurationAudits' => $this->configurationAudits(),
            'governedChanges' => $this->governedChanges(),
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
        $currentHealth = $metrics['currentHealth']->get($sourceId);
        $lastObserved = $this->latestTimestamp([
            $latestMessageAt,
            $latestSuccessAt,
            $latestRun?->completed_at,
        ]);
        $derivedHealth = $this->healthStatus(
            (string) $source->active_status,
            $lastObserved,
            $latestRun?->status,
            $latestRun?->started_at,
            $latestSuccessAt,
        );
        $healthFreshUntil = $currentHealth?->freshness_expires_at !== null
            ? $this->carbon($currentHealth->freshness_expires_at)
            : null;
        $healthObservationStale = $healthFreshUntil !== null && $healthFreshUntil->isPast();
        $observabilityStatus = $currentHealth?->observation_status !== null
            ? (string) $currentHealth->observation_status
            : 'unobserved';
        $health = $currentHealth !== null
            ? $this->projectedHealthStatus($observabilityStatus, $healthObservationStale)
            : $derivedHealth;
        $sloSummary = $this->decodeMap($currentHealth?->summary_counts);
        $metadata = $this->decodeMap($source->metadata);

        return [
            'sourceId' => $sourceId,
            'sourceKey' => $source->source_key,
            'sourceName' => $source->source_name,
            'organizationId' => $source->organization_id !== null ? (int) $source->organization_id : null,
            'organizationName' => $source->organization_name,
            'facilityId' => $source->facility_id !== null ? (int) $source->facility_id : null,
            'facilityName' => $source->facility_name,
            'vendor' => $source->vendor,
            'systemClass' => $source->system_class,
            'environment' => $source->environment,
            'interfaceType' => $source->interface_type,
            'configuredStatus' => $source->active_status,
            'healthStatus' => $health,
            'derivedHealthStatus' => $derivedHealth,
            'observabilityStatus' => $observabilityStatus,
            'healthObservationId' => $currentHealth?->health_observation_id !== null
                ? (int) $currentHealth->health_observation_id
                : null,
            'healthObservedAtIso' => $this->iso($currentHealth?->observed_at),
            'healthFreshUntilIso' => $this->iso($currentHealth?->freshness_expires_at),
            'healthObservationStale' => $healthObservationStale,
            'maintenanceActive' => (bool) ($currentHealth?->maintenance_active ?? false),
            'sloDefinitionId' => $currentHealth?->source_slo_definition_id !== null
                ? (int) $currentHealth->source_slo_definition_id
                : null,
            'sloSummary' => [
                'met' => (int) ($sloSummary['met'] ?? 0),
                'breached' => (int) ($sloSummary['breached'] ?? 0),
                'unknown' => (int) ($sloSummary['unknown'] ?? 0),
                'notApplicable' => (int) ($sloSummary['not_applicable'] ?? 0),
            ],
            'queueState' => JsonMap::from($this->decodeMap($currentHealth?->queue_state)),
            'runtimeState' => JsonMap::from($this->decodeMap($currentHealth?->runtime_state)),
            'openSloBreaches' => (int) $metrics['openSloBreaches']->get($sourceId, 0),
            'protocolHealthStatus' => $source->protocol_health_status,
            'protocolHealthCheckedAtIso' => $this->iso($source->protocol_health_checked_at),
            'protocolHealthErrorCode' => $source->protocol_health_error,
            'goLiveStatus' => $source->go_live_status,
            'lifecycleState' => $source->lifecycle_state,
            'lifecycleChangedAtIso' => $this->iso($source->lifecycle_changed_at),
            'currentConfigurationVersionId' => $source->current_configuration_version_id !== null
                ? (int) $source->current_configuration_version_id
                : null,
            'currentConfigurationVersionNumber' => $source->configuration_version_number !== null
                ? (int) $source->configuration_version_number
                : null,
            'currentConfigurationSha256' => $source->configuration_sha256,
            'currentConfigurationCreatedAtIso' => $this->iso($source->configuration_version_created_at),
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

        $currentHealth = Schema::hasTable('integration.source_health_current')
            ? DB::table('integration.source_health_current')->get()->keyBy('source_id')
            : collect();
        $openSloBreaches = collect();
        if (Schema::hasTable('integration.slo_breach_events')) {
            $latestBreachEvents = DB::table('integration.slo_breach_events')
                ->selectRaw('slo_breach_id, max(slo_breach_event_id) AS event_id')
                ->groupBy('slo_breach_id');
            $openSloBreaches = DB::table('integration.slo_breaches AS breach')
                ->joinSub($latestBreachEvents, 'latest', 'latest.slo_breach_id', '=', 'breach.slo_breach_id')
                ->join('integration.slo_breach_events AS event', 'event.slo_breach_event_id', '=', 'latest.event_id')
                ->whereIn('event.status_after', ['open', 'suppressed', 'acknowledged'])
                ->selectRaw('breach.source_id, count(*) AS aggregate')
                ->groupBy('breach.source_id')
                ->pluck('aggregate', 'source_id')
                ->map(fn (mixed $count): int => (int) $count);
        }

        return [
            'currentHealth' => $currentHealth,
            'openSloBreaches' => $openSloBreaches,
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
        $resourceProfiles = DB::table('integration.fhir_resource_profiles')
            ->orderBy('resource_type')
            ->get([
                'fhir_resource_profile_id',
                'source_id',
                'resource_type',
                'canonical_profile_url',
                'canonical_profile_version',
                'profile_status',
                'poll_enabled',
                'cadence_minutes',
                'page_size',
                'page_limit',
                'resource_limit',
                'version_number',
                'change_reason',
            ])
            ->groupBy('source_id');

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
            ->map(function (object $row) use ($resourceCounts, $resourceProfiles): array {
                $profiles = collect($resourceProfiles->get($row->source_id, []))
                    ->map(fn (object $profile): array => [
                        'profileId' => (int) $profile->fhir_resource_profile_id,
                        'resourceType' => (string) $profile->resource_type,
                        'canonicalProfileUrl' => $profile->canonical_profile_url,
                        'canonicalProfileVersion' => $profile->canonical_profile_version,
                        'status' => (string) $profile->profile_status,
                        'pollEnabled' => (bool) $profile->poll_enabled,
                        'cadenceMinutes' => (int) $profile->cadence_minutes,
                        'pageSize' => (int) $profile->page_size,
                        'pageLimit' => (int) $profile->page_limit,
                        'resourceLimit' => (int) $profile->resource_limit,
                        'versionNumber' => (int) $profile->version_number,
                        'changeReason' => (string) $profile->change_reason,
                    ])->values()->all();

                return [
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
                    'resourceProfiles' => $profiles,
                ];
            })->all();
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
                'credential.current_credential_version_id', 'credential.credential_state',
                'credential.valid_from', 'credential.expires_at', 'credential.rotation_overlap_ends_at',
                'credential.revoked_at', 'credential.last_used_at',
                'credential.metadata', 'source.source_name',
            ])
            ->map(function (object $row): array {
                $smart = DB::table('integration.smart_backend_credentials')
                    ->where('source_credential_id', $row->source_credential_id)
                    ->first();
                $observation = DB::table('integration.credential_validation_observations')
                    ->where('source_credential_id', $row->source_credential_id)
                    ->orderByDesc('credential_validation_observation_id')
                    ->first();
                $versionNumber = $row->current_credential_version_id !== null
                    ? DB::table('integration.source_credential_versions')
                        ->where('source_credential_version_id', $row->current_credential_version_id)
                        ->value('version_number')
                    : null;
                $certificate = $this->decodeMap($observation?->certificate_metadata);

                return [
                    'credentialId' => 'source:'.$row->source_credential_id,
                    'sourceCredentialId' => (int) $row->source_credential_id,
                    'sourceId' => (int) $row->source_id,
                    'sourceName' => $row->source_name,
                    'credentialKey' => $row->credential_key,
                    'credentialType' => $row->credential_type,
                    'status' => (string) $row->credential_state,
                    'credentialState' => (string) $row->credential_state,
                    'credentialVersionId' => $row->current_credential_version_id !== null ? (int) $row->current_credential_version_id : null,
                    'credentialVersionNumber' => $versionNumber !== null ? (int) $versionNumber : null,
                    'secretReferenceConfigured' => filled($row->secret_ref),
                    'secretProviderScheme' => filled($row->secret_ref) ? parse_url((string) $row->secret_ref, PHP_URL_SCHEME) : null,
                    'certificateReferenceConfigured' => filled($row->certificate_ref),
                    'certificateProviderScheme' => filled($row->certificate_ref) ? parse_url((string) $row->certificate_ref, PHP_URL_SCHEME) : null,
                    'jwksConfigured' => filled($row->jwks_uri),
                    'clientIdConfigured' => filled($smart?->client_id),
                    'tokenEndpointConfigured' => filled($smart?->token_url),
                    'validFromIso' => $this->iso($row->valid_from),
                    'expiresAtIso' => $this->iso($row->expires_at),
                    'rotatesAtIso' => $this->iso($row->rotates_at),
                    'rotationOverlapEndsAtIso' => $this->iso($row->rotation_overlap_ends_at),
                    'revokedAtIso' => $this->iso($row->revoked_at),
                    'lastUsedAtIso' => $this->iso($row->last_used_at),
                    'validationStatus' => $observation?->validation_status ?? 'unobserved',
                    'rotationState' => $observation?->rotation_state ?? 'unobserved',
                    'validationErrorCode' => $observation?->error_code,
                    'validatedAtIso' => $this->iso($observation?->observed_at),
                    'providerVersion' => $observation?->provider_version,
                    'providerLeaseExpiresAtIso' => $this->iso($observation?->provider_lease_expires_at),
                    'certificateChainLength' => isset($certificate['chainLength']) ? (int) $certificate['chainLength'] : null,
                    'certificateExpiresAtIso' => data_get($certificate, 'leaf.notAfterIso'),
                    'certificateFingerprintSha256' => data_get($certificate, 'leaf.fingerprintSha256'),
                    'owner' => $this->decodeMap($row->metadata)['owner'] ?? null,
                ];
            });

        $smartCredentials = DB::table('integration.smart_backend_credentials as credential')
            ->join('integration.sources as source', 'source.source_id', '=', 'credential.source_id')
            ->whereNull('credential.source_credential_id')
            ->get([
                'credential.smart_backend_credential_id', 'credential.source_id', 'credential.credential_key',
                'credential.status', 'credential.jwks_secret_ref', 'credential.client_id',
                'credential.token_url', 'credential.issued_at', 'credential.expires_at',
                'credential.rotates_at', 'credential.metadata', 'source.source_name',
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
                'credentialState' => $row->status,
                'credentialVersionId' => null,
                'credentialVersionNumber' => null,
                'secretProviderScheme' => filled($row->jwks_secret_ref) ? parse_url((string) $row->jwks_secret_ref, PHP_URL_SCHEME) : null,
                'certificateProviderScheme' => null,
                'validFromIso' => null,
                'expiresAtIso' => $this->iso($row->expires_at ?? null),
                'rotationOverlapEndsAtIso' => null,
                'revokedAtIso' => null,
                'lastUsedAtIso' => $this->iso($row->issued_at ?? null),
                'validationStatus' => 'unobserved',
                'rotationState' => 'unobserved',
                'validationErrorCode' => null,
                'validatedAtIso' => null,
                'providerVersion' => null,
                'providerLeaseExpiresAtIso' => null,
                'certificateChainLength' => null,
                'certificateExpiresAtIso' => null,
                'certificateFingerprintSha256' => null,
                'owner' => $this->decodeMap($row->metadata)['owner'] ?? null,
            ]);

        return $sourceCredentials
            ->concat($smartCredentials)
            ->sortBy([['sourceName', 'asc'], ['credentialKey', 'asc']])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function networkRoutes(): array
    {
        return DB::table('integration.source_network_routes as route')
            ->join('integration.sources as source', 'source.source_id', '=', 'route.source_id')
            ->orderBy('source.source_name')
            ->orderBy('route.route_key')
            ->get([
                'route.source_network_route_id', 'route.source_id', 'route.source_endpoint_id',
                'route.route_key', 'route.environment', 'route.transport', 'route.hostname',
                'route.port', 'route.proxy_url', 'route.dns_policy', 'route.allowed_ip_cidrs',
                'route.egress_policy_key', 'route.mtls_required', 'route.client_credential_id',
                'route.server_name', 'route.status', 'source.source_name',
            ])
            ->map(function (object $row): array {
                $observation = DB::table('integration.network_route_observations')
                    ->where('source_network_route_id', $row->source_network_route_id)
                    ->orderByDesc('network_route_observation_id')
                    ->first();

                return [
                    'networkRouteId' => (int) $row->source_network_route_id,
                    'sourceId' => (int) $row->source_id,
                    'sourceName' => (string) $row->source_name,
                    'endpointId' => $row->source_endpoint_id !== null ? (int) $row->source_endpoint_id : null,
                    'routeKey' => (string) $row->route_key,
                    'environment' => (string) $row->environment,
                    'transport' => (string) $row->transport,
                    'hostname' => (string) $row->hostname,
                    'port' => (int) $row->port,
                    'proxyConfigured' => filled($row->proxy_url),
                    'proxyOrigin' => filled($row->proxy_url) ? $this->urlOrigin((string) $row->proxy_url) : null,
                    'dnsPolicy' => (string) $row->dns_policy,
                    'allowedIpCidrs' => $this->decodeList($row->allowed_ip_cidrs),
                    'egressPolicyKey' => (string) $row->egress_policy_key,
                    'mtlsRequired' => (bool) $row->mtls_required,
                    'clientCredentialId' => $row->client_credential_id !== null ? (int) $row->client_credential_id : null,
                    'serverName' => $row->server_name,
                    'status' => (string) $row->status,
                    'lastAddressCount' => $observation !== null ? (int) $observation->address_count : 0,
                    'lastErrorCode' => $observation?->error_code,
                    'lastObservedAtIso' => $this->iso($observation?->observed_at),
                    'policySha256' => $observation?->policy_sha256,
                ];
            })->all();
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

    private function pendingGovernedChangeCount(): int
    {
        if (! Schema::hasTable('governance.change_requests')) {
            return 0;
        }

        return DB::table('governance.change_requests as request')
            ->leftJoin('governance.change_decisions as decision', 'decision.change_request_uuid', '=', 'request.change_request_uuid')
            ->where('request.action_type', '!=', 'purge_user_identity')
            ->where('request.expires_at', '>', now())
            ->whereNull('decision.change_decision_id')
            ->count();
    }

    /** @return list<array<string, mixed>> */
    private function governedChanges(): array
    {
        if (! Schema::hasTable('governance.change_requests')) {
            return [];
        }

        $successfulExecutions = DB::table('governance.change_executions')
            ->selectRaw('change_request_uuid, max(executed_at) as executed_at')
            ->where('outcome', 'success')
            ->groupBy('change_request_uuid');

        return DB::table('governance.change_requests as request')
            ->leftJoin('governance.change_decisions as decision', 'decision.change_request_uuid', '=', 'request.change_request_uuid')
            ->leftJoinSub($successfulExecutions, 'execution', 'execution.change_request_uuid', '=', 'request.change_request_uuid')
            ->where('request.action_type', '!=', 'purge_user_identity')
            ->orderByRaw('CASE WHEN decision.change_decision_id IS NULL AND request.expires_at > now() THEN 0 ELSE 1 END')
            ->orderByDesc('request.requested_at')
            ->limit(100)
            ->get([
                'request.change_request_uuid',
                'request.action_type',
                'request.subject_type',
                'request.subject_id',
                'request.author_user_id',
                'request.organization_id',
                'request.facility_id',
                'request.requested_at',
                'request.expires_at',
                'decision.decision',
                'decision.decided_by_user_id',
                'decision.decided_at',
                'execution.executed_at',
            ])
            ->map(fn (object $row): array => [
                'changeRequestUuid' => $row->change_request_uuid,
                'actionType' => $row->action_type,
                'subjectType' => $row->subject_type,
                'subjectId' => $row->subject_id,
                'authorUserId' => (int) $row->author_user_id,
                'organizationId' => $row->organization_id !== null ? (int) $row->organization_id : null,
                'facilityId' => $row->facility_id !== null ? (int) $row->facility_id : null,
                'sourceId' => $this->governedSourceId((string) $row->subject_type, (string) $row->subject_id),
                'status' => match (true) {
                    $row->executed_at !== null => 'executed',
                    $row->decision !== null => $row->decision,
                    now()->isAfter($row->expires_at) => 'expired',
                    default => 'pending',
                },
                'requestedAtIso' => $this->iso($row->requested_at),
                'expiresAtIso' => $this->iso($row->expires_at),
                'decidedByUserId' => $row->decided_by_user_id ? (int) $row->decided_by_user_id : null,
                'decidedAtIso' => $this->iso($row->decided_at),
                'executedAtIso' => $this->iso($row->executed_at),
            ])->all();
    }

    private function governedSourceId(string $subjectType, string $subjectId): ?int
    {
        if ($subjectType === 'integration_source' && ctype_digit($subjectId)) {
            return (int) $subjectId;
        }
        if (in_array($subjectType, ['integration_credential', 'integration_source_configuration'], true)) {
            $sourceId = explode(':', $subjectId, 2)[0];

            return ctype_digit($sourceId) ? (int) $sourceId : null;
        }
        if ($subjectType === 'source_activation_window') {
            $sourceId = DB::table('integration.source_activation_windows')
                ->where('activation_window_uuid', $subjectId)
                ->value('source_id');

            return $sourceId !== null ? (int) $sourceId : null;
        }
        if ($subjectType === 'payload_quarantine') {
            $sourceId = DB::table('raw.payload_quarantines')
                ->where('quarantine_uuid', $subjectId)
                ->value('source_id');

            return $sourceId !== null ? (int) $sourceId : null;
        }
        if ($subjectType === 'clinical_payload') {
            $sourceId = DB::table('raw.payload_objects')
                ->where('payload_uuid', $subjectId)
                ->value('source_id');

            return $sourceId !== null ? (int) $sourceId : null;
        }

        return null;
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

    private function projectedHealthStatus(string $status, bool $stale): string
    {
        if ($stale) {
            return 'stale';
        }

        return match ($status) {
            'healthy' => 'healthy',
            'degraded', 'maintenance' => 'degraded',
            'failed' => 'failed',
            'disabled' => 'disabled',
            default => 'unobserved',
        };
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

    /** @return list<string> */
    private function decodeList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
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
