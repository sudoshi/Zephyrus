<?php

namespace App\Integrations\Healthcare\Services;

use App\Security\ClinicalPayloads\ClinicalContentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Append-only, PHI-free execution evidence for governed integration jobs.
 *
 * Queue state is mutable and an ingest run's terminal status cannot prove how
 * many attempts occurred. This service records one idempotent event per
 * run/replay + event type + attempt. PostgreSQL independently verifies that the
 * claimed source and connector match the governing run.
 */
final class SourceRuntimeExecutionService
{
    private const EVENTS = [
        'queued',
        'attempt_started',
        'retry_scheduled',
        'throttled',
        'circuit_rejected',
        'succeeded',
        'terminal_failed',
    ];

    private const JOB_TYPES = ['fhir_poll', 'protocol_health', 'canonical_replay'];

    public function __construct(private readonly ClinicalContentGuard $contentGuard) {}

    /** @param array<string, int|float|bool|string|null> $metadata */
    public function recordIngestRun(
        int $ingestRunId,
        string $jobType,
        string $eventType,
        int $attemptNumber,
        int $maxAttempts,
        string $reasonCode,
        ?string $correlationUuid = null,
        ?int $retryAfterSeconds = null,
        ?int $durationMs = null,
        array $metadata = [],
        ?CarbonImmutable $observedAt = null,
    ): void {
        $run = DB::table('raw.ingest_runs')
            ->where('ingest_run_id', $ingestRunId)
            ->first(['ingest_run_id', 'source_id', 'connector_key']);
        if ($run === null) {
            throw new InvalidArgumentException('source_runtime_ingest_run_not_found');
        }

        $this->insert(
            targetType: 'ingest',
            targetId: $ingestRunId,
            sourceId: (int) $run->source_id,
            ingestRunId: $ingestRunId,
            replayJobId: null,
            connectorKey: (string) $run->connector_key,
            jobType: $jobType,
            eventType: $eventType,
            attemptNumber: $attemptNumber,
            maxAttempts: $maxAttempts,
            reasonCode: $reasonCode,
            correlationUuid: $correlationUuid,
            retryAfterSeconds: $retryAfterSeconds,
            durationMs: $durationMs,
            metadata: $metadata,
            observedAt: $observedAt,
        );
    }

    /** @param array<string, int|float|bool|string|null> $metadata */
    public function recordReplayJob(
        int $replayJobId,
        string $eventType,
        int $attemptNumber,
        int $maxAttempts,
        string $reasonCode,
        ?string $correlationUuid = null,
        ?int $retryAfterSeconds = null,
        ?int $durationMs = null,
        array $metadata = [],
        ?CarbonImmutable $observedAt = null,
    ): void {
        $replay = DB::table('integration.event_replay_jobs')
            ->where('event_replay_job_id', $replayJobId)
            ->first(['event_replay_job_id', 'source_id']);
        if ($replay === null) {
            throw new InvalidArgumentException('source_runtime_replay_job_not_found');
        }

        $this->insert(
            targetType: 'replay',
            targetId: $replayJobId,
            sourceId: $replay->source_id !== null ? (int) $replay->source_id : null,
            ingestRunId: null,
            replayJobId: $replayJobId,
            connectorKey: 'integration.canonical-replay',
            jobType: 'canonical_replay',
            eventType: $eventType,
            attemptNumber: $attemptNumber,
            maxAttempts: $maxAttempts,
            reasonCode: $reasonCode,
            correlationUuid: $correlationUuid,
            retryAfterSeconds: $retryAfterSeconds,
            durationMs: $durationMs,
            metadata: $metadata,
            observedAt: $observedAt,
        );
    }

    /** @param array<string, int|float|bool|string|null> $metadata */
    private function insert(
        string $targetType,
        int $targetId,
        ?int $sourceId,
        ?int $ingestRunId,
        ?int $replayJobId,
        string $connectorKey,
        string $jobType,
        string $eventType,
        int $attemptNumber,
        int $maxAttempts,
        string $reasonCode,
        ?string $correlationUuid,
        ?int $retryAfterSeconds,
        ?int $durationMs,
        array $metadata,
        ?CarbonImmutable $observedAt,
    ): void {
        if (! in_array($jobType, self::JOB_TYPES, true)) {
            throw new InvalidArgumentException('source_runtime_job_type_invalid');
        }
        if (! in_array($eventType, self::EVENTS, true)) {
            throw new InvalidArgumentException('source_runtime_event_type_invalid');
        }
        $attemptNumber = $eventType === 'queued' ? 0 : max(1, min(100, $attemptNumber));
        $maxAttempts = max(1, min(100, $maxAttempts));
        $retryAfterSeconds = $retryAfterSeconds !== null
            ? max(0, min(604800, $retryAfterSeconds))
            : null;
        $durationMs = $durationMs !== null ? max(0, min(86400000, $durationMs)) : null;
        $observedAt ??= CarbonImmutable::now();
        $reasonCode = $this->reasonCode($reasonCode);
        $correlationUuid = $correlationUuid !== null && Str::isUuid($correlationUuid)
            ? $correlationUuid
            : null;
        $eventKey = hash('sha256', implode("\0", [
            'source-runtime-v1',
            $targetType,
            (string) $targetId,
            $eventType,
            (string) $attemptNumber,
        ]));

        $safeMetadata = [];
        foreach ($metadata as $key => $value) {
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1) {
                throw new InvalidArgumentException('source_runtime_metadata_key_invalid');
            }
            if ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException('source_runtime_metadata_value_invalid');
            }
            if (is_string($value) && mb_strlen($value) > 160) {
                throw new InvalidArgumentException('source_runtime_metadata_value_too_long');
            }
            if ($value !== null) {
                $safeMetadata[$key] = $value;
            }
        }
        ksort($safeMetadata);

        $row = [
            'event_uuid' => (string) Str::uuid7(),
            'event_key' => $eventKey,
            'correlation_uuid' => $correlationUuid,
            'source_id' => $sourceId,
            'ingest_run_id' => $ingestRunId,
            'event_replay_job_id' => $replayJobId,
            'connector_key' => $connectorKey,
            'job_type' => $jobType,
            'event_type' => $eventType,
            'attempt_number' => $attemptNumber,
            'max_attempts' => $maxAttempts,
            'retry_after_seconds' => $retryAfterSeconds,
            'available_at' => $retryAfterSeconds !== null ? $observedAt->addSeconds($retryAfterSeconds) : null,
            'reason_code' => $reasonCode,
            'duration_ms' => $durationMs,
            'metadata' => json_encode((object) $safeMetadata, JSON_THROW_ON_ERROR),
            'observed_at' => $observedAt,
            'created_at' => $observedAt,
        ];
        $this->contentGuard->assertSafe($row, 'source_runtime_execution_content_rejected');

        DB::table('integration.source_runtime_execution_events')->insertOrIgnore($row);
    }

    private function reasonCode(string $value): string
    {
        $value = strtolower(trim($value));

        return preg_match('/^[a-z][a-z0-9_]{0,79}$/', $value) === 1
            ? $value
            : 'runtime_execution_recorded';
    }
}
