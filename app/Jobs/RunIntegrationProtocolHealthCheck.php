<?php

namespace App\Jobs;

use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;
use App\Integrations\Healthcare\Services\IntegrationConfigurationAuditService;
use App\Integrations\Healthcare\Services\IntegrationProtocolHealthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunIntegrationProtocolHealthCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

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
    ): void {
        $run = DB::table('raw.ingest_runs')->where('ingest_run_id', $this->runId)->first();
        if (! $run || $run->status === 'completed') {
            return;
        }

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

            throw $exception;
        }
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
}
