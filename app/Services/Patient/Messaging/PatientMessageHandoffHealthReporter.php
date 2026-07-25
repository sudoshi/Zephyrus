<?php

namespace App\Services\Patient\Messaging;

use App\Models\PatientCommunication\ConsumerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only, content-free operational status for patient-message handoff.
 *
 * This reporter deliberately exposes aggregate counts only. It never returns
 * a patient, thread, grant, outbox UUID, message body, worker reference, or
 * routing decision. It also never creates a delivery attempt or replays work.
 */
final class PatientMessageHandoffHealthReporter
{
    private const REQUIRED_TABLES = [
        'patient_experience.notification_outbox',
        'patient_experience.notification_delivery_attempts',
        'patient_communications.consumer_heartbeats',
    ];

    /**
     * @return array{
     *     observed_at: string,
     *     activation_state: 'active'|'disabled'|'governance_unapproved',
     *     status: 'healthy'|'warning'|'critical'|'disabled',
     *     requires_operator_action: bool,
     *     schema_ready: bool,
     *     consumer: array{heartbeat_present: bool, heartbeat_fresh: bool, heartbeat_status: string|null, heartbeat_ttl_seconds: int},
     *     outbox: array{total_unexpired: int, pending_ready: int, pending_scheduled: int, in_flight: int, expired_claim_lease: int, retry_due: int, retry_scheduled: int, terminal_failure: int, delivered: int, expired: int, unknown_state: int}
     * }
     */
    public function report(): array
    {
        $observedAt = CarbonImmutable::now();
        $activationState = $this->activationState();
        $schemaReady = ! collect(self::REQUIRED_TABLES)->contains(
            fn (string $table): bool => ! Schema::hasTable($table),
        );
        $ttlSeconds = $this->heartbeatTtlSeconds();
        $heartbeat = $schemaReady
            ? ConsumerHeartbeat::query()->find($this->consumerKey())
            : null;
        $heartbeatFresh = $activationState !== 'active'
            || ($heartbeat !== null
                && $heartbeat->last_seen_at !== null
                && $heartbeat->last_seen_at->gte($observedAt->copy()->subSeconds($ttlSeconds)));
        $outbox = $schemaReady ? $this->outboxSummary($observedAt) : $this->emptyOutboxSummary();

        $status = $this->status(
            $activationState,
            $schemaReady,
            $heartbeatFresh,
            $heartbeat?->status,
            $outbox,
        );

        return [
            'observed_at' => $observedAt->toISOString(),
            'activation_state' => $activationState,
            'status' => $status,
            'requires_operator_action' => $status === 'critical',
            'schema_ready' => $schemaReady,
            'consumer' => [
                'heartbeat_present' => $heartbeat !== null,
                'heartbeat_fresh' => $heartbeatFresh,
                'heartbeat_status' => $heartbeat?->status,
                'heartbeat_ttl_seconds' => $ttlSeconds,
            ],
            'outbox' => $outbox,
        ];
    }

    /**
     * @return array{total_unexpired: int, pending_ready: int, pending_scheduled: int, in_flight: int, expired_claim_lease: int, retry_due: int, retry_scheduled: int, terminal_failure: int, delivered: int, expired: int, unknown_state: int}
     */
    private function outboxSummary(mixed $observedAt): array
    {
        $summary = $this->emptyOutboxSummary();
        $latestAttempts = DB::table('patient_experience.notification_delivery_attempts')
            ->selectRaw('notification_outbox_id, max(notification_delivery_attempt_id) AS notification_delivery_attempt_id')
            ->groupBy('notification_outbox_id');

        $rows = DB::table('patient_experience.notification_outbox as outbox')
            ->leftJoinSub($latestAttempts, 'latest_attempt', function ($join): void {
                $join->on('latest_attempt.notification_outbox_id', '=', 'outbox.notification_outbox_id');
            })
            ->leftJoin(
                'patient_experience.notification_delivery_attempts as attempt',
                'attempt.notification_delivery_attempt_id',
                '=',
                'latest_attempt.notification_delivery_attempt_id',
            )
            ->where('outbox.destination', 'staff_inbox')
            ->where(function ($query) use ($observedAt): void {
                $query->whereNull('outbox.expires_at')->orWhere('outbox.expires_at', '>', $observedAt);
            })
            ->select([
                'outbox.available_at',
                'attempt.status as attempt_status',
                'attempt.next_attempt_at',
            ])
            ->orderBy('outbox.notification_outbox_id')
            ->cursor();

        foreach ($rows as $row) {
            $summary['total_unexpired']++;
            $state = $this->outboxState(
                $row->attempt_status,
                $row->available_at,
                $row->next_attempt_at,
                $observedAt,
            );
            $summary[$state]++;
        }

        return $summary;
    }

    /**
     * @return 'pending_ready'|'pending_scheduled'|'in_flight'|'expired_claim_lease'|'retry_due'|'retry_scheduled'|'terminal_failure'|'delivered'|'expired'|'unknown_state'
     */
    private function outboxState(
        mixed $attemptStatus,
        mixed $availableAt,
        mixed $nextAttemptAt,
        mixed $observedAt,
    ): string {
        $availableAt = $availableAt === null ? null : CarbonImmutable::parse((string) $availableAt);
        $nextAttemptAt = $nextAttemptAt === null ? null : CarbonImmutable::parse((string) $nextAttemptAt);

        if ($attemptStatus === null) {
            return $availableAt === null || $availableAt <= $observedAt
                ? 'pending_ready'
                : 'pending_scheduled';
        }

        return match ($attemptStatus) {
            'claimed' => $nextAttemptAt === null || $nextAttemptAt <= $observedAt
                ? 'expired_claim_lease'
                : 'in_flight',
            'retryable_failure' => $nextAttemptAt === null || $nextAttemptAt <= $observedAt
                ? 'retry_due'
                : 'retry_scheduled',
            'terminal_failure' => 'terminal_failure',
            'delivered' => 'delivered',
            'expired' => 'expired',
            default => 'unknown_state',
        };
    }

    /**
     * @param  array{total_unexpired: int, pending_ready: int, pending_scheduled: int, in_flight: int, expired_claim_lease: int, retry_due: int, retry_scheduled: int, terminal_failure: int, delivered: int, expired: int, unknown_state: int}  $outbox
     * @return 'healthy'|'warning'|'critical'|'disabled'
     */
    private function status(
        string $activationState,
        bool $schemaReady,
        bool $heartbeatFresh,
        ?string $heartbeatStatus,
        array $outbox,
    ): string {
        if (! $schemaReady) {
            return 'critical';
        }

        if ($activationState !== 'active') {
            return 'disabled';
        }

        if (! $heartbeatFresh || $outbox['terminal_failure'] > 0 || $outbox['unknown_state'] > 0) {
            return 'critical';
        }

        if ($outbox['expired_claim_lease'] > 0
            || $outbox['retry_due'] > 0
            || $outbox['retry_scheduled'] > 0
            || $heartbeatStatus !== 'ready'
        ) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * @return array{total_unexpired: int, pending_ready: int, pending_scheduled: int, in_flight: int, expired_claim_lease: int, retry_due: int, retry_scheduled: int, terminal_failure: int, delivered: int, expired: int, unknown_state: int}
     */
    private function emptyOutboxSummary(): array
    {
        return [
            'total_unexpired' => 0,
            'pending_ready' => 0,
            'pending_scheduled' => 0,
            'in_flight' => 0,
            'expired_claim_lease' => 0,
            'retry_due' => 0,
            'retry_scheduled' => 0,
            'terminal_failure' => 0,
            'delivered' => 0,
            'expired' => 0,
            'unknown_state' => 0,
        ];
    }

    /** @return 'active'|'disabled'|'governance_unapproved' */
    private function activationState(): string
    {
        if (! (bool) config('hummingbird-patient.staff_messaging.enabled', false)) {
            return 'disabled';
        }

        return config('hummingbird-patient.staff_messaging.governance_status') === 'approved'
            ? 'active'
            : 'governance_unapproved';
    }

    private function consumerKey(): string
    {
        return (string) config('hummingbird-patient.staff_messaging.consumer_key', 'patient-message-staff-inbox-v1');
    }

    private function heartbeatTtlSeconds(): int
    {
        return max(30, min(600, (int) config('hummingbird-patient.staff_messaging.heartbeat_ttl_seconds', 120)));
    }
}
