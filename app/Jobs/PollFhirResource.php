<?php

namespace App\Jobs;

use App\Integrations\Healthcare\Exceptions\IntegrationCircuitOpenException;
use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;
use App\Integrations\Healthcare\Exceptions\IntegrationThrottledException;
use App\Integrations\Healthcare\Services\IntegrationConfigurationAuditService;
use App\Integrations\Healthcare\Services\SmartBackendFhirClient;
use App\Integrations\Healthcare\Services\SourceRuntimeExecutionService;
use App\Jobs\Middleware\FailClinicalJobSafely;
use App\Security\ClinicalPayloads\ClinicalPayloadSafeQueueJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PollFhirResource implements ClinicalPayloadSafeQueueJob, ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_ATTEMPTS = 3;

    public int $tries = self::MAX_ATTEMPTS;

    public int $timeout = 300;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(
        public readonly int $runId,
        public readonly string $resourceType,
        public readonly ?int $actorUserId,
        public readonly string $correlationId,
    ) {
        $this->onConnection('database')->onQueue('integrations');
    }

    public function handle(
        SmartBackendFhirClient $client,
        IntegrationConfigurationAuditService $audit,
        ?SourceRuntimeExecutionService $runtimeExecution = null,
    ): void {
        $runtimeExecution ??= app(SourceRuntimeExecutionService::class);
        $run = DB::table('raw.ingest_runs')->where('ingest_run_id', $this->runId)->first();
        if (! $run || $run->status === 'completed') {
            return;
        }
        $attempt = $this->currentAttempt();
        $startedAt = hrtime(true);
        $runtimeExecution->recordIngestRun(
            $this->runId,
            'fhir_poll',
            'attempt_started',
            $attempt,
            self::MAX_ATTEMPTS,
            'fhir_poll_attempt_started',
            $this->correlationId,
            metadata: ['resource_type' => $this->resourceType],
        );
        DB::table('raw.ingest_runs')->where('ingest_run_id', $this->runId)->update([
            'status' => 'running', 'started_at' => now(), 'completed_at' => null, 'error_summary' => null, 'updated_at' => now(),
        ]);

        try {
            $result = $client->poll((int) $run->source_id, $this->resourceType, $this->runId);
            DB::table('raw.ingest_runs')->where('ingest_run_id', $this->runId)->update([
                'status' => 'completed',
                'completed_at' => now(),
                'messages_received' => $result['resourcesReceived'],
                'messages_succeeded' => $result['resourcesPersisted'],
                'messages_skipped' => $result['resourcesSkipped'],
                'metadata' => json_encode(array_merge($this->metadata($run->metadata), ['result' => $result]), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            DB::table('raw.dead_letters')
                ->where('ingest_run_id', $this->runId)
                ->where('failure_stage', 'fhir_poll')
                ->where('status', 'open')
                ->update([
                    'status' => 'resolved',
                    'resolved_at' => now(),
                    'replayed_at' => now(),
                    'updated_at' => now(),
                ]);
            $audit->record($this->actorUserId, 'completed', 'fhir_poll', $this->runId, (string) $run->run_uuid, [], $result, $this->correlationId);
            $runtimeExecution->recordIngestRun(
                $this->runId,
                'fhir_poll',
                'succeeded',
                $attempt,
                self::MAX_ATTEMPTS,
                'fhir_poll_completed',
                $this->correlationId,
                durationMs: $this->durationMs($startedAt),
                metadata: [
                    'resource_type' => $this->resourceType,
                    'resources_received' => (int) $result['resourcesReceived'],
                    'resources_persisted' => (int) $result['resourcesPersisted'],
                ],
            );
        } catch (IntegrationThrottledException $exception) {
            $this->deferForPressure(
                $runtimeExecution,
                $run,
                $attempt,
                $startedAt,
                'throttled',
                $exception->errorCode,
                $exception->retryAfterSeconds,
            );

            if ($this->canRelease($attempt)) {
                $this->release($exception->retryAfterSeconds);

                return;
            }

            throw new IntegrationProtocolException($exception->errorCode);
        } catch (IntegrationCircuitOpenException $exception) {
            $this->deferForPressure(
                $runtimeExecution,
                $run,
                $attempt,
                $startedAt,
                'circuit_rejected',
                $exception->errorCode,
                $exception->retryAfterSeconds,
            );

            if ($this->canRelease($attempt)) {
                $this->release($exception->retryAfterSeconds);

                return;
            }

            throw new IntegrationProtocolException($exception->errorCode);
        } catch (Throwable $exception) {
            $errorCode = $exception instanceof IntegrationProtocolException ? $exception->errorCode : 'fhir_poll_failed';
            DB::table('raw.ingest_runs')->where('ingest_run_id', $this->runId)->update([
                'status' => 'retrying', 'completed_at' => null,
                'error_summary' => $errorCode,
                'metadata' => json_encode(array_merge($this->metadata($run->metadata), ['errorCode' => $errorCode]), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            if ($attempt < self::MAX_ATTEMPTS) {
                $runtimeExecution->recordIngestRun(
                    $this->runId,
                    'fhir_poll',
                    'retry_scheduled',
                    $attempt,
                    self::MAX_ATTEMPTS,
                    $errorCode,
                    $this->correlationId,
                    $this->backoffForAttempt($attempt),
                    $this->durationMs($startedAt),
                    ['resource_type' => $this->resourceType],
                );
            }

            throw new IntegrationProtocolException($errorCode);
        }
    }

    /** @return list<FailClinicalJobSafely> */
    public function middleware(): array
    {
        return [new FailClinicalJobSafely];
    }

    public function clinicalPayloadSafeArguments(): array
    {
        return [
            'runId' => $this->runId,
            'resourceType' => $this->resourceType,
            'actorUserId' => $this->actorUserId,
            'correlationId' => $this->correlationId,
        ];
    }

    public function failed(?Throwable $exception): void
    {
        $run = DB::table('raw.ingest_runs')->where('ingest_run_id', $this->runId)->first();
        if (! $run || $run->status === 'completed') {
            return;
        }
        $errorCode = $exception instanceof IntegrationProtocolException ? $exception->errorCode : 'fhir_poll_failed';
        DB::table('raw.ingest_runs')->where('ingest_run_id', $this->runId)->update([
            'status' => 'failed',
            'completed_at' => now(),
            'messages_failed' => 1,
            'error_summary' => $errorCode,
            'updated_at' => now(),
        ]);
        app(SourceRuntimeExecutionService::class)->recordIngestRun(
            $this->runId,
            'fhir_poll',
            'terminal_failed',
            $this->currentAttempt(),
            self::MAX_ATTEMPTS,
            $errorCode,
            $this->correlationId,
            metadata: ['resource_type' => $this->resourceType],
        );
        if (! DB::table('raw.dead_letters')->where('ingest_run_id', $this->runId)->where('failure_stage', 'fhir_poll')->where('status', 'open')->exists()) {
            DB::table('raw.dead_letters')->insert([
                'dead_letter_uuid' => (string) Str::uuid(),
                'source_id' => $run->source_id,
                'ingest_run_id' => $this->runId,
                'failure_stage' => 'fhir_poll',
                'reason_code' => $errorCode,
                'message' => 'The governed FHIR poll did not complete.',
                'exception_class' => $exception !== null ? $exception::class : Throwable::class,
                'context' => json_encode(['resource_type' => $this->resourceType], JSON_THROW_ON_ERROR),
                'status' => 'open',
                'metadata' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        app(IntegrationConfigurationAuditService::class)->record(
            $this->actorUserId,
            'failed',
            'fhir_poll',
            $this->runId,
            (string) $run->run_uuid,
            [],
            ['errorCode' => $errorCode],
            $this->correlationId,
        );
    }

    /** @return array<string, mixed> */
    private function metadata(mixed $value): array
    {
        return is_string($value) ? (json_decode($value, true) ?: []) : (is_array($value) ? $value : []);
    }

    private function currentAttempt(): int
    {
        return max(1, min(self::MAX_ATTEMPTS, $this->job?->attempts() ?? 1));
    }

    private function canRelease(int $attempt): bool
    {
        return $this->job !== null && $attempt < self::MAX_ATTEMPTS;
    }

    private function backoffForAttempt(int $attempt): int
    {
        $backoff = $this->backoff();

        return (int) ($backoff[min(max(0, $attempt - 1), count($backoff) - 1)] ?? 10);
    }

    private function durationMs(int $startedAt): int
    {
        return max(0, min(86400000, (int) ((hrtime(true) - $startedAt) / 1_000_000)));
    }

    private function deferForPressure(
        SourceRuntimeExecutionService $runtimeExecution,
        object $run,
        int $attempt,
        int $startedAt,
        string $eventType,
        string $errorCode,
        int $retryAfterSeconds,
    ): void {
        DB::table('raw.ingest_runs')->where('ingest_run_id', $this->runId)->update([
            'status' => 'retrying',
            'completed_at' => null,
            'error_summary' => $errorCode,
            'metadata' => json_encode(array_merge($this->metadata($run->metadata), ['errorCode' => $errorCode]), JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
        $runtimeExecution->recordIngestRun(
            $this->runId,
            'fhir_poll',
            $eventType,
            $attempt,
            self::MAX_ATTEMPTS,
            $errorCode,
            $this->correlationId,
            $retryAfterSeconds,
            $this->durationMs($startedAt),
            ['resource_type' => $this->resourceType],
        );
        if ($attempt < self::MAX_ATTEMPTS) {
            $runtimeExecution->recordIngestRun(
                $this->runId,
                'fhir_poll',
                'retry_scheduled',
                $attempt,
                self::MAX_ATTEMPTS,
                $errorCode,
                $this->correlationId,
                $retryAfterSeconds,
                metadata: ['resource_type' => $this->resourceType],
            );
        }
    }
}
