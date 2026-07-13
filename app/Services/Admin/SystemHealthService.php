<?php

namespace App\Services\Admin;

use App\Models\Governance\SystemHealthObservation;
use App\Models\User;
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
        }

        return $this->snapshot(batchUuid: $batchUuid);
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

        $now = CarbonImmutable::now();
        $observations = collect($this->catalog())->map(function (array $component, string $key) use ($latest, $now): array {
            /** @var SystemHealthObservation|null $row */
            $row = $latest->get($key);
            if ($row === null) {
                return $this->missingObservation($key, $component);
            }

            $expired = $row->freshness_expires_at->isBefore($now);
            $status = $expired ? 'unknown' : $row->status;

            return [
                'key' => $key,
                'label' => $component['label'],
                'category' => $component['category'],
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
