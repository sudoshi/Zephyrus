<?php

namespace App\Jobs;

use App\Integrations\Healthcare\Exceptions\IntegrationCircuitOpenException;
use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;
use App\Integrations\Healthcare\Exceptions\IntegrationThrottledException;
use App\Integrations\Healthcare\Services\IntegrationConfigurationAuditService;
use App\Integrations\Healthcare\Services\IntegrationProtocolHealthService;
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
use Throwable;

class RunIntegrationProtocolHealthCheck implements ClinicalPayloadSafeQueueJob, ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_ATTEMPTS = 3;

    public int $tries = self::MAX_ATTEMPTS;

    public int $timeout = 60;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(
        public readonly int $runId,
        public readonly ?int $actorUserId,
        public readonly string $correlationId,
    ) {
        $this->onConnection('database')->onQueue('integrations');
    }

    public function handle(
        IntegrationProtocolHealthService $health,
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
            'protocol_health',
            'attempt_started',
            $attempt,
            self::MAX_ATTEMPTS,
            'protocol_health_attempt_started',
            $this->correlationId,
        );

        DB::table('raw.ingest_runs')->where('ingest_run_id', $this->runId)->update([
            'status' => 'running',
            'started_at' => now(),
            'completed_at' => null,
            'error_summary' => null,
            'updated_at' => now(),
        ]);

        try {
            $result = $health->check((int) $run->source_id);
            DB::table('raw.ingest_runs')->where('ingest_run_id', $this->runId)->update([
                'status' => 'completed',
                'completed_at' => now(),
                'metadata' => json_encode(array_merge($this->metadata($run->metadata), ['result' => $result]), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            $audit->record($this->actorUserId, 'completed', 'protocol_health_check', $this->runId, (string) $run->run_uuid, [], $result, $this->correlationId);
            $runtimeExecution->recordIngestRun(
                $this->runId,
                'protocol_health',
                'succeeded',
                $attempt,
                self::MAX_ATTEMPTS,
                'protocol_health_completed',
                $this->correlationId,
                durationMs: $this->durationMs($startedAt),
            );
        } catch (IntegrationThrottledException $exception) {
            $this->deferForPressure($runtimeExecution, $run, $attempt, $startedAt, 'throttled', $exception);
            if ($this->canRelease($attempt)) {
                $this->release($exception->retryAfterSeconds);

                return;
            }

            throw new IntegrationProtocolException($exception->errorCode);
        } catch (IntegrationCircuitOpenException $exception) {
            $this->deferForPressure($runtimeExecution, $run, $attempt, $startedAt, 'circuit_rejected', $exception);
            if ($this->canRelease($attempt)) {
                $this->release($exception->retryAfterSeconds);

                return;
            }

            throw new IntegrationProtocolException($exception->errorCode);
        } catch (Throwable $exception) {
            $errorCode = $exception instanceof IntegrationProtocolException
                ? $exception->errorCode
                : 'protocol_health_check_failed';
            DB::table('raw.ingest_runs')->where('ingest_run_id', $this->runId)->update([
                'status' => 'retrying',
                'completed_at' => null,
                'error_summary' => $errorCode,
                'metadata' => json_encode(array_merge($this->metadata($run->metadata), ['errorCode' => $errorCode]), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            if ($attempt < self::MAX_ATTEMPTS) {
                $runtimeExecution->recordIngestRun(
                    $this->runId,
                    'protocol_health',
                    'retry_scheduled',
                    $attempt,
                    self::MAX_ATTEMPTS,
                    $errorCode,
                    $this->correlationId,
                    $this->backoffForAttempt($attempt),
                    $this->durationMs($startedAt),
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
        $errorCode = $exception instanceof IntegrationProtocolException
            ? $exception->errorCode
            : 'protocol_health_check_failed';
        DB::table('raw.ingest_runs')->where('ingest_run_id', $this->runId)->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_summary' => $errorCode,
            'updated_at' => now(),
        ]);
        app(SourceRuntimeExecutionService::class)->recordIngestRun(
            $this->runId,
            'protocol_health',
            'terminal_failed',
            $this->currentAttempt(),
            self::MAX_ATTEMPTS,
            $errorCode,
            $this->correlationId,
        );
        app(IntegrationConfigurationAuditService::class)->record(
            $this->actorUserId,
            'failed',
            'protocol_health_check',
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
        IntegrationThrottledException|IntegrationCircuitOpenException $exception,
    ): void {
        DB::table('raw.ingest_runs')->where('ingest_run_id', $this->runId)->update([
            'status' => 'retrying',
            'completed_at' => null,
            'error_summary' => $exception->errorCode,
            'metadata' => json_encode(array_merge($this->metadata($run->metadata), ['errorCode' => $exception->errorCode]), JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
        $runtimeExecution->recordIngestRun(
            $this->runId,
            'protocol_health',
            $eventType,
            $attempt,
            self::MAX_ATTEMPTS,
            $exception->errorCode,
            $this->correlationId,
            $exception->retryAfterSeconds,
            $this->durationMs($startedAt),
        );
        if ($attempt < self::MAX_ATTEMPTS) {
            $runtimeExecution->recordIngestRun(
                $this->runId,
                'protocol_health',
                'retry_scheduled',
                $attempt,
                self::MAX_ATTEMPTS,
                $exception->errorCode,
                $this->correlationId,
                $exception->retryAfterSeconds,
            );
        }
    }
}
