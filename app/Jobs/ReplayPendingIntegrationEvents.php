<?php

namespace App\Jobs;

use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Integrations\Healthcare\Services\IntegrationConfigurationAuditService;
use App\Integrations\Healthcare\Services\RtdcProjectionHandler;
use App\Integrations\Healthcare\Services\SourceRuntimeExecutionService;
use App\Jobs\Middleware\FailClinicalJobSafely;
use App\Security\ClinicalPayloads\ClinicalPayloadHydrator;
use App\Security\ClinicalPayloads\ClinicalPayloadSafeQueueJob;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReplayPendingIntegrationEvents implements ClinicalPayloadSafeQueueJob, ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_ATTEMPTS = 1;

    public int $tries = self::MAX_ATTEMPTS;

    public int $timeout = 300;

    public function __construct(
        public readonly int $replayJobId,
        public readonly ?int $actorUserId,
        public readonly string $correlationId,
    ) {
        $this->onConnection('database')->onQueue('integrations');
    }

    public function handle(
        RtdcProjectionHandler $projector,
        IntegrationConfigurationAuditService $audit,
        ?SourceRuntimeExecutionService $runtimeExecution = null,
        ?ClinicalPayloadHydrator $payloads = null,
    ): void {
        $runtimeExecution ??= app(SourceRuntimeExecutionService::class);
        $payloads ??= app(ClinicalPayloadHydrator::class);
        $replay = DB::table('integration.event_replay_jobs')->where('event_replay_job_id', $this->replayJobId)->first();
        if (! $replay || in_array($replay->status, ['completed', 'completed_with_errors'], true)) {
            return;
        }
        $startedAt = hrtime(true);
        $runtimeExecution->recordReplayJob(
            $this->replayJobId,
            'attempt_started',
            1,
            self::MAX_ATTEMPTS,
            'canonical_replay_attempt_started',
            $this->correlationId,
        );
        $scope = $this->map($replay->scope);
        DB::table('integration.event_replay_jobs')->where('event_replay_job_id', $this->replayJobId)->update([
            'status' => 'running', 'started_at' => now(), 'completed_at' => null, 'error_summary' => null, 'updated_at' => now(),
        ]);

        $events = DB::table('integration.canonical_events')
            ->when($scope['sourceId'] ?? null, fn ($query, $sourceId) => $query->where('source_id', $sourceId))
            ->whereBetween('occurred_at', [$scope['from'], $scope['to']])
            ->whereIn('event_type', $scope['eventTypes'])
            ->whereIn('projection_status', ['pending', 'failed'])
            ->orderBy('occurred_at')->orderBy('canonical_event_id')
            ->limit((int) $scope['limit'])->get();

        $replayed = 0;
        $failed = 0;
        foreach ($events as $row) {
            try {
                $event = new CanonicalOperationalEvent(
                    eventId: (string) $row->event_id,
                    eventType: (string) $row->event_type,
                    entityType: $row->entity_type,
                    entityRef: $row->entity_ref,
                    payload: $payloads->required(
                        $row->payload_object_id !== null ? (int) $row->payload_object_id : null,
                        (int) $row->source_id,
                        'canonical_event',
                        $row->payload,
                    ),
                    occurredAt: CarbonImmutable::parse($row->occurred_at),
                    idempotencyKey: (string) $row->idempotency_key,
                    correlationId: $row->correlation_id,
                    causationId: $row->causation_id,
                    sequenceKey: $row->sequence_key,
                    metadata: $this->map($row->metadata),
                );
                if (! $projector->supports($event)) {
                    throw new \InvalidArgumentException('Unsupported replay event.');
                }
                $projector->project($event);
                DB::table('integration.canonical_events')->where('canonical_event_id', $row->canonical_event_id)->update([
                    'projection_status' => 'projected', 'projected_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('integration.event_projection_errors')->where('canonical_event_id', $row->canonical_event_id)->where('status', 'open')->update([
                    'status' => 'resolved', 'updated_at' => now(),
                ]);
                $replayed++;
            } catch (Throwable $exception) {
                $failed++;
                DB::table('integration.canonical_events')->where('canonical_event_id', $row->canonical_event_id)->update([
                    'projection_status' => 'failed', 'updated_at' => now(),
                ]);
                DB::table('integration.event_projection_errors')->insert([
                    'canonical_event_id' => $row->canonical_event_id,
                    'projector_key' => 'rtdc.census',
                    'error_code' => 'replay_projection_failed',
                    'message' => 'The canonical event could not be replayed into the RTDC projection.',
                    'exception_class' => $exception::class,
                    'context' => json_encode(['replay_job_id' => $this->replayJobId], JSON_THROW_ON_ERROR),
                    'status' => 'open',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $status = $failed > 0 ? 'completed_with_errors' : 'completed';
        DB::table('integration.event_replay_jobs')->where('event_replay_job_id', $this->replayJobId)->update([
            'status' => $status,
            'events_replayed' => $replayed,
            'events_failed' => $failed,
            'completed_at' => now(),
            'error_summary' => $failed > 0 ? 'one_or_more_events_failed' : null,
            'updated_at' => now(),
        ]);
        $audit->record($this->actorUserId, $status, 'canonical_event_replay', $this->replayJobId, (string) $replay->replay_uuid, [], [
            'status' => $status, 'eventsReplayed' => $replayed, 'eventsFailed' => $failed,
        ], $this->correlationId);
        $runtimeExecution->recordReplayJob(
            $this->replayJobId,
            'succeeded',
            1,
            self::MAX_ATTEMPTS,
            $status === 'completed_with_errors' ? 'canonical_replay_completed_with_errors' : 'canonical_replay_completed',
            $this->correlationId,
            durationMs: $this->durationMs($startedAt),
            metadata: ['events_replayed' => $replayed, 'events_failed' => $failed],
        );
    }

    public function failed(?Throwable $exception): void
    {
        $replay = DB::table('integration.event_replay_jobs')->where('event_replay_job_id', $this->replayJobId)->first();
        if (! $replay || in_array($replay->status, ['completed', 'completed_with_errors', 'failed'], true)) {
            return;
        }

        DB::table('integration.event_replay_jobs')->where('event_replay_job_id', $this->replayJobId)->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_summary' => 'replay_job_failed',
            'updated_at' => now(),
        ]);
        app(SourceRuntimeExecutionService::class)->recordReplayJob(
            $this->replayJobId,
            'terminal_failed',
            1,
            self::MAX_ATTEMPTS,
            'replay_job_failed',
            $this->correlationId,
        );
        app(IntegrationConfigurationAuditService::class)->record(
            $this->actorUserId,
            'failed',
            'canonical_event_replay',
            $this->replayJobId,
            (string) $replay->replay_uuid,
            [],
            ['status' => 'failed', 'errorCode' => 'replay_job_failed'],
            $this->correlationId,
        );
    }

    /** @return list<FailClinicalJobSafely> */
    public function middleware(): array
    {
        return [new FailClinicalJobSafely];
    }

    public function clinicalPayloadSafeArguments(): array
    {
        return [
            'replayJobId' => $this->replayJobId,
            'actorUserId' => $this->actorUserId,
            'correlationId' => $this->correlationId,
        ];
    }

    /** @return array<string, mixed> */
    private function map(mixed $value): array
    {
        return is_string($value) ? (json_decode($value, true) ?: []) : (is_array($value) ? $value : []);
    }

    private function durationMs(int $startedAt): int
    {
        return max(0, min(86400000, (int) ((hrtime(true) - $startedAt) / 1_000_000)));
    }
}
