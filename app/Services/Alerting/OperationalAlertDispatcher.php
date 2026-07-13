<?php

namespace App\Services\Alerting;

use App\Contracts\OperationalAlertChannel;
use App\Security\ClinicalPayloads\ClinicalContentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Shared on-call delivery abstraction (INT-OBS 5 + ADM-HEALTH 6).
 *
 * This is the single place integration SLO breaches and critical system-health
 * observations turn into paging. It reuses the same inert-by-default channels
 * as the cockpit AlertFanout (Teams webhook + mobile push) — a new lane means a
 * new OperationalAlertChannel binding, never a new delivery path.
 *
 * Callers dispatch ONLY on a state TRANSITION (breach opened; component became
 * critical) so the flap-damping upstream keeps phones quiet. Every attempt is
 * recorded in the append-only PHI-free integration.operational_alert_deliveries
 * ledger — including the inert/suppressed no-op, so the absence of paging is
 * itself auditable.
 */
final class OperationalAlertDispatcher
{
    /** @param list<OperationalAlertChannel> $channels */
    public function __construct(
        private readonly array $channels,
        private readonly ClinicalContentGuard $contentGuard,
    ) {}

    /**
     * @param  'slo_breach'|'system_health_component'|'credential_rotation'  $subjectType
     * @return array{delivered:int, recipients:int, attempts:list<array{channel:string,outcome:string,recipients:int}>}
     */
    public function dispatch(
        OperationalAlert $alert,
        string $subjectType,
        string $subjectReference,
        ?string $correlationUuid = null,
        ?CarbonImmutable $dispatchedAt = null,
    ): array {
        $dispatchedAt ??= CarbonImmutable::now();
        // Defense in depth: the alert is PHI-free by construction, but the guard
        // is the last gate before any lane or ledger row sees it.
        $this->contentGuard->assertSafe($alert->toGuardedPayload(), 'operational_alert_content_rejected');

        $attempts = [];
        $delivered = 0;
        $recipients = 0;

        if ($this->channels === []) {
            $this->record($alert, $subjectType, $subjectReference, 'none', 'inert', 0, 'no_channel_bound', $correlationUuid, $dispatchedAt);

            return ['delivered' => 0, 'recipients' => 0, 'attempts' => []];
        }

        foreach ($this->channels as $channel) {
            $name = $this->channelName($channel);
            try {
                $count = $channel->deliver($alert);
                $outcome = $count > 0 ? 'delivered' : 'inert';
                if ($count > 0) {
                    $delivered++;
                    $recipients += $count;
                }
                $this->record($alert, $subjectType, $subjectReference, $name, $outcome, $count, $count > 0 ? 'channel_delivered' : 'channel_inert', $correlationUuid, $dispatchedAt);
                $attempts[] = ['channel' => $name, 'outcome' => $outcome, 'recipients' => $count];
            } catch (Throwable $exception) {
                // A broken lane must never break the caller (a breach sync, a
                // scheduled health collection). Record a stable failure only.
                Log::warning('operational_alert.delivery_failed', [
                    'channel' => $name,
                    'domain' => $alert->domain,
                    'code' => $alert->code,
                ]);
                $this->record($alert, $subjectType, $subjectReference, $name, 'failed', 0, 'channel_delivery_failed', $correlationUuid, $dispatchedAt);
                $attempts[] = ['channel' => $name, 'outcome' => 'failed', 'recipients' => 0];
            }
        }

        return ['delivered' => $delivered, 'recipients' => $recipients, 'attempts' => $attempts];
    }

    private function channelName(OperationalAlertChannel $channel): string
    {
        $name = strtolower(trim($channel->name()));

        return preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name) === 1 ? $name : 'unknown';
    }

    private function record(
        OperationalAlert $alert,
        string $subjectType,
        string $subjectReference,
        string $channel,
        string $outcome,
        int $recipientCount,
        string $reasonCode,
        ?string $correlationUuid,
        CarbonImmutable $dispatchedAt,
    ): void {
        $row = [
            'delivery_uuid' => (string) Str::uuid7(),
            'alert_domain' => $alert->domain,
            'alert_code' => $alert->code,
            'severity' => $alert->severity,
            'subject_type' => $subjectType,
            'subject_reference' => $subjectReference,
            'channel' => $channel,
            'outcome' => $outcome,
            'recipient_count' => max(0, $recipientCount),
            'reason_code' => $reasonCode,
            'correlation_uuid' => $correlationUuid !== null && Str::isUuid($correlationUuid) ? $correlationUuid : null,
            'dispatched_at' => $dispatchedAt,
            'created_at' => $dispatchedAt,
        ];
        $this->contentGuard->assertSafe($row, 'operational_alert_delivery_content_rejected');

        try {
            DB::table('integration.operational_alert_deliveries')->insert($row);
        } catch (Throwable $exception) {
            // The ledger is best-effort observability of paging; failing to
            // record it must not break the alerting caller.
            Log::warning('operational_alert.ledger_failed', ['channel' => $channel, 'code' => $alert->code]);
        }
    }
}
