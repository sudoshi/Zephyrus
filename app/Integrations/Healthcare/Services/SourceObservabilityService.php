<?php

namespace App\Integrations\Healthcare\Services;

use App\Observability\MetricRecorder;
use App\Security\ClinicalPayloads\ClinicalContentGuard;
use App\Services\Alerting\OperationalAlert;
use App\Services\Alerting\OperationalAlertDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Persists PHI-free, source-scoped operational truth.
 *
 * This collector never calls a partner system or advances a cursor. It derives
 * bounded measures from already-authoritative receipt, run, projection, and
 * watermark ledgers. Missing evidence remains unknown.
 */
final class SourceObservabilityService
{
    /** @var array<string, array{column:string, comparator:string, unit:string}> */
    private const METRIC_CONTRACTS = [
        'availability' => ['column' => 'availability_percent', 'comparator' => 'gte', 'unit' => 'percent'],
        'freshness' => ['column' => 'freshness_minutes', 'comparator' => 'lte', 'unit' => 'minutes'],
        'completeness' => ['column' => 'completeness_percent', 'comparator' => 'gte', 'unit' => 'percent'],
        'latency' => ['column' => 'latency_ms', 'comparator' => 'lte', 'unit' => 'milliseconds'],
        'error_rate' => ['column' => 'error_rate_percent', 'comparator' => 'lte', 'unit' => 'percent'],
        'acknowledgement' => ['column' => 'acknowledgement_seconds', 'comparator' => 'lte', 'unit' => 'seconds'],
        'reconciliation_variance' => ['column' => 'reconciliation_variance_percent', 'comparator' => 'lte', 'unit' => 'percent'],
    ];

    public function __construct(
        private readonly SourceSloDefinitionService $sloDefinitions,
        private readonly SourceMaintenanceWindowService $maintenanceWindows,
        private readonly ClinicalContentGuard $contentGuard,
        private readonly OperationalAlertDispatcher $alertDispatcher,
        private readonly MetricRecorder $metrics,
    ) {}

    /**
     * @param  list<int>  $sourceIds
     * @return array{batchUuid:string, observedAtIso:string, attempted:int, recorded:int, failed:int, statuses:array<string,int>, failures:list<array{sourceId:int,errorCode:string}>}
     */
    public function collect(
        string $origin = 'scheduled',
        array $sourceIds = [],
        int $limit = 100,
        ?CarbonImmutable $observedAt = null,
        ?string $batchUuid = null,
    ): array {
        $this->assertOrigin($origin);
        $limit = max(1, min(1000, $limit));
        $observedAt ??= CarbonImmutable::now();
        $batchUuid ??= (string) Str::uuid7();
        $this->assertUuid($batchUuid, 'source_health_batch_uuid_invalid');

        $query = DB::table('integration.sources')
            ->where('lifecycle_state', '<>', 'retired')
            ->orderBy('source_id');
        if ($sourceIds !== []) {
            $sourceIds = array_values(array_unique(array_map('intval', $sourceIds)));
            $query->whereIn('source_id', $sourceIds);
        }
        $ids = $query->limit($limit)->pluck('source_id')->map(fn (mixed $id): int => (int) $id)->all();

        $statuses = [];
        $failures = [];
        foreach ($ids as $sourceId) {
            try {
                $observation = $this->observe(
                    $sourceId,
                    $observedAt,
                    $origin,
                    $batchUuid,
                    (string) Str::uuid7(),
                    null,
                );
                $status = (string) $observation['status'];
                $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            } catch (Throwable $exception) {
                $failures[] = [
                    'sourceId' => $sourceId,
                    'errorCode' => $this->stableErrorCode($exception),
                ];
            }
        }

        return [
            'batchUuid' => $batchUuid,
            'observedAtIso' => $observedAt->toIso8601String(),
            'attempted' => count($ids),
            'recorded' => count($ids) - count($failures),
            'failed' => count($failures),
            'statuses' => $statuses,
            'failures' => $failures,
        ];
    }

    /** @return array<string, mixed> */
    public function observe(
        int $sourceId,
        CarbonImmutable $observedAt,
        string $origin = 'scheduled',
        ?string $batchUuid = null,
        ?string $correlationUuid = null,
        ?int $actorUserId = null,
    ): array {
        $this->assertOrigin($origin);
        $batchUuid ??= (string) Str::uuid7();
        $correlationUuid ??= (string) Str::uuid7();
        $this->assertUuid($batchUuid, 'source_health_batch_uuid_invalid');
        $this->assertUuid($correlationUuid, 'source_health_correlation_uuid_invalid');

        return DB::transaction(function () use (
            $sourceId,
            $observedAt,
            $origin,
            $batchUuid,
            $correlationUuid,
            $actorUserId,
        ): array {
            $source = DB::table('integration.sources')
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->first();
            if ($source === null) {
                throw new RuntimeException('source_not_found');
            }
            if ($source->current_configuration_version_id === null) {
                throw new RuntimeException('source_configuration_version_missing');
            }
            $onboarding = DB::table('integration.source_onboarding_versions')
                ->where('source_id', $sourceId)
                ->orderByDesc('version_number')
                ->first();
            if ($onboarding === null) {
                throw new RuntimeException('source_onboarding_version_missing');
            }
            $definition = $this->sloDefinitions->forOnboarding($onboarding);
            $windowStartedAt = $observedAt->subMinutes((int) $definition->evaluation_window_minutes);
            $maintenance = $this->maintenanceWindows->at($onboarding, $observedAt);
            $metrics = $this->metrics($sourceId, $definition, $windowStartedAt, $observedAt);
            $queueState = $this->queueState($sourceId, $observedAt);
            $runtimeState = $this->runtimeState($source, $onboarding, $maintenance, $sourceId, $windowStartedAt, $observedAt);
            $protocolStatus = $this->protocolStatus($source);
            $summary = collect($metrics)->countBy('status')->all();
            foreach (['met', 'breached', 'unknown', 'not_applicable'] as $status) {
                $summary[$status] = (int) ($summary[$status] ?? 0);
            }
            ksort($summary);
            $status = $this->observationStatus($source, $protocolStatus, $metrics, $maintenance['active']);
            $freshnessExpiresAt = $observedAt->addSeconds(max(
                60,
                (int) config('integrations.observability.observation_fresh_for_seconds', 180),
            ));
            $evidence = [
                'definitionSha256' => (string) $definition->definition_sha256,
                'maintenanceFingerprint' => $maintenance['fingerprint'],
                'metrics' => array_map(fn (array $metric): array => collect($metric)->except('details')->all(), $metrics),
                'queueState' => $queueState,
                'runtimeState' => $runtimeState,
                'sourceConfigurationVersionId' => (int) $source->current_configuration_version_id,
                'sourceId' => $sourceId,
                'sourceOnboardingVersionId' => (int) $onboarding->source_onboarding_version_id,
                'windowEndedAt' => $observedAt->toIso8601String(),
                'windowStartedAt' => $windowStartedAt->toIso8601String(),
            ];
            $evidenceSha256 = $this->hash($evidence);
            $observationRow = [
                'observation_uuid' => (string) Str::uuid7(),
                'batch_uuid' => $batchUuid,
                'correlation_uuid' => $correlationUuid,
                'source_id' => $sourceId,
                'source_configuration_version_id' => (int) $source->current_configuration_version_id,
                'source_onboarding_version_id' => (int) $onboarding->source_onboarding_version_id,
                'source_slo_definition_id' => (int) $definition->source_slo_definition_id,
                'observation_status' => $status,
                'protocol_status' => $protocolStatus,
                'protocol_error_code' => $this->errorCode($source->protocol_health_error ?? null),
                'maintenance_active' => (bool) $maintenance['active'],
                'maintenance_window_fingerprint' => $maintenance['fingerprint'],
                'window_started_at' => $windowStartedAt,
                'window_ended_at' => $observedAt,
                'observed_at' => $observedAt,
                'freshness_expires_at' => $freshnessExpiresAt,
                'collector_origin' => $origin,
                'recorded_by_user_id' => $actorUserId,
                'summary_counts' => json_encode($summary, JSON_THROW_ON_ERROR),
                'queue_state' => json_encode($queueState, JSON_THROW_ON_ERROR),
                'runtime_state' => json_encode($runtimeState, JSON_THROW_ON_ERROR),
                'evidence_sha256' => $evidenceSha256,
                'created_at' => $observedAt,
            ];
            $this->contentGuard->assertSafe($observationRow, 'source_health_observation_content_rejected');
            $observationId = (int) DB::table('integration.health_observations')->insertGetId(
                $observationRow,
                'health_observation_id',
            );

            $persistedMetrics = [];
            foreach ($metrics as $metric) {
                $metricRow = [
                    'health_observation_id' => $observationId,
                    'metric_key' => $metric['key'],
                    'metric_status' => $metric['status'],
                    'measured_value' => $metric['measuredValue'],
                    'target_value' => $metric['targetValue'],
                    'comparator' => $metric['comparator'],
                    'unit' => $metric['unit'],
                    'sample_count' => $metric['sampleCount'],
                    'evidence_code' => $metric['evidenceCode'],
                    'details' => json_encode($metric['details'], JSON_THROW_ON_ERROR),
                    'created_at' => $observedAt,
                ];
                $this->contentGuard->assertSafe($metricRow, 'source_health_metric_content_rejected');
                $metricId = (int) DB::table('integration.health_observation_metrics')->insertGetId(
                    $metricRow,
                    'health_observation_metric_id',
                );
                $persistedMetrics[] = [...$metric, 'metricId' => $metricId];
            }

            $this->syncBreaches(
                $sourceId,
                (string) $source->source_key,
                (int) $definition->source_slo_definition_id,
                $observationId,
                $persistedMetrics,
                (bool) $maintenance['active'],
                $observedAt,
            );
            $this->projectCurrent(
                $sourceId,
                $observationId,
                (int) $definition->source_slo_definition_id,
                $status,
                (bool) $maintenance['active'],
                $observedAt,
                $freshnessExpiresAt,
                $summary,
                $queueState,
                $runtimeState,
            );

            // INT-OBS 4: emit a PHI-safe metric on the safe-attribute contract,
            // carrying the correlation UUID from receipt through this projection.
            // Default OFF; a guarded no-op unless observability.enabled is on.
            $this->metrics->counter('zephyrus.integration.source_health.observation', 1, [
                'zephyrus.source.id' => $sourceId,
                'zephyrus.observation.status' => $status,
                'zephyrus.protocol.status' => $protocolStatus,
                'zephyrus.slo.breached' => (int) ($summary['breached'] ?? 0),
                'zephyrus.correlation.uuid' => $correlationUuid,
                'zephyrus.batch.uuid' => $batchUuid,
            ]);

            return [
                'observationId' => $observationId,
                'observationUuid' => $observationRow['observation_uuid'],
                'batchUuid' => $batchUuid,
                'correlationUuid' => $correlationUuid,
                'sourceId' => $sourceId,
                'sloDefinitionId' => (int) $definition->source_slo_definition_id,
                'sloDefinitionStatus' => (string) $definition->definition_status,
                'status' => $status,
                'protocolStatus' => $protocolStatus,
                'maintenanceActive' => (bool) $maintenance['active'],
                'observedAtIso' => $observedAt->toIso8601String(),
                'freshUntilIso' => $freshnessExpiresAt->toIso8601String(),
                'summary' => $summary,
                'metrics' => array_map(fn (array $metric): array => collect($metric)->except('details')->all(), $persistedMetrics),
                'queueState' => $queueState,
                'runtimeState' => $runtimeState,
                'evidenceSha256' => $evidenceSha256,
            ];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function snapshot(int $sourceId, int $historyLimit = 24): array
    {
        $historyLimit = max(1, min(168, $historyLimit));
        $current = DB::table('integration.source_health_current')
            ->where('source_id', $sourceId)
            ->first();
        $history = DB::table('integration.health_observations')
            ->where('source_id', $sourceId)
            ->orderByDesc('health_observation_id')
            ->limit($historyLimit)
            ->get()
            ->map(fn (object $row): array => $this->observationPayload($row))
            ->all();
        $openBreaches = $this->openBreaches($sourceId)->map(fn (object $row): array => [
            'breachId' => (int) $row->slo_breach_id,
            'breachUuid' => (string) $row->breach_uuid,
            'metricKey' => (string) $row->metric_key,
            'status' => (string) $row->status_after,
            'notificationSuppressed' => (bool) $row->notification_suppressed,
            'openedAtIso' => CarbonImmutable::parse($row->opened_at)->toIso8601String(),
            'lastObservedAtIso' => CarbonImmutable::parse($row->occurred_at)->toIso8601String(),
            'acknowledged' => $this->breachHasEvent((int) $row->slo_breach_id, 'acknowledged'),
            'escalated' => $this->breachHasEvent((int) $row->slo_breach_id, 'escalated'),
            'incidentLinked' => $this->breachHasEvent((int) $row->slo_breach_id, 'incident_linked'),
            'reviewed' => $this->breachHasEvent((int) $row->slo_breach_id, 'reviewed'),
            'events' => $this->breachEventTrail((int) $row->slo_breach_id),
        ])->values()->all();

        return [
            'sourceId' => $sourceId,
            'current' => $current !== null ? [
                ...$this->observationPayload(
                    DB::table('integration.health_observations')
                        ->where('health_observation_id', $current->health_observation_id)
                        ->firstOrFail(),
                ),
                'stale' => CarbonImmutable::parse($current->freshness_expires_at)->isPast(),
            ] : null,
            'history' => $history,
            'openBreaches' => $openBreaches,
            'contract' => [
                'appendOnly' => true,
                'externalCallsAllowed' => false,
                'missingEvidenceStatus' => 'unknown',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function metrics(
        int $sourceId,
        object $definition,
        CarbonImmutable $windowStartedAt,
        CarbonImmutable $observedAt,
    ): array {
        $runTotals = DB::table('raw.ingest_runs')
            ->where('source_id', $sourceId)
            ->where('run_type', '<>', 'protocol_health')
            ->whereBetween('started_at', [$windowStartedAt, $observedAt])
            ->selectRaw("count(*) FILTER (WHERE status IN ('completed', 'failed')) AS terminal_runs")
            ->selectRaw("count(*) FILTER (WHERE status = 'completed') AS completed_runs")
            ->selectRaw('coalesce(sum(messages_received), 0) AS messages_received')
            ->selectRaw('coalesce(sum(messages_succeeded), 0) AS messages_succeeded')
            ->selectRaw('coalesce(sum(messages_failed), 0) AS messages_failed')
            ->selectRaw('coalesce(sum(messages_skipped), 0) AS messages_skipped')
            ->first();
        $terminalRuns = (int) ($runTotals->terminal_runs ?? 0);
        $completedRuns = (int) ($runTotals->completed_runs ?? 0);
        $messagesReceived = (int) ($runTotals->messages_received ?? 0);
        $messagesSucceeded = (int) ($runTotals->messages_succeeded ?? 0);
        $messagesFailed = (int) ($runTotals->messages_failed ?? 0);
        $messagesSkipped = (int) ($runTotals->messages_skipped ?? 0);

        $availability = $terminalRuns > 0 ? ($completedRuns / $terminalRuns) * 100 : null;
        $latestMessageAt = DB::table('raw.inbound_messages')
            ->where('source_id', $sourceId)
            ->where('received_at', '<=', $observedAt)
            ->max('received_at');
        $latestSuccessAt = DB::table('integration.connector_watermarks')
            ->where('source_id', $sourceId)
            ->where('last_success_at', '<=', $observedAt)
            ->max('last_success_at');
        $latestDataAt = collect([$latestMessageAt, $latestSuccessAt])
            ->filter()
            ->map(fn (mixed $value): CarbonImmutable => CarbonImmutable::parse($value))
            ->sortByDesc(fn (CarbonImmutable $value): int => $value->getTimestamp())
            ->first();
        $freshnessMinutes = $latestDataAt instanceof CarbonImmutable
            ? max(0.0, ($observedAt->getTimestamp() - $latestDataAt->getTimestamp()) / 60)
            : null;
        $accountedMessages = min($messagesReceived, $messagesSucceeded + $messagesSkipped);
        $completeness = $messagesReceived > 0 ? ($accountedMessages / $messagesReceived) * 100 : null;
        $latency = DB::selectOne(
            <<<'SQL'
                SELECT count(*) AS sample_count,
                       percentile_cont(0.95) WITHIN GROUP (
                           ORDER BY extract(epoch FROM (projected_at - received_at)) * 1000
                       ) AS p95_ms
                FROM integration.canonical_events
                WHERE source_id = ?
                  AND received_at BETWEEN ? AND ?
                  AND projected_at IS NOT NULL
                  AND projected_at >= received_at
                SQL,
            [$sourceId, $windowStartedAt, $observedAt],
        );
        $latencySamples = (int) ($latency->sample_count ?? 0);
        $latencyP95 = $latencySamples > 0 ? (float) $latency->p95_ms : null;
        $errorRate = $messagesReceived > 0
            ? ($messagesFailed / $messagesReceived) * 100
            : ($terminalRuns > 0 ? (($terminalRuns - $completedRuns) / $terminalRuns) * 100 : null);
        $errorEvidence = $messagesReceived > 0 ? 'message_failure_ratio' : 'terminal_run_failure_ratio';

        return [
            $this->metric($definition, 'availability', $availability, $terminalRuns, $terminalRuns > 0 ? 'terminal_run_availability' : 'availability_evidence_missing', [
                'completedRuns' => $completedRuns,
                'terminalRuns' => $terminalRuns,
            ]),
            $this->metric($definition, 'freshness', $freshnessMinutes, $latestDataAt ? 1 : 0, $latestDataAt ? 'latest_data_receipt_age' : 'freshness_evidence_missing', [
                'lastDataObservedAtIso' => $latestDataAt?->toIso8601String(),
            ]),
            $this->metric($definition, 'completeness', $completeness, $messagesReceived, $messagesReceived > 0 ? 'message_processing_accountability' : 'completeness_evidence_missing', [
                'accountedMessages' => $accountedMessages,
                'failedMessages' => $messagesFailed,
                'receivedMessages' => $messagesReceived,
            ]),
            $this->metric($definition, 'latency', $latencyP95, $latencySamples, $latencySamples > 0 ? 'receipt_to_projection_p95' : 'latency_evidence_missing', [
                'percentile' => 95,
            ]),
            $this->metric($definition, 'error_rate', $errorRate, $messagesReceived > 0 ? $messagesReceived : $terminalRuns, $terminalRuns > 0 ? $errorEvidence : 'error_rate_evidence_missing', [
                'failedMessages' => $messagesFailed,
                'terminalRuns' => $terminalRuns,
            ]),
            $this->metric($definition, 'acknowledgement', null, 0, 'acknowledgement_ledger_unavailable', [
                'authorityAvailable' => false,
            ]),
            $this->metric($definition, 'reconciliation_variance', null, 0, 'reconciliation_ledger_unavailable', [
                'authorityAvailable' => false,
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function metric(
        object $definition,
        string $key,
        ?float $measuredValue,
        int $sampleCount,
        string $evidenceCode,
        array $details,
    ): array {
        $contract = self::METRIC_CONTRACTS[$key];
        $target = $definition->{$contract['column']} !== null
            ? (float) $definition->{$contract['column']}
            : null;
        $status = 'unknown';
        if ($target === null) {
            $evidenceCode = 'slo_target_missing';
        } elseif ($measuredValue !== null) {
            $status = match ($contract['comparator']) {
                'gte' => $measuredValue >= $target ? 'met' : 'breached',
                'lte' => $measuredValue <= $target ? 'met' : 'breached',
            };
        }

        return [
            'key' => $key,
            'status' => $status,
            'measuredValue' => $measuredValue !== null ? round($measuredValue, 6) : null,
            'targetValue' => $target,
            'comparator' => $contract['comparator'],
            'unit' => $contract['unit'],
            'sampleCount' => max(0, $sampleCount),
            'evidenceCode' => $evidenceCode,
            'details' => $details,
        ];
    }

    /** @return array<string, mixed> */
    private function queueState(int $sourceId, CarbonImmutable $observedAt): array
    {
        $activeRuns = DB::table('raw.ingest_runs')
            ->where('source_id', $sourceId)
            ->whereIn('status', ['queued', 'running', 'retrying']);
        $sourceDepth = (clone $activeRuns)->count();
        $retryingRuns = (clone $activeRuns)->where('status', 'retrying')->count();
        $oldest = (clone $activeRuns)->min('created_at');
        $sourceOldestAge = $oldest !== null
            ? max(0, $observedAt->getTimestamp() - CarbonImmutable::parse($oldest)->getTimestamp())
            : null;
        $warningDepth = max(1, (int) config('integrations.observability.queue.warning_depth', 25));
        $criticalDepth = max($warningDepth, (int) config('integrations.observability.queue.critical_depth', 100));
        $warningAge = max(30, (int) config('integrations.observability.queue.warning_age_seconds', 120));
        $criticalAge = max($warningAge, (int) config('integrations.observability.queue.critical_age_seconds', 600));
        $backpressure = match (true) {
            $sourceDepth >= $criticalDepth || ($sourceOldestAge !== null && $sourceOldestAge >= $criticalAge) => 'critical',
            $sourceDepth >= $warningDepth || ($sourceOldestAge !== null && $sourceOldestAge >= $warningAge) => 'warning',
            default => 'normal',
        };
        $globalDepth = null;
        $globalOldestAge = null;
        if (Schema::hasTable('jobs')) {
            $globalDepth = DB::table('jobs')->where('queue', 'integrations')->count();
            $globalOldest = DB::table('jobs')->where('queue', 'integrations')->min('created_at');
            $globalOldestAge = $globalOldest !== null
                ? max(0, $observedAt->getTimestamp() - (int) $globalOldest)
                : null;
        }

        return [
            'backpressureStatus' => $backpressure,
            'globalIntegrationQueueDepth' => $globalDepth,
            'globalOldestJobAgeSeconds' => $globalOldestAge,
            'retryingRuns' => (int) $retryingRuns,
            'sourceActiveRunDepth' => $sourceDepth,
            'sourceOldestRunAgeSeconds' => $sourceOldestAge,
        ];
    }

    /** @param array<string, mixed> $maintenance
     * @return array<string, mixed>
     */
    private function runtimeState(
        object $source,
        object $onboarding,
        array $maintenance,
        int $sourceId,
        CarbonImmutable $windowStartedAt,
        CarbonImmutable $observedAt,
    ): array {
        return [
            'circuitBreaker' => $this->circuitBreakerState($sourceId, $observedAt),
            'maintenance' => [
                'active' => (bool) $maintenance['active'],
                'configured' => (bool) $maintenance['configured'],
                'endsAtIso' => $maintenance['endsAtIso'],
                'startsAtIso' => $maintenance['startsAtIso'],
                'timezone' => $maintenance['timezone'],
            ],
            'rateLimit' => $this->rateLimitState($sourceId, $observedAt),
            'retryBudget' => $this->retryBudgetState($sourceId, $windowStartedAt, $observedAt),
            'supportEntitlement' => (string) ($onboarding->support_entitlement ?? 'unknown'),
            'sourceLifecycleState' => (string) ($source->lifecycle_state ?? 'draft'),
        ];
    }

    /**
     * Circuit-breaker state derived from recorded transitions first, then from
     * the recent observation history as a fallback (consecutive failed/degraded
     * observations count as a soft trip). No transition evidence => 'closed'.
     *
     * @return array<string, mixed>
     */
    private function circuitBreakerState(int $sourceId, CarbonImmutable $observedAt): array
    {
        $latest = $this->latestPressure($sourceId, 'circuit_breaker');
        if ($latest !== null) {
            $state = match ((string) $latest->pressure_state) {
                'open' => 'open',
                'half_open' => 'half_open',
                default => 'closed',
            };

            return [
                'state' => $state,
                'consecutiveFailures' => $latest->consecutive_failures !== null ? (int) $latest->consecutive_failures : null,
                'lastTransitionAtIso' => CarbonImmutable::parse($latest->observed_at)->toIso8601String(),
                'evidenceCode' => 'recorded_circuit_transition',
            ];
        }

        $tripThreshold = max(2, (int) config('integrations.observability.circuit_breaker_trip_failures', 5));
        $recent = DB::table('integration.health_observations')
            ->where('source_id', $sourceId)
            ->where('observed_at', '<=', $observedAt)
            ->orderByDesc('health_observation_id')
            ->limit($tripThreshold)
            ->pluck('observation_status')
            ->all();
        $consecutive = 0;
        foreach ($recent as $status) {
            if (in_array((string) $status, ['failed', 'degraded'], true)) {
                $consecutive++;

                continue;
            }
            break;
        }

        return [
            'state' => $consecutive >= $tripThreshold ? 'open' : 'closed',
            'consecutiveFailures' => $consecutive,
            'lastTransitionAtIso' => null,
            'evidenceCode' => $recent === [] ? 'no_observation_history' : 'derived_from_observation_history',
        ];
    }

    /**
     * Rate-limit/backoff state derived from the most recent 429/Retry-After
     * evidence. A throttle whose Retry-After window has elapsed reads as
     * 'recovering'; no evidence reads as 'normal'.
     *
     * @return array<string, mixed>
     */
    private function rateLimitState(int $sourceId, CarbonImmutable $observedAt): array
    {
        $latest = $this->latestPressure($sourceId, 'rate_limit');
        if ($latest === null) {
            return ['state' => 'normal', 'retryAfterSeconds' => null, 'lastThrottledAtIso' => null, 'evidenceCode' => 'no_rate_limit_evidence'];
        }
        if ((string) $latest->pressure_state !== 'throttled') {
            return ['state' => 'normal', 'retryAfterSeconds' => null, 'lastThrottledAtIso' => null, 'evidenceCode' => 'rate_limit_cleared'];
        }

        $throttledAt = CarbonImmutable::parse($latest->observed_at);
        $retryAfter = $latest->retry_after_seconds !== null ? (int) $latest->retry_after_seconds : null;
        $windowElapsed = $retryAfter !== null
            && $throttledAt->addSeconds($retryAfter)->lessThanOrEqualTo($observedAt);

        return [
            'state' => $windowElapsed ? 'recovering' : 'throttled',
            'retryAfterSeconds' => $retryAfter,
            'lastThrottledAtIso' => $throttledAt->toIso8601String(),
            'evidenceCode' => 'recorded_rate_limit',
        ];
    }

    /**
     * Retry-budget consumption derived from failed/retrying ingest runs in the
     * evaluation window against the configured per-run attempt budget.
     *
     * @return array<string, mixed>
     */
    private function retryBudgetState(int $sourceId, CarbonImmutable $windowStartedAt, CarbonImmutable $observedAt): array
    {
        $configuredPerRun = max(1, (int) config('integrations.observability.retry_budget_per_run', 3));
        $runs = DB::table('raw.ingest_runs')
            ->where('source_id', $sourceId)
            ->where('run_type', '<>', 'protocol_health')
            ->whereBetween('started_at', [$windowStartedAt, $observedAt])
            ->selectRaw('count(*) AS total_runs')
            ->selectRaw("count(*) FILTER (WHERE status = 'failed') AS failed_runs")
            ->selectRaw("count(*) FILTER (WHERE status = 'retrying') AS retrying_runs")
            ->first();
        $totalRuns = (int) ($runs->total_runs ?? 0);
        if ($totalRuns === 0) {
            return [
                'configuredAttemptsPerRun' => $configuredPerRun,
                'windowBudget' => null,
                'consumedAttempts' => null,
                'remainingAttempts' => null,
                'state' => 'unknown',
                'evidenceCode' => 'no_run_evidence',
            ];
        }
        $failedRuns = (int) ($runs->failed_runs ?? 0);
        $retryingRuns = (int) ($runs->retrying_runs ?? 0);
        $windowBudget = $totalRuns * $configuredPerRun;
        // A failed run consumed its full attempt budget; a retrying run has
        // consumed at least one retry beyond its first attempt.
        $consumed = min($windowBudget, ($failedRuns * $configuredPerRun) + $retryingRuns);
        $remaining = max(0, $windowBudget - $consumed);
        $ratio = $windowBudget > 0 ? $consumed / $windowBudget : 0.0;
        $state = match (true) {
            $ratio >= 0.9 => 'exhausted',
            $ratio >= 0.5 => 'strained',
            default => 'healthy',
        };

        return [
            'configuredAttemptsPerRun' => $configuredPerRun,
            'windowBudget' => $windowBudget,
            'consumedAttempts' => $consumed,
            'remainingAttempts' => $remaining,
            'state' => $state,
            'evidenceCode' => 'derived_from_ingest_runs',
        ];
    }

    private function latestPressure(int $sourceId, string $kind): ?object
    {
        if (! Schema::hasTable('integration.source_runtime_pressure_events')) {
            return null;
        }

        return DB::table('integration.source_runtime_pressure_events')
            ->where('source_id', $sourceId)
            ->where('pressure_kind', $kind)
            ->orderByDesc('source_runtime_pressure_event_id')
            ->first();
    }

    /** @param list<array<string, mixed>> $metrics */
    private function observationStatus(object $source, string $protocolStatus, array $metrics, bool $maintenanceActive): string
    {
        if (in_array((string) $source->active_status, ['inactive', 'disabled'], true)
            || in_array((string) $source->lifecycle_state, ['suspended', 'retired'], true)) {
            return 'disabled';
        }
        if ($maintenanceActive) {
            return 'maintenance';
        }
        if ($protocolStatus === 'failed' || collect($metrics)->contains('status', 'breached')) {
            return 'failed';
        }
        if (collect($metrics)->contains('status', 'unknown')) {
            return 'unknown';
        }
        if (in_array($protocolStatus, ['degraded', 'unobserved'], true)) {
            return 'degraded';
        }

        return 'healthy';
    }

    private function protocolStatus(object $source): string
    {
        if (in_array((string) $source->active_status, ['inactive', 'disabled'], true)) {
            return 'disabled';
        }
        $status = strtolower((string) ($source->protocol_health_status ?? 'unobserved'));

        return in_array($status, ['healthy', 'degraded', 'failed', 'unobserved'], true)
            ? $status
            : 'unsupported';
    }

    /** @param list<array<string, mixed>> $metrics */
    private function syncBreaches(
        int $sourceId,
        string $sourceKey,
        int $definitionId,
        int $observationId,
        array $metrics,
        bool $maintenanceActive,
        CarbonImmutable $observedAt,
    ): void {
        $byKey = collect($metrics)->keyBy('key');
        $open = $this->openBreaches($sourceId);
        foreach ($open as $breach) {
            $metric = $byKey->get((string) $breach->metric_key);
            if ((int) $breach->source_slo_definition_id !== $definitionId) {
                $this->recordBreachEvent($breach, $observationId, 'recovered', 'recovered', false, 'slo_definition_superseded', $observedAt);

                continue;
            }
            if (! is_array($metric) || $metric['status'] === 'not_applicable') {
                $this->recordBreachEvent($breach, $observationId, 'recovered', 'recovered', false, 'metric_recovered', $observedAt);

                continue;
            }

            if ($metric['status'] === 'met') {
                $this->recordBreachEvent($breach, $observationId, 'recovered', 'recovered', false, 'metric_recovered', $observedAt);

                continue;
            }

            $previous = (string) $breach->status_after;
            if ($maintenanceActive && $previous !== 'suppressed') {
                $this->recordBreachEvent($breach, $observationId, 'suppressed', 'suppressed', true, 'planned_maintenance_active', $observedAt);
            } elseif (! $maintenanceActive && $previous === 'suppressed') {
                $this->recordBreachEvent($breach, $observationId, 'resumed', 'open', false, 'planned_maintenance_ended', $observedAt);
            } else {
                $statusAfter = in_array($previous, ['open', 'acknowledged', 'suppressed'], true) ? $previous : 'open';
                $this->recordBreachEvent(
                    $breach,
                    $observationId,
                    'continued',
                    $statusAfter,
                    $statusAfter === 'suppressed',
                    $metric['status'] === 'unknown' ? 'metric_evidence_unknown' : 'metric_still_breached',
                    $observedAt,
                );
            }
        }

        $activeKeys = $open
            ->where('source_slo_definition_id', $definitionId)
            ->pluck('metric_key')
            ->map(fn (mixed $key): string => (string) $key)
            ->all();
        foreach ($metrics as $metric) {
            if ($metric['status'] !== 'breached' || in_array($metric['key'], $activeKeys, true)) {
                continue;
            }
            $breachRow = [
                'breach_uuid' => (string) Str::uuid7(),
                'source_id' => $sourceId,
                'source_slo_definition_id' => $definitionId,
                'metric_key' => $metric['key'],
                'opened_health_observation_id' => $observationId,
                'opened_health_observation_metric_id' => $metric['metricId'],
                'opened_at' => $observedAt,
                'created_at' => $observedAt,
            ];
            $this->contentGuard->assertSafe($breachRow, 'source_slo_breach_content_rejected');
            $breachId = (int) DB::table('integration.slo_breaches')->insertGetId($breachRow, 'slo_breach_id');
            $breach = (object) [
                'slo_breach_id' => $breachId,
                'status_after' => $maintenanceActive ? 'suppressed' : 'open',
            ];
            $this->recordBreachEvent(
                $breach,
                $observationId,
                'opened',
                $maintenanceActive ? 'suppressed' : 'open',
                $maintenanceActive,
                $maintenanceActive ? 'opened_during_planned_maintenance' : 'metric_threshold_breached',
                $observedAt,
            );

            // Fire the shared on-call delivery abstraction ONLY on the open
            // transition and ONLY when not suppressed by planned maintenance —
            // the flap-damped ledger keeps this to one page per breach. Alert
            // text is a stable code + source label + metric, never content.
            if (! $maintenanceActive) {
                $this->alertDispatcher->dispatch(
                    new OperationalAlert(
                        severity: 'crit',
                        domain: 'integration',
                        code: 'source_slo_breach',
                        title: sprintf('%s SLO breached', $this->humanMetric((string) $metric['key'])),
                        sourceLabel: $sourceKey,
                        deepLink: '/integrations?tab=observability',
                        facts: ['metric' => (string) $metric['key'], 'source_id' => $sourceId],
                    ),
                    'slo_breach',
                    (string) $breachRow['breach_uuid'],
                    null,
                    $observedAt,
                );
            }
        }
    }

    /** @param array<string, mixed> $metadata */
    private function recordBreachEvent(
        object $breach,
        int $observationId,
        string $eventType,
        string $statusAfter,
        bool $notificationSuppressed,
        string $reasonCode,
        CarbonImmutable $observedAt,
        ?int $actorUserId = null,
        ?string $incidentReferenceHash = null,
        array $metadata = [],
    ): void {
        $row = [
            'event_uuid' => (string) Str::uuid7(),
            'slo_breach_id' => (int) $breach->slo_breach_id,
            'health_observation_id' => $observationId,
            'event_type' => $eventType,
            'status_after' => $statusAfter,
            'notification_suppressed' => $notificationSuppressed,
            'reason_code' => $reasonCode,
            'actor_user_id' => $actorUserId,
            'incident_reference_hash' => $incidentReferenceHash,
            'metadata' => json_encode((object) $metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => $observedAt,
            'created_at' => $observedAt,
        ];
        $this->contentGuard->assertSafe($row, 'source_slo_breach_event_content_rejected');
        DB::table('integration.slo_breach_events')->insert($row);
    }

    /**
     * Acknowledge an open breach — an operator triage action. The breach stays
     * open; acknowledgement records who is on it plus a bounded reason code so
     * escalation/paging policy can suppress re-paging while owned.
     *
     * @return array<string, mixed>
     */
    public function acknowledgeBreach(
        int $sourceId,
        string $breachUuid,
        int $actorUserId,
        string $reasonCode,
        ?CarbonImmutable $occurredAt = null,
    ): array {
        return $this->operatorEvent($sourceId, $breachUuid, 'acknowledged', 'acknowledged', $actorUserId, $reasonCode, $occurredAt);
    }

    /**
     * Escalate an open/acknowledged breach. Escalation keeps the breach open and
     * re-fires the shared on-call delivery abstraction so the next tier pages.
     *
     * @return array<string, mixed>
     */
    public function escalateBreach(
        int $sourceId,
        string $breachUuid,
        int $actorUserId,
        string $reasonCode,
        ?CarbonImmutable $occurredAt = null,
    ): array {
        $result = $this->operatorEvent($sourceId, $breachUuid, 'escalated', 'open', $actorUserId, $reasonCode, $occurredAt);
        $context = $result['_context'];
        unset($result['_context']);
        $this->alertDispatcher->dispatch(
            new OperationalAlert(
                severity: 'crit',
                domain: 'integration',
                code: 'source_slo_breach_escalated',
                title: sprintf('%s breach escalated', $this->humanMetric((string) $context->metric_key)),
                sourceLabel: (string) $context->source_key,
                deepLink: '/integrations?tab=observability',
                facts: ['metric' => (string) $context->metric_key, 'source_id' => $sourceId, 'reason' => $reasonCode],
            ),
            'slo_breach',
            $breachUuid,
            null,
            $occurredAt ?? CarbonImmutable::now(),
        );

        return $result;
    }

    /**
     * Bind an open breach to an external incident record. Only a SHA-256 of the
     * incident reference is stored — never the raw ticket text.
     *
     * @return array<string, mixed>
     */
    public function linkBreachIncident(
        int $sourceId,
        string $breachUuid,
        int $actorUserId,
        string $incidentReference,
        ?CarbonImmutable $occurredAt = null,
    ): array {
        $reference = trim($incidentReference);
        if ($reference === '' || mb_strlen($reference) > 255) {
            throw new InvalidArgumentException('source_slo_incident_reference_invalid');
        }
        // The raw reference never lands anywhere; only its fingerprint does.
        $hash = hash('sha256', $reference);

        return $this->operatorEvent(
            $sourceId,
            $breachUuid,
            'incident_linked',
            null,
            $actorUserId,
            'incident_linked',
            $occurredAt,
            $hash,
        );
    }

    /**
     * Post-incident review — a `reviewed` event carrying a bounded, PHI-free
     * structured summary (stable enum codes and counts only, no free content).
     *
     * @param  array{root_cause_code:string, corrective_action_code:string, recurrence_risk:string}  $summary
     * @return array<string, mixed>
     */
    public function reviewBreach(
        int $sourceId,
        string $breachUuid,
        int $actorUserId,
        array $summary,
        ?CarbonImmutable $occurredAt = null,
    ): array {
        $bounded = $this->boundedReviewSummary($summary);

        return $this->operatorEvent(
            $sourceId,
            $breachUuid,
            'reviewed',
            null,
            $actorUserId,
            'post_incident_review_recorded',
            $occurredAt,
            null,
            $bounded,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function operatorEvent(
        int $sourceId,
        string $breachUuid,
        string $eventType,
        ?string $statusAfterOverride,
        int $actorUserId,
        string $reasonCode,
        ?CarbonImmutable $occurredAt,
        ?string $incidentReferenceHash = null,
        array $metadata = [],
    ): array {
        $occurredAt ??= CarbonImmutable::now();
        $reasonCode = strtolower(trim($reasonCode));
        if (preg_match('/^[a-z][a-z0-9_]{0,79}$/', $reasonCode) !== 1) {
            throw new InvalidArgumentException('source_slo_reason_code_invalid');
        }

        return DB::transaction(function () use (
            $sourceId,
            $breachUuid,
            $eventType,
            $statusAfterOverride,
            $actorUserId,
            $reasonCode,
            $occurredAt,
            $incidentReferenceHash,
            $metadata,
        ): array {
            $context = DB::table('integration.slo_breaches AS breach')
                ->join('integration.sources AS source', 'source.source_id', '=', 'breach.source_id')
                ->where('breach.breach_uuid', $breachUuid)
                ->where('breach.source_id', $sourceId)
                ->lockForUpdate()
                ->first(['breach.slo_breach_id', 'breach.metric_key', 'source.source_key']);
            if ($context === null) {
                throw new RuntimeException('source_slo_breach_not_found');
            }
            $current = DB::table('integration.slo_breach_events')
                ->where('slo_breach_id', $context->slo_breach_id)
                ->orderByDesc('slo_breach_event_id')
                ->first();
            if ($current === null) {
                throw new RuntimeException('source_slo_breach_has_no_events');
            }
            $currentStatus = (string) $current->status_after;
            if (! in_array($currentStatus, ['open', 'suppressed', 'acknowledged'], true)) {
                throw new RuntimeException('source_slo_breach_not_open');
            }

            // review/incident_linked keep the current lifecycle status; ack/escalate set it.
            $statusAfter = $statusAfterOverride ?? $currentStatus;

            $this->recordBreachEvent(
                (object) ['slo_breach_id' => (int) $context->slo_breach_id],
                (int) $current->health_observation_id,
                $eventType,
                $statusAfter,
                $statusAfter === 'suppressed',
                $reasonCode,
                $occurredAt,
                $actorUserId,
                $incidentReferenceHash,
                $metadata,
            );

            return [
                'breachUuid' => $breachUuid,
                'sourceId' => $sourceId,
                'metricKey' => (string) $context->metric_key,
                'eventType' => $eventType,
                'statusAfter' => $statusAfter,
                'reasonCode' => $reasonCode,
                'occurredAtIso' => $occurredAt->toIso8601String(),
                'incidentLinked' => $incidentReferenceHash !== null,
                '_context' => $context,
            ];
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array{root_cause_code:string, corrective_action_code:string, recurrence_risk:string}
     */
    private function boundedReviewSummary(array $summary): array
    {
        $code = static function (mixed $value, string $fallback): string {
            $value = strtolower(trim((string) $value));

            return preg_match('/^[a-z][a-z0-9_]{0,49}$/', $value) === 1 ? $value : $fallback;
        };
        $risk = strtolower(trim((string) ($summary['recurrence_risk'] ?? '')));

        return [
            'root_cause_code' => $code($summary['root_cause_code'] ?? null, 'unspecified'),
            'corrective_action_code' => $code($summary['corrective_action_code'] ?? null, 'unspecified'),
            'recurrence_risk' => in_array($risk, ['low', 'medium', 'high'], true) ? $risk : 'unspecified',
        ];
    }

    private function humanMetric(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }

    private function breachHasEvent(int $breachId, string $eventType): bool
    {
        return DB::table('integration.slo_breach_events')
            ->where('slo_breach_id', $breachId)
            ->where('event_type', $eventType)
            ->exists();
    }

    /** @return list<array<string, mixed>> the most recent bounded PHI-free event trail */
    private function breachEventTrail(int $breachId): array
    {
        return DB::table('integration.slo_breach_events')
            ->where('slo_breach_id', $breachId)
            ->orderByDesc('slo_breach_event_id')
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => [
                'eventType' => (string) $row->event_type,
                'statusAfter' => (string) $row->status_after,
                'reasonCode' => (string) $row->reason_code,
                'notificationSuppressed' => (bool) $row->notification_suppressed,
                'actorUserId' => $row->actor_user_id !== null ? (int) $row->actor_user_id : null,
                'incidentLinked' => $row->incident_reference_hash !== null,
                'occurredAtIso' => CarbonImmutable::parse($row->occurred_at)->toIso8601String(),
                'metadata' => $this->decodeMap($row->metadata),
            ])
            ->values()
            ->all();
    }

    /** @return Collection<int, object> */
    private function openBreaches(int $sourceId): Collection
    {
        $latestEvents = DB::table('integration.slo_breach_events')
            ->selectRaw('slo_breach_id, max(slo_breach_event_id) AS event_id')
            ->groupBy('slo_breach_id');

        return DB::table('integration.slo_breaches AS breach')
            ->joinSub($latestEvents, 'latest', 'latest.slo_breach_id', '=', 'breach.slo_breach_id')
            ->join('integration.slo_breach_events AS event', 'event.slo_breach_event_id', '=', 'latest.event_id')
            ->where('breach.source_id', $sourceId)
            ->whereIn('event.status_after', ['open', 'suppressed', 'acknowledged'])
            ->orderBy('breach.slo_breach_id')
            ->get([
                'breach.*',
                'event.status_after',
                'event.notification_suppressed',
                'event.occurred_at',
            ]);
    }

    /** @param array<string, int> $summary
     * @param  array<string, mixed>  $queueState
     * @param  array<string, mixed>  $runtimeState
     */
    private function projectCurrent(
        int $sourceId,
        int $observationId,
        int $definitionId,
        string $status,
        bool $maintenanceActive,
        CarbonImmutable $observedAt,
        CarbonImmutable $freshnessExpiresAt,
        array $summary,
        array $queueState,
        array $runtimeState,
    ): void {
        $current = DB::table('integration.source_health_current')
            ->where('source_id', $sourceId)
            ->lockForUpdate()
            ->first();
        $row = [
            'source_id' => $sourceId,
            'health_observation_id' => $observationId,
            'source_slo_definition_id' => $definitionId,
            'observation_status' => $status,
            'maintenance_active' => $maintenanceActive,
            'observed_at' => $observedAt,
            'freshness_expires_at' => $freshnessExpiresAt,
            'summary_counts' => json_encode($summary, JSON_THROW_ON_ERROR),
            'queue_state' => json_encode($queueState, JSON_THROW_ON_ERROR),
            'runtime_state' => json_encode($runtimeState, JSON_THROW_ON_ERROR),
            'projection_version' => $current !== null ? (int) $current->projection_version + 1 : 1,
            'updated_at' => $observedAt,
        ];
        $this->contentGuard->assertSafe($row, 'source_health_projection_content_rejected');
        if ($current === null) {
            DB::table('integration.source_health_current')->insert($row);
        } else {
            DB::table('integration.source_health_current')->where('source_id', $sourceId)->update($row);
        }
    }

    /** @return array<string, mixed> */
    private function observationPayload(object $row): array
    {
        return [
            'observationId' => (int) $row->health_observation_id,
            'observationUuid' => (string) $row->observation_uuid,
            'batchUuid' => (string) $row->batch_uuid,
            'correlationUuid' => (string) $row->correlation_uuid,
            'sloDefinitionId' => (int) $row->source_slo_definition_id,
            'status' => (string) $row->observation_status,
            'protocolStatus' => (string) $row->protocol_status,
            'protocolErrorCode' => $row->protocol_error_code,
            'maintenanceActive' => (bool) $row->maintenance_active,
            'observedAtIso' => CarbonImmutable::parse($row->observed_at)->toIso8601String(),
            'freshUntilIso' => CarbonImmutable::parse($row->freshness_expires_at)->toIso8601String(),
            'origin' => (string) $row->collector_origin,
            'recordedByUserId' => $row->recorded_by_user_id !== null ? (int) $row->recorded_by_user_id : null,
            'summary' => $this->decodeMap($row->summary_counts),
            'queueState' => $this->decodeMap($row->queue_state),
            'runtimeState' => $this->decodeMap($row->runtime_state),
            'evidenceSha256' => (string) $row->evidence_sha256,
        ];
    }

    private function assertOrigin(string $origin): void
    {
        if (! in_array($origin, ['scheduled', 'manual', 'runtime'], true)) {
            throw new InvalidArgumentException('source_health_origin_invalid');
        }
    }

    private function assertUuid(string $value, string $errorCode): void
    {
        if (! Str::isUuid($value)) {
            throw new InvalidArgumentException($errorCode);
        }
    }

    private function errorCode(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return $value !== '' && preg_match('/^[a-z][a-z0-9_]{0,79}$/', $value) === 1
            ? $value
            : ($value !== '' ? 'protocol_health_failed' : null);
    }

    private function stableErrorCode(Throwable $exception): string
    {
        if (! $exception instanceof RuntimeException) {
            return 'source_health_collection_failed';
        }
        $value = strtolower(trim($exception->getMessage()));

        return preg_match('/^[a-z][a-z0-9_]{0,79}$/', $value) === 1
            ? $value
            : 'source_health_collection_failed';
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $nested): mixed => $this->canonicalize($nested), $value);
    }

    /** @return array<string, mixed> */
    private function decodeMap(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : [];
    }
}
