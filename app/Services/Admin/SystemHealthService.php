<?php

namespace App\Services\Admin;

use App\Models\Governance\SystemHealthObservation;
use App\Models\User;
use App\Security\ClinicalPayloads\ClinicalContentGuard;
use App\Services\Alerting\OperationalAlert;
use App\Services\Alerting\OperationalAlertDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bounded, PHI-free platform diagnostics and their append-only read model.
 *
 * This service never calls an EHR, advances an integration cursor, reads a
 * backup payload, or returns an exception message/path/secret. External service
 * health remains unknown until a purpose-built heartbeat exists.
 */
final class SystemHealthService
{
    private const STATUSES = ['healthy', 'warning', 'critical', 'unknown', 'disabled'];

    public function __construct(
        private readonly OperationalAlertDispatcher $alertDispatcher,
        private readonly ClinicalContentGuard $contentGuard,
    ) {}

    /** @return array<string, mixed> */
    public function collect(string $origin, ?User $actor = null, ?string $correlationId = null): array
    {
        if (! in_array($origin, ['scheduled', 'manual'], true)) {
            throw new \InvalidArgumentException('System health observation origin is invalid.');
        }

        if ($correlationId !== null && ! Str::isUuid($correlationId)) {
            throw new \InvalidArgumentException('System health correlation ID must be a UUID.');
        }

        $batchUuid = $correlationId ?? (string) Str::uuid7();
        $observedAt = CarbonImmutable::now();
        $freshnessSeconds = max(60, (int) config('admin-health.fresh_for_seconds', 180));

        // Prior status per component so alert delivery fires only on the
        // transition INTO critical — a persistently critical component never
        // re-pages, matching the flap-damped cockpit doctrine.
        $priorStatus = $this->currentStatusByComponent();
        $newlyCritical = [];

        foreach ($this->catalog() as $key => $component) {
            // A manual diagnostic must not manufacture evidence that the
            // scheduler is running. Only the scheduled command emits it.
            if ($key === 'scheduler' && $origin !== 'scheduled') {
                continue;
            }

            $started = hrtime(true);
            try {
                $result = $this->probe($key, $origin);
            } catch (Throwable) {
                $result = [
                    'status' => 'critical',
                    'summary' => 'The bounded diagnostic did not complete.',
                    'errorCode' => 'probe_failed',
                    'details' => [],
                ];
            }

            $durationMs = min(300_000, max(0, (int) round((hrtime(true) - $started) / 1_000_000)));
            $status = in_array($result['status'] ?? null, self::STATUSES, true)
                ? $result['status']
                : 'unknown';

            SystemHealthObservation::query()->create([
                'observation_uuid' => (string) Str::uuid7(),
                'batch_uuid' => $batchUuid,
                'component_key' => $key,
                'component_label' => $component['label'],
                'category' => $component['category'],
                'status' => $status,
                'summary' => Str::limit((string) ($result['summary'] ?? 'No diagnostic summary was produced.'), 300, ''),
                'error_code' => $result['errorCode'] ?? null,
                'observed_at' => $observedAt,
                'duration_ms' => $durationMs,
                'freshness_expires_at' => $observedAt->addSeconds($freshnessSeconds),
                'required' => (bool) $component['required'],
                'owner' => $component['owner'],
                'runbook_ref' => $this->runbookRef($component['runbook']),
                'origin' => $origin,
                'details' => $result['details'] ?? [],
                'recorded_by_user_id' => $actor?->getKey(),
                'created_at' => $observedAt,
            ]);

            if ($status === 'critical' && (($priorStatus[$key] ?? null) !== 'critical') && (bool) $component['required']) {
                $newlyCritical[] = ['key' => $key, 'label' => $component['label'], 'errorCode' => $result['errorCode'] ?? null];
            }
        }

        foreach ($newlyCritical as $component) {
            $this->alertDispatcher->dispatch(
                new OperationalAlert(
                    severity: 'crit',
                    domain: 'system_health',
                    code: 'system_health_component_critical',
                    title: sprintf('%s is critical', $component['label']),
                    sourceLabel: $component['key'],
                    deepLink: '/admin/system-health/'.$component['key'],
                    facts: array_filter([
                        'component' => $component['key'],
                        'error_code' => $component['errorCode'],
                    ], fn (mixed $value): bool => $value !== null),
                ),
                'system_health_component',
                $component['key'],
                $batchUuid,
                $observedAt,
            );
        }

        return $this->snapshot(batchUuid: $batchUuid);
    }

    /**
     * Acknowledge a critical/attention system-health component — an operator
     * triage action recorded in the append-only acknowledgement ledger with a
     * bounded reason (no content). The observation history is never mutated.
     *
     * @return array<string, mixed>
     */
    public function acknowledgeComponent(
        string $componentKey,
        User $actor,
        string $reason,
        ?string $correlationId = null,
    ): array {
        if (! array_key_exists($componentKey, $this->catalog())) {
            abort(404);
        }
        if ($correlationId !== null && ! Str::isUuid($correlationId)) {
            throw new \InvalidArgumentException('System health correlation ID must be a UUID.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('System health acknowledgement reason must be 10-500 characters.');
        }

        $latest = SystemHealthObservation::query()
            ->where('component_key', $componentKey)
            ->orderByDesc('system_health_observation_id')
            ->first();
        $acknowledgedStatus = $latest !== null
            && in_array($latest->status, ['critical', 'warning', 'unknown', 'disabled'], true)
            ? $latest->status
            : 'unknown';

        $now = CarbonImmutable::now();
        $row = [
            'acknowledgement_uuid' => (string) Str::uuid7(),
            'component_key' => $componentKey,
            'acknowledged_status' => $acknowledgedStatus,
            'system_health_observation_id' => $latest?->getKey(),
            'acknowledged_by_user_id' => $actor->getKey(),
            'reason' => $reason,
            'correlation_uuid' => $correlationId,
            'acknowledged_at' => $now,
            'created_at' => $now,
        ];
        $this->contentGuard->assertSafe($row, 'system_health_acknowledgement_content_rejected');
        DB::table('governance.system_health_acknowledgements')->insert($row);

        return [
            'componentKey' => $componentKey,
            'acknowledgedStatus' => $acknowledgedStatus,
            'acknowledgementUuid' => $row['acknowledgement_uuid'],
            'acknowledgedByUserId' => $actor->getKey(),
            'acknowledgedAtIso' => $now->toIso8601String(),
        ];
    }

    /** @return array<string, array<string, mixed>> latest acknowledgement per component */
    private function latestAcknowledgements(): array
    {
        if (! Schema::hasTable('governance.system_health_acknowledgements')) {
            return [];
        }
        $latestIds = DB::table('governance.system_health_acknowledgements')
            ->selectRaw('max(system_health_acknowledgement_id) as id')
            ->groupBy('component_key')
            ->pluck('id');

        return DB::table('governance.system_health_acknowledgements')
            ->whereIn('system_health_acknowledgement_id', $latestIds)
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->component_key => [
                'acknowledgedStatus' => (string) $row->acknowledged_status,
                'acknowledgedByUserId' => (int) $row->acknowledged_by_user_id,
                'acknowledgedAtIso' => CarbonImmutable::parse($row->acknowledged_at)->toIso8601String(),
            ]])
            ->all();
    }

    /** @return array<string, string> latest recorded status keyed by component */
    private function currentStatusByComponent(): array
    {
        if (! Schema::hasTable('governance.system_health_observations')) {
            return [];
        }
        $latestIds = DB::table('governance.system_health_observations')
            ->selectRaw('max(system_health_observation_id) as observation_id')
            ->groupBy('component_key')
            ->pluck('observation_id');

        return SystemHealthObservation::query()
            ->whereIn('system_health_observation_id', $latestIds)
            ->get()
            ->mapWithKeys(fn (SystemHealthObservation $row): array => [$row->component_key => $row->status])
            ->all();
    }

    /** @return array<string, mixed> */
    public function snapshot(?string $selectedKey = null, ?string $batchUuid = null): array
    {
        $latest = collect();
        if (Schema::hasTable('governance.system_health_observations')) {
            $latestIds = DB::table('governance.system_health_observations')
                ->selectRaw('max(system_health_observation_id) as observation_id')
                ->groupBy('component_key')
                ->pluck('observation_id');

            $latest = SystemHealthObservation::query()
                ->whereIn('system_health_observation_id', $latestIds)
                ->get()
                ->keyBy('component_key');
        }

        $acknowledgements = $this->latestAcknowledgements();

        $now = CarbonImmutable::now();
        $observations = collect($this->catalog())->map(function (array $component, string $key) use ($latest, $acknowledgements, $now): array {
            /** @var SystemHealthObservation|null $row */
            $row = $latest->get($key);
            if ($row === null) {
                return $this->missingObservation($key, $component) + ['acknowledgement' => $acknowledgements[$key] ?? null];
            }

            $expired = $row->freshness_expires_at->isBefore($now);
            $status = $expired ? 'unknown' : $row->status;

            return [
                'key' => $key,
                'label' => $component['label'],
                'category' => $component['category'],
                'acknowledgement' => $acknowledgements[$key] ?? null,
                'status' => $status,
                'recordedStatus' => $row->status,
                'summary' => $expired
                    ? 'The last observation expired; current state is unknown.'
                    : $row->summary,
                'errorCode' => $expired ? 'observation_expired' : $row->error_code,
                'required' => (bool) $component['required'],
                'owner' => $component['owner'],
                'runbookRef' => $row->runbook_ref,
                'runbookUrl' => $this->runbookUrl($component['runbook']),
                'observedAt' => $row->observed_at->toIso8601String(),
                'freshUntil' => $row->freshness_expires_at->toIso8601String(),
                'durationMs' => $row->duration_ms,
                'origin' => $row->origin,
                'stale' => $expired,
                'details' => $row->details ?? [],
                'href' => '/admin/system-health/'.$key,
            ];
        })->values();

        $required = $observations->where('required', true);
        $overallStatus = match (true) {
            $required->contains('status', 'critical') => 'critical',
            $required->contains(fn (array $item): bool => in_array($item['status'], ['warning', 'unknown', 'disabled'], true)) => 'degraded',
            $required->isNotEmpty() => 'healthy',
            default => 'unknown',
        };

        $counts = collect(self::STATUSES)
            ->mapWithKeys(fn (string $status): array => [$status => $observations->where('status', $status)->count()])
            ->all();
        $counts['requiredAttention'] = $required
            ->filter(fn (array $item): bool => $item['status'] !== 'healthy')
            ->count();

        $selected = $selectedKey !== null ? $observations->firstWhere('key', $selectedKey) : null;
        if ($selectedKey !== null && $selected === null) {
            abort(404);
        }

        $lastScheduledAt = $latest
            ->where('origin', 'scheduled')
            ->max(fn (SystemHealthObservation $row) => $row->observed_at?->getTimestamp());

        return [
            'generatedAt' => $now->toIso8601String(),
            'batchUuid' => $batchUuid,
            'correlationId' => $batchUuid,
            'batchObservationCount' => $batchUuid !== null && Schema::hasTable('governance.system_health_observations')
                ? SystemHealthObservation::query()->where('batch_uuid', $batchUuid)->count()
                : null,
            'overallStatus' => $overallStatus,
            'counts' => $counts,
            'lastScheduledAt' => $lastScheduledAt ? CarbonImmutable::createFromTimestamp($lastScheduledAt)->toIso8601String() : null,
            'observations' => $observations->all(),
            'selectedComponent' => $selected,
            'contract' => [
                'freshForSeconds' => max(60, (int) config('admin-health.fresh_for_seconds', 180)),
                'statuses' => self::STATUSES,
                'appendOnly' => true,
                'externalCallsAllowed' => false,
            ],
        ];
    }

    /** @return array<string, array{label:string, category:string, required:bool, owner:string, runbook:string}> */
    private function catalog(): array
    {
        /** @var array<string, array{label:string, category:string, required:bool, owner:string, runbook:string}> $catalog */
        $catalog = config('admin-health.components', []);

        return $catalog;
    }

    /** @return array{status:string, summary:string, errorCode?:string|null, details:array<string, mixed>} */
    private function probe(string $key, string $origin): array
    {
        return match ($key) {
            'database' => $this->probeDatabase(),
            'database_replicas' => $this->probeDatabaseReplicas(),
            'queue' => $this->probeQueue(),
            'scheduler' => $this->probeScheduler($origin),
            'cache' => $this->probeCache(),
            'sessions' => $this->probeSessions(),
            'integration_runtime' => $this->probeIntegrationRuntime(),
            'patient_projection_pipeline' => $this->probePatientProjectionPipeline(),
            'realtime' => $this->probeRealtime(),
            'object_storage' => $this->probeObjectStorage(),
            'disk_capacity' => $this->probeDiskCapacity(),
            'backups' => $this->probeBackups(),
            'tls_certificate' => $this->probeTlsCertificate(),
            'arena' => $this->probeArena(),
            'eddy' => $this->probeEddy(),
            default => [
                'status' => 'unknown',
                'summary' => 'No bounded diagnostic is registered for this component.',
                'errorCode' => 'probe_not_registered',
                'details' => [],
            ],
        };
    }

    private function probeDatabase(): array
    {
        $result = DB::selectOne('select 1 as connection_ok');
        $ok = (int) ($result->connection_ok ?? 0) === 1;

        return [
            'status' => $ok ? 'healthy' : 'critical',
            'summary' => $ok ? 'The primary database accepted a bounded read.' : 'The primary database check returned an invalid response.',
            'errorCode' => $ok ? null : 'database_invalid_response',
            'details' => [
                'driver' => DB::connection()->getDriverName(),
                'readSucceeded' => $ok,
            ],
        ];
    }

    private function probeDatabaseReplicas(): array
    {
        $expected = max(0, (int) config('admin-health.database.expected_replica_count', 0));
        if ($expected === 0) {
            return [
                'status' => 'disabled',
                'summary' => 'No database replicas are declared in the deployment contract.',
                'details' => ['expectedReplicaCount' => 0, 'connectedReplicaCount' => null],
            ];
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return [
                'status' => 'unknown',
                'summary' => 'Replica telemetry is implemented only for PostgreSQL.',
                'errorCode' => 'replica_telemetry_unsupported',
                'details' => ['expectedReplicaCount' => $expected, 'connectedReplicaCount' => null],
            ];
        }

        $connected = (int) (DB::selectOne("select count(*) as aggregate from pg_stat_replication where state = 'streaming'")->aggregate ?? 0);
        $status = $connected >= $expected ? 'healthy' : 'critical';

        return [
            'status' => $status,
            'summary' => $status === 'healthy' ? 'The declared PostgreSQL replicas are streaming.' : 'Fewer PostgreSQL replicas are streaming than the deployment contract requires.',
            'errorCode' => $status === 'healthy' ? null : 'replica_count_below_contract',
            'details' => ['expectedReplicaCount' => $expected, 'connectedReplicaCount' => $connected],
        ];
    }

    private function probeQueue(): array
    {
        $connection = (string) config('queue.default', 'sync');
        $driver = (string) config("queue.connections.{$connection}.driver", $connection);
        if (in_array($driver, ['sync', 'null'], true)) {
            return [
                'status' => 'disabled',
                'summary' => 'Asynchronous queue processing is not enabled.',
                'errorCode' => 'async_queue_disabled',
                'details' => ['connection' => $connection, 'driver' => $driver, 'queuedJobs' => 0, 'failedJobs' => 0, 'oldestAgeSeconds' => null],
            ];
        }
        if ($driver !== 'database') {
            return [
                'status' => 'unknown',
                'summary' => 'An external queue is configured, but bounded worker telemetry is not connected.',
                'errorCode' => 'external_queue_telemetry_missing',
                'details' => ['connection' => $connection, 'driver' => $driver, 'queuedJobs' => null, 'failedJobs' => null, 'oldestAgeSeconds' => null],
            ];
        }
        if (! Schema::hasTable('jobs') || ! Schema::hasTable('failed_jobs')) {
            return [
                'status' => 'critical',
                'summary' => 'The database queue tables are unavailable.',
                'errorCode' => 'queue_tables_missing',
                'details' => ['connection' => $connection, 'driver' => $driver, 'queuedJobs' => null, 'failedJobs' => null, 'oldestAgeSeconds' => null],
            ];
        }

        $queued = DB::table('jobs')->count();
        $failed = DB::table('failed_jobs')->count();
        $oldestCreatedAt = DB::table('jobs')->min('created_at');
        $oldestAge = $oldestCreatedAt !== null ? max(0, now()->timestamp - (int) $oldestCreatedAt) : null;
        $warningAge = max(30, (int) config('admin-health.queue.warning_age_seconds', 120));
        $criticalAge = max($warningAge, (int) config('admin-health.queue.critical_age_seconds', 600));
        $criticalFailures = max(1, (int) config('admin-health.queue.critical_failed_jobs', 10));

        $status = match (true) {
            $failed >= $criticalFailures || ($oldestAge !== null && $oldestAge >= $criticalAge) => 'critical',
            $failed > 0 || ($oldestAge !== null && $oldestAge >= $warningAge) => 'warning',
            default => 'healthy',
        };

        return [
            'status' => $status,
            'summary' => match ($status) {
                'critical' => 'The database queue has a critical failure count or job age.',
                'warning' => 'The database queue requires operator review.',
                default => 'The database queue has no aged or failed work.',
            },
            'errorCode' => $status === 'healthy' ? null : 'queue_attention_required',
            'details' => ['connection' => $connection, 'driver' => $driver, 'queuedJobs' => $queued, 'failedJobs' => $failed, 'oldestAgeSeconds' => $oldestAge],
        ];
    }

    private function probeScheduler(string $origin): array
    {
        return [
            'status' => $origin === 'scheduled' ? 'healthy' : 'unknown',
            'summary' => $origin === 'scheduled'
                ? 'The Laravel scheduler invoked the health collector.'
                : 'Manual diagnostics cannot establish scheduler health.',
            'errorCode' => $origin === 'scheduled' ? null : 'scheduler_unobserved',
            'details' => ['scheduledInvocation' => $origin === 'scheduled'],
        ];
    }

    private function probeCache(): array
    {
        $key = 'admin-health:'.Str::uuid7();
        $value = (string) Str::uuid7();
        Cache::put($key, $value, 10);
        $ok = hash_equals($value, (string) Cache::get($key));
        Cache::forget($key);

        return [
            'status' => $ok ? 'healthy' : 'critical',
            'summary' => $ok ? 'A temporary cache value completed a write/read/delete round trip.' : 'The cache round trip did not return the expected value.',
            'errorCode' => $ok ? null : 'cache_round_trip_failed',
            'details' => ['store' => (string) config('cache.default'), 'roundTripSucceeded' => $ok],
        ];
    }

    private function probeSessions(): array
    {
        $driver = (string) config('session.driver', 'file');
        $secure = (bool) config('session.secure');
        $httpOnly = (bool) config('session.http_only');
        $sameSite = config('session.same_site');
        $isProduction = app()->environment('production');
        $tableReady = $driver !== 'database' || Schema::hasTable((string) config('session.table', 'sessions'));
        $invalidCookiePolicy = ! $httpOnly || ($isProduction && ! $secure) || ! in_array($sameSite, ['lax', 'strict', 'none'], true);
        $disabled = in_array($driver, ['array', 'null'], true);

        $status = match (true) {
            ! $tableReady || $invalidCookiePolicy => 'critical',
            $disabled => 'disabled',
            default => 'healthy',
        };

        return [
            'status' => $status,
            'summary' => match ($status) {
                'critical' => 'The session store or cookie security policy is incomplete.',
                'disabled' => 'Persistent sessions are disabled in this environment.',
                default => 'The configured session store and cookie policy pass bounded checks.',
            },
            'errorCode' => match ($status) {
                'critical' => 'session_configuration_invalid',
                'disabled' => 'persistent_sessions_disabled',
                default => null,
            },
            'details' => ['driver' => $driver, 'secureCookie' => $secure, 'httpOnly' => $httpOnly, 'sameSite' => $sameSite, 'storeReady' => $tableReady],
        ];
    }

    private function probeIntegrationRuntime(): array
    {
        if (! Schema::hasTable('integration.sources')) {
            return [
                'status' => 'critical',
                'summary' => 'The integration control-plane schema is unavailable.',
                'errorCode' => 'integration_schema_missing',
                'details' => ['sourceCount' => null, 'activeSources' => null, 'failedSources' => null, 'unobservedSources' => null, 'openDeadLetters' => null, 'openProjectionErrors' => null],
            ];
        }

        $sourceCount = DB::table('integration.sources')->count();
        $activeSources = DB::table('integration.sources')->where('active_status', 'active')->count();
        $failedSources = Schema::hasColumn('integration.sources', 'protocol_health_status')
            ? DB::table('integration.sources')->where('active_status', 'active')->where('protocol_health_status', 'failed')->count()
            : 0;
        $unobservedSources = Schema::hasColumn('integration.sources', 'protocol_health_status')
            ? DB::table('integration.sources')->where('active_status', 'active')->whereIn('protocol_health_status', ['unobserved', 'degraded'])->count()
            : $activeSources;
        $openDeadLetters = Schema::hasTable('raw.dead_letters')
            ? DB::table('raw.dead_letters')->where('status', 'open')->count()
            : 0;
        $openProjectionErrors = Schema::hasTable('integration.event_projection_errors')
            ? DB::table('integration.event_projection_errors')->where('status', 'open')->count()
            : 0;
        $exceptions = $openDeadLetters + $openProjectionErrors;
        $criticalExceptions = max(1, (int) config('admin-health.integration.critical_open_exceptions', 25));

        $status = match (true) {
            $sourceCount === 0 => 'unknown',
            $failedSources > 0 || $exceptions >= $criticalExceptions => 'critical',
            $activeSources === 0 || $unobservedSources > 0 || $exceptions > 0 => 'warning',
            default => 'healthy',
        };

        return [
            'status' => $status,
            'summary' => match ($status) {
                'healthy' => 'Active healthcare sources are observed with no open runtime exceptions.',
                'warning' => 'Healthcare integration readiness requires operator review.',
                'critical' => 'A healthcare source failed protocol health or exception volume crossed policy.',
                default => 'No healthcare source has been configured; runtime state is unknown.',
            },
            'errorCode' => $status === 'healthy' ? null : match ($status) {
                'critical' => 'integration_runtime_critical',
                'warning' => 'integration_runtime_degraded',
                default => 'integration_runtime_unconfigured',
            },
            'details' => compact('sourceCount', 'activeSources', 'failedSources', 'unobservedSources', 'openDeadLetters', 'openProjectionErrors'),
        ];
    }

    /**
     * Bounded, content-free evidence for the draft-only patient pathway
     * producer. This is deliberately a monitor, not an ingestion worker: it
     * does not call a source, advance a cursor, release content, or identify a
     * patient, grant, encounter, pathway, or source event.
     *
     * @return array{status:string, summary:string, errorCode?:string|null, details:array<string, mixed>}
     */
    private function probePatientProjectionPipeline(): array
    {
        $gates = [
            'patientProduct' => (bool) config('hummingbird-patient.enabled', false),
            'patientPathway' => (bool) config('hummingbird-patient.features.pathway', false),
            'pathwayHistoryDrafts' => (bool) config('hummingbird-patient.features.pathway_history_drafts', false),
            'carePathwaysPatient' => (bool) config('care-pathways.patient_enabled', false),
        ];
        $enabledGateCount = collect($gates)->filter()->count();
        $baseDetails = [
            'monitoringEnabled' => $enabledGateCount === count($gates),
            'enabledGateCount' => $enabledGateCount,
            'requiredGateCount' => count($gates),
            'effectivePathwayInstances' => null,
            'observedPathwayInstances' => null,
            'unobservedPathwayInstances' => null,
            'oldestObservationAgeMinutes' => null,
            'newestObservationAgeMinutes' => null,
            'latestDraftProjectionCounts' => [
                'expected' => null,
                'missing' => null,
                'behindSource' => null,
                'current' => null,
                'aging' => null,
                'stale' => null,
                'unknown' => null,
                'unavailable' => null,
            ],
            'recentFailureCounts' => [
                'total' => null,
                'retryable' => null,
                'manualReview' => null,
                'terminal' => null,
            ],
        ];

        if ($enabledGateCount === 0) {
            return [
                'status' => 'healthy',
                'summary' => 'Patient pathway projection monitoring is disabled by the current governance gates.',
                'details' => $baseDetails,
            ];
        }

        if ($enabledGateCount !== count($gates)) {
            return [
                'status' => 'warning',
                'summary' => 'Patient pathway projection monitoring has incomplete governance prerequisites.',
                'errorCode' => 'patient_projection_monitoring_prerequisites_incomplete',
                'details' => $baseDetails,
            ];
        }

        $requiredTables = [
            'patient_experience.encounter_access_grants',
            'patient_experience.pathway_instances',
            'patient_experience.pathway_stage_instances',
            'patient_experience.pathway_stage_status_events',
            'patient_experience.pathway_milestone_instances',
            'patient_experience.pathway_milestone_status_events',
            'patient_experience.encounter_projections',
            'patient_experience.source_projection_failures',
        ];
        if (DB::connection()->getDriverName() !== 'pgsql' || collect($requiredTables)->contains(
            fn (string $table): bool => ! Schema::hasTable($table),
        )) {
            return [
                'status' => 'critical',
                'summary' => 'The enabled patient pathway projection monitor lacks its required schema.',
                'errorCode' => 'patient_projection_monitoring_schema_missing',
                'details' => $baseDetails,
            ];
        }

        $observedAt = CarbonImmutable::now();
        $warningLagMinutes = max(1, (int) config('admin-health.patient_projection.warning_lag_minutes', 30));
        $criticalLagMinutes = max(
            $warningLagMinutes,
            (int) config('admin-health.patient_projection.critical_lag_minutes', 240),
        );
        $failureWindowMinutes = max(1, (int) config('admin-health.patient_projection.failure_window_minutes', 60));
        $criticalFailureCount = max(1, (int) config('admin-health.patient_projection.critical_failure_count', 3));
        $observationSummary = $this->patientPathwayObservationSummary($observedAt);
        $projectionSummary = $this->patientPathwayProjectionSummary($observedAt);
        $failureSummary = $this->patientPathwayFailureSummary($observedAt, $failureWindowMinutes);

        $oldestAge = $this->ageInMinutes($observationSummary->oldest_observed_at ?? null, $observedAt);
        $newestAge = $this->ageInMinutes($observationSummary->newest_observed_at ?? null, $observedAt);
        $effectiveInstances = (int) ($observationSummary->effective_instance_count ?? 0);
        $unobservedInstances = (int) ($observationSummary->unobserved_instance_count ?? 0);
        $missingDrafts = (int) ($projectionSummary->missing_count ?? 0);
        $draftsBehindSource = (int) ($projectionSummary->behind_source_count ?? 0);
        $staleProjections = (int) ($projectionSummary->stale_count ?? 0);
        $recentFailures = (int) ($failureSummary->total_count ?? 0);

        $details = [
            ...$baseDetails,
            'effectivePathwayInstances' => $effectiveInstances,
            'observedPathwayInstances' => (int) ($observationSummary->observed_instance_count ?? 0),
            'unobservedPathwayInstances' => $unobservedInstances,
            'oldestObservationAgeMinutes' => $oldestAge,
            'newestObservationAgeMinutes' => $newestAge,
            'latestDraftProjectionCounts' => [
                'expected' => (int) ($projectionSummary->expected_count ?? 0),
                'missing' => $missingDrafts,
                'behindSource' => $draftsBehindSource,
                'current' => (int) ($projectionSummary->current_count ?? 0),
                'aging' => (int) ($projectionSummary->aging_count ?? 0),
                'stale' => $staleProjections,
                'unknown' => (int) ($projectionSummary->unknown_count ?? 0),
                'unavailable' => (int) ($projectionSummary->unavailable_count ?? 0),
            ],
            'recentFailureCounts' => [
                'total' => $recentFailures,
                'retryable' => (int) ($failureSummary->retryable_count ?? 0),
                'manualReview' => (int) ($failureSummary->manual_review_count ?? 0),
                'terminal' => (int) ($failureSummary->terminal_count ?? 0),
            ],
            'warningLagMinutes' => $warningLagMinutes,
            'criticalLagMinutes' => $criticalLagMinutes,
            'failureWindowMinutes' => $failureWindowMinutes,
            'criticalFailureCount' => $criticalFailureCount,
        ];

        if ($effectiveInstances === 0) {
            return [
                'status' => 'healthy',
                'summary' => 'Patient pathway projection monitoring is ready; no effective pathway instances require a draft.',
                'details' => $details,
            ];
        }

        $criticalLag = $unobservedInstances > 0
            || $missingDrafts > 0
            || $draftsBehindSource > 0
            || $staleProjections > 0
            || ($oldestAge !== null && $oldestAge >= $criticalLagMinutes);
        if ($criticalLag || $recentFailures >= $criticalFailureCount) {
            return [
                'status' => 'critical',
                'summary' => $criticalLag
                    ? 'An enabled patient pathway projection is missing, behind source history, stale, or beyond its critical freshness threshold.'
                    : 'Recent patient pathway projection failures crossed the critical threshold.',
                'errorCode' => $criticalLag
                    ? 'patient_projection_freshness_critical'
                    : 'patient_projection_failures_critical',
                'details' => $details,
            ];
        }

        $warningLag = (int) ($projectionSummary->aging_count ?? 0) > 0
            || ($oldestAge !== null && $oldestAge >= $warningLagMinutes);
        if ($warningLag || $recentFailures > 0) {
            return [
                'status' => 'warning',
                'summary' => $warningLag
                    ? 'An enabled patient pathway projection requires freshness review.'
                    : 'Recent patient pathway projection failures require operator review.',
                'errorCode' => $warningLag
                    ? 'patient_projection_freshness_warning'
                    : 'patient_projection_failures_warning',
                'details' => $details,
            ];
        }

        return [
            'status' => 'healthy',
            'summary' => 'Enabled patient pathway projections have current bounded freshness and no recent failures.',
            'details' => $details,
        ];
    }

    private function patientPathwayObservationSummary(CarbonImmutable $observedAt): object
    {
        $latestEvents = $this->patientPathwayLatestObservations();

        return DB::table('patient_experience.pathway_instances as instances')
            ->join(
                'patient_experience.encounter_access_grants as grants',
                'grants.access_grant_id',
                '=',
                'instances.access_grant_id',
            )
            ->leftJoinSub($latestEvents, 'observations', function ($join): void {
                $join->on('observations.pathway_instance_id', '=', 'instances.pathway_instance_id');
            })
            ->where('grants.status', 'active')
            ->where('grants.valid_from', '<=', $observedAt)
            ->where(function ($window) use ($observedAt): void {
                $window->whereNull('grants.expires_at')->orWhere('grants.expires_at', '>', $observedAt);
            })
            ->selectRaw('count(*) AS effective_instance_count')
            ->selectRaw('count(observations.latest_observed_at) AS observed_instance_count')
            ->selectRaw('count(*) FILTER (WHERE observations.latest_observed_at IS NULL) AS unobserved_instance_count')
            ->selectRaw('min(observations.latest_observed_at) AS oldest_observed_at')
            ->selectRaw('max(observations.latest_observed_at) AS newest_observed_at')
            ->first();
    }

    private function patientPathwayProjectionSummary(CarbonImmutable $observedAt): object
    {
        $producerVersion = (string) config(
            'hummingbird-patient.pathway_history_drafts.producer_version',
            'patient-pathway-history-draft-v1',
        );
        $latest = DB::table('patient_experience.encounter_projections as candidates')
            ->where('candidates.projection_kind', 'pathway')
            ->where('candidates.release_state', 'draft')
            ->where('candidates.source_version', $producerVersion)
            ->selectRaw('candidates.access_grant_id, max(candidates.projection_sequence) AS projection_sequence')
            ->groupBy('candidates.access_grant_id');

        $observedGrants = DB::table('patient_experience.pathway_instances as instances')
            ->joinSub($this->patientPathwayLatestObservations(), 'observations', function ($join): void {
                $join->on('observations.pathway_instance_id', '=', 'instances.pathway_instance_id');
            })
            ->selectRaw('instances.access_grant_id, max(observations.latest_observed_at) AS latest_observed_at')
            ->groupBy('instances.access_grant_id');

        return DB::table('patient_experience.encounter_access_grants as grants')
            ->joinSub($observedGrants, 'observed_grants', function ($join): void {
                $join->on('observed_grants.access_grant_id', '=', 'grants.access_grant_id');
            })
            ->leftJoinSub($latest, 'latest', function ($join): void {
                $join->on('latest.access_grant_id', '=', 'grants.access_grant_id');
            })
            ->leftJoin('patient_experience.encounter_projections as projections', function ($join) use ($producerVersion): void {
                $join->on('projections.access_grant_id', '=', 'latest.access_grant_id')
                    ->on('projections.projection_sequence', '=', 'latest.projection_sequence')
                    ->where('projections.projection_kind', 'pathway')
                    ->where('projections.release_state', 'draft')
                    ->where('projections.source_version', $producerVersion);
            })
            ->where('grants.status', 'active')
            ->where('grants.valid_from', '<=', $observedAt)
            ->where(function ($window) use ($observedAt): void {
                $window->whereNull('grants.expires_at')->orWhere('grants.expires_at', '>', $observedAt);
            })
            ->selectRaw('count(*) AS expected_count')
            ->selectRaw('count(*) FILTER (WHERE projections.encounter_projection_id IS NULL) AS missing_count')
            ->selectRaw('count(*) FILTER (WHERE projections.source_observed_at < observed_grants.latest_observed_at) AS behind_source_count')
            ->selectRaw("count(*) FILTER (WHERE projections.freshness_class = 'current') AS current_count")
            ->selectRaw("count(*) FILTER (WHERE projections.freshness_class = 'aging') AS aging_count")
            ->selectRaw("count(*) FILTER (WHERE projections.freshness_class = 'stale') AS stale_count")
            ->selectRaw("count(*) FILTER (WHERE projections.freshness_class = 'unknown') AS unknown_count")
            ->selectRaw("count(*) FILTER (WHERE projections.freshness_class = 'unavailable') AS unavailable_count")
            ->first();
    }

    private function patientPathwayLatestObservations(): \Illuminate\Database\Query\Builder
    {
        $stageEvents = DB::table('patient_experience.pathway_stage_status_events as events')
            ->join(
                'patient_experience.pathway_stage_instances as instances',
                'instances.pathway_stage_instance_id',
                '=',
                'events.pathway_stage_instance_id',
            )
            ->selectRaw('instances.pathway_instance_id, max(events.source_observed_at) AS observed_at')
            ->groupBy('instances.pathway_instance_id');
        $milestoneEvents = DB::table('patient_experience.pathway_milestone_status_events as events')
            ->join(
                'patient_experience.pathway_milestone_instances as instances',
                'instances.pathway_milestone_instance_id',
                '=',
                'events.pathway_milestone_instance_id',
            )
            ->selectRaw('instances.pathway_instance_id, max(events.source_observed_at) AS observed_at')
            ->groupBy('instances.pathway_instance_id');

        return DB::query()
            ->fromSub($stageEvents->unionAll($milestoneEvents), 'all_events')
            ->selectRaw('pathway_instance_id, max(observed_at) AS latest_observed_at')
            ->groupBy('pathway_instance_id');
    }

    private function patientPathwayFailureSummary(CarbonImmutable $observedAt, int $windowMinutes): object
    {
        $sourceSystemKey = (string) config(
            'hummingbird-patient.pathway_history_drafts.source_system_key',
            'care-pathways.pathway-history-v1',
        );

        return DB::table('patient_experience.source_projection_failures')
            ->where('source_system_key', $sourceSystemKey)
            ->where('projection_kind', 'pathway')
            ->where('occurred_at', '>=', $observedAt->subMinutes($windowMinutes))
            ->selectRaw('count(*) AS total_count')
            ->selectRaw("count(*) FILTER (WHERE retryability = 'retryable') AS retryable_count")
            ->selectRaw("count(*) FILTER (WHERE retryability = 'manual_review') AS manual_review_count")
            ->selectRaw("count(*) FILTER (WHERE retryability = 'terminal') AS terminal_count")
            ->first();
    }

    private function ageInMinutes(mixed $timestamp, CarbonImmutable $observedAt): ?int
    {
        if ($timestamp === null) {
            return null;
        }

        return max(0, CarbonImmutable::parse((string) $timestamp)->diffInMinutes($observedAt));
    }

    private function probeRealtime(): array
    {
        $connection = (string) config('broadcasting.default', 'null');
        $driver = (string) config("broadcasting.connections.{$connection}.driver", $connection);
        if (in_array($driver, ['null', 'log'], true)) {
            return [
                'status' => 'disabled',
                'summary' => 'Realtime broadcasting is disabled for this deployment.',
                'details' => ['connection' => $connection, 'driver' => $driver, 'configurationComplete' => false],
            ];
        }

        $configurationComplete = $driver !== 'reverb' || (
            filled(config('broadcasting.connections.reverb.app_id'))
            && filled(config('broadcasting.connections.reverb.key'))
            && filled(config('broadcasting.connections.reverb.options.host'))
        );

        return [
            'status' => $configurationComplete ? 'unknown' : 'critical',
            'summary' => $configurationComplete
                ? 'Realtime broadcasting is configured; runtime heartbeat evidence is not connected.'
                : 'Realtime broadcasting configuration is incomplete.',
            'errorCode' => $configurationComplete ? 'realtime_heartbeat_missing' : 'realtime_configuration_incomplete',
            'details' => ['connection' => $connection, 'driver' => $driver, 'configurationComplete' => $configurationComplete],
        ];
    }

    private function probeObjectStorage(): array
    {
        $disk = (string) config('filesystems.default', 'local');
        $driver = (string) config("filesystems.disks.{$disk}.driver", 'unknown');
        if ($driver !== 'local') {
            $configured = match ($driver) {
                's3' => filled(config("filesystems.disks.{$disk}.bucket")) && filled(config("filesystems.disks.{$disk}.region")),
                default => true,
            };

            return [
                'status' => $configured ? 'unknown' : 'critical',
                'summary' => $configured
                    ? 'External object storage is configured; a non-mutating runtime heartbeat is not connected.'
                    : 'External object storage configuration is incomplete.',
                'errorCode' => $configured ? 'object_storage_heartbeat_missing' : 'object_storage_configuration_incomplete',
                'details' => ['disk' => $disk, 'driver' => $driver, 'configurationComplete' => $configured, 'writable' => null],
            ];
        }

        $root = (string) config("filesystems.disks.{$disk}.root", storage_path('app/private'));
        $probePath = is_dir($root) ? $root : dirname($root);
        $writable = is_dir($probePath) && is_writable($probePath);

        return [
            'status' => $writable ? 'healthy' : 'critical',
            'summary' => $writable ? 'The local private storage boundary is writable.' : 'The local private storage boundary is not writable.',
            'errorCode' => $writable ? null : 'object_storage_not_writable',
            'details' => ['disk' => $disk, 'driver' => $driver, 'configurationComplete' => true, 'writable' => $writable],
        ];
    }

    private function probeDiskCapacity(): array
    {
        $total = @disk_total_space(storage_path());
        $free = @disk_free_space(storage_path());
        if (! is_float($total) || ! is_float($free) || $total <= 0) {
            return [
                'status' => 'unknown',
                'summary' => 'Disk capacity telemetry is unavailable.',
                'errorCode' => 'disk_telemetry_unavailable',
                'details' => ['freePercent' => null, 'freeBytes' => null, 'totalBytes' => null],
            ];
        }

        $freePercent = round(($free / $total) * 100, 1);
        $warning = (int) config('admin-health.disk.warning_free_percent', 20);
        $critical = (int) config('admin-health.disk.critical_free_percent', 10);
        $status = match (true) {
            $freePercent <= $critical => 'critical',
            $freePercent <= $warning => 'warning',
            default => 'healthy',
        };

        return [
            'status' => $status,
            'summary' => match ($status) {
                'critical' => 'Local storage has crossed the critical free-capacity threshold.',
                'warning' => 'Local storage has crossed the warning free-capacity threshold.',
                default => 'Local storage capacity is above the configured warning threshold.',
            },
            'errorCode' => $status === 'healthy' ? null : 'disk_capacity_low',
            'details' => ['freePercent' => $freePercent, 'freeBytes' => (int) $free, 'totalBytes' => (int) $total],
        ];
    }

    private function probeBackups(): array
    {
        $path = config('admin-health.backup.evidence_path');
        if (! is_string($path) || trim($path) === '') {
            return [
                'status' => 'unknown',
                'summary' => 'No deployment-managed backup verification marker is configured.',
                'errorCode' => 'backup_evidence_unconfigured',
                'details' => ['evidenceConfigured' => false, 'ageHours' => null],
            ];
        }
        $modifiedAt = @filemtime($path);
        if ($modifiedAt === false) {
            return [
                'status' => 'critical',
                'summary' => 'The configured backup verification marker is unavailable.',
                'errorCode' => 'backup_evidence_unavailable',
                'details' => ['evidenceConfigured' => true, 'ageHours' => null],
            ];
        }

        $ageHours = round(max(0, now()->timestamp - $modifiedAt) / 3600, 1);
        $warning = (int) config('admin-health.backup.warning_age_hours', 26);
        $critical = max($warning, (int) config('admin-health.backup.critical_age_hours', 48));
        $status = match (true) {
            $ageHours >= $critical => 'critical',
            $ageHours >= $warning => 'warning',
            default => 'healthy',
        };

        return [
            'status' => $status,
            'summary' => match ($status) {
                'critical' => 'Backup verification evidence is critically stale.',
                'warning' => 'Backup verification evidence is approaching its maximum age.',
                default => 'Backup verification evidence is within the configured age policy.',
            },
            'errorCode' => $status === 'healthy' ? null : 'backup_evidence_stale',
            'details' => ['evidenceConfigured' => true, 'ageHours' => $ageHours],
        ];
    }

    private function probeTlsCertificate(): array
    {
        $path = config('admin-health.tls.certificate_path');
        if (! is_string($path) || trim($path) === '') {
            return [
                'status' => 'unknown',
                'summary' => 'No public TLS certificate path is configured for bounded inspection.',
                'errorCode' => 'tls_evidence_unconfigured',
                'details' => ['certificateConfigured' => false, 'daysRemaining' => null],
            ];
        }
        $certificate = @file_get_contents($path);
        $parsed = is_string($certificate) ? @openssl_x509_parse($certificate) : false;
        $expiresAt = is_array($parsed) ? ($parsed['validTo_time_t'] ?? null) : null;
        if (! is_int($expiresAt)) {
            return [
                'status' => 'critical',
                'summary' => 'The configured public TLS certificate could not be parsed.',
                'errorCode' => 'tls_certificate_invalid',
                'details' => ['certificateConfigured' => true, 'daysRemaining' => null],
            ];
        }

        $daysRemaining = (int) floor(($expiresAt - now()->timestamp) / 86400);
        $warning = (int) config('admin-health.tls.warning_days', 30);
        $critical = (int) config('admin-health.tls.critical_days', 14);
        $status = match (true) {
            $daysRemaining <= $critical => 'critical',
            $daysRemaining <= $warning => 'warning',
            default => 'healthy',
        };

        return [
            'status' => $status,
            'summary' => match ($status) {
                'critical' => 'The TLS certificate is expired or inside the critical renewal window.',
                'warning' => 'The TLS certificate is inside the warning renewal window.',
                default => 'The TLS certificate is outside the configured renewal window.',
            },
            'errorCode' => $status === 'healthy' ? null : 'tls_certificate_expiring',
            'details' => ['certificateConfigured' => true, 'daysRemaining' => $daysRemaining],
        ];
    }

    private function probeArena(): array
    {
        if (! (bool) config('services.arena.enabled')) {
            return [
                'status' => 'disabled',
                'summary' => 'Arena is disabled by deployment policy.',
                'details' => ['enabled' => false, 'lastSignalAgeMinutes' => null],
            ];
        }

        $timestamps = collect();
        foreach (['arena.conformance_signals', 'arena.performance_signals'] as $table) {
            if (Schema::hasTable($table)) {
                $timestamps->push(DB::table($table)->max('computed_at'));
            }
        }
        $latest = $timestamps->filter()
            ->map(fn (mixed $value) => CarbonImmutable::parse($value))
            ->sortByDesc(fn (CarbonImmutable $value): int => $value->getTimestamp())
            ->first();
        if (! $latest instanceof CarbonImmutable) {
            return [
                'status' => 'unknown',
                'summary' => 'Arena is enabled, but no process-intelligence signal has been observed.',
                'errorCode' => 'arena_signal_unobserved',
                'details' => ['enabled' => true, 'lastSignalAgeMinutes' => null],
            ];
        }

        $age = max(0, (int) $latest->diffInMinutes(now()));
        $warning = (int) config('admin-health.arena_signal_warning_minutes', 90);

        return [
            'status' => $age >= $warning ? 'warning' : 'healthy',
            'summary' => $age >= $warning ? 'Arena process-intelligence signals are stale.' : 'Arena has produced a recent process-intelligence signal.',
            'errorCode' => $age >= $warning ? 'arena_signal_stale' : null,
            'details' => ['enabled' => true, 'lastSignalAgeMinutes' => $age],
        ];
    }

    private function probeEddy(): array
    {
        if (! (bool) config('services.eddy.enabled')) {
            return [
                'status' => 'disabled',
                'summary' => 'Eddy is disabled by deployment policy.',
                'details' => ['enabled' => false, 'configurationComplete' => false],
            ];
        }

        $url = (string) config('services.eddy.url');
        $configurationComplete = filter_var($url, FILTER_VALIDATE_URL) !== false
            && filled(config('services.eddy.shared_secret'));

        return [
            'status' => $configurationComplete ? 'unknown' : 'critical',
            'summary' => $configurationComplete
                ? 'Eddy is configured; a PHI-safe runtime heartbeat is not connected.'
                : 'Eddy is enabled but its server-to-server configuration is incomplete.',
            'errorCode' => $configurationComplete ? 'eddy_heartbeat_missing' : 'eddy_configuration_incomplete',
            'details' => ['enabled' => true, 'configurationComplete' => $configurationComplete],
        ];
    }

    /** @param array{label:string, category:string, required:bool, owner:string, runbook:string} $component */
    private function missingObservation(string $key, array $component): array
    {
        return [
            'key' => $key,
            'label' => $component['label'],
            'category' => $component['category'],
            'status' => 'unknown',
            'recordedStatus' => null,
            'summary' => 'No health observation has been recorded.',
            'errorCode' => 'observation_missing',
            'required' => (bool) $component['required'],
            'owner' => $component['owner'],
            'runbookRef' => $this->runbookRef($component['runbook']),
            'runbookUrl' => $this->runbookUrl($component['runbook']),
            'observedAt' => null,
            'freshUntil' => null,
            'durationMs' => null,
            'origin' => null,
            'stale' => false,
            'details' => [],
            'href' => '/admin/system-health/'.$key,
        ];
    }

    private function runbookRef(string $anchor): string
    {
        return 'admin-system-health#'.$anchor;
    }

    private function runbookUrl(string $anchor): ?string
    {
        $base = (string) config('admin-health.runbook_base_url', '');

        return $base !== '' ? $base.'/admin-system-health#'.$anchor : null;
    }
}
