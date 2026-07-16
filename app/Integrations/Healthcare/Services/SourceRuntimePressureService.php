<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Exceptions\IntegrationCircuitOpenException;
use App\Integrations\Healthcare\Exceptions\IntegrationThrottledException;
use App\Security\ClinicalPayloads\ClinicalContentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * INT-OBS 3 — records and derives per-source runtime pressure.
 *
 * Connectors call recordRateLimit()/recordCircuitTransition() when a partner
 * returns 429/Retry-After or a bounded retry loop exhausts, writing PHI-free
 * evidence to the append-only integration.source_runtime_pressure_events
 * ledger. The observability collector reads back the derived state so the
 * Integrations console and downstream contracts can see backpressure without
 * the collector calling a partner system itself.
 *
 * This service never resolves a secret, calls a partner, or advances a cursor.
 */
final class SourceRuntimePressureService
{
    public function __construct(private readonly ClinicalContentGuard $contentGuard) {}

    /**
     * Enforce the source/connector circuit before a partner network call.
     *
     * A source-row lock serializes the open -> half-open lease so only one
     * worker becomes the recovery probe. Rejected calls never touch the partner.
     */
    public function beforePartnerCall(
        int $sourceId,
        string $connectorKey,
        ?string $correlationUuid = null,
        ?CarbonImmutable $observedAt = null,
    ): void {
        $observedAt ??= CarbonImmutable::now();
        $connectorKey = $this->requiredConnectorKey($connectorKey);

        DB::transaction(function () use ($sourceId, $connectorKey, $correlationUuid, $observedAt): void {
            $sourceExists = DB::table('integration.sources')
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->exists();
            if (! $sourceExists) {
                throw new InvalidArgumentException('source_runtime_source_not_found');
            }

            $rateLimit = $this->latest($sourceId, 'rate_limit', $connectorKey);
            if ($rateLimit !== null && (string) $rateLimit->pressure_state === 'throttled') {
                $retrySeconds = max(1, (int) ($rateLimit->retry_after_seconds
                    ?? config('integrations.observability.rate_limit_default_retry_seconds', 60)));
                $availableAt = CarbonImmutable::parse($rateLimit->observed_at)->addSeconds($retrySeconds);
                if ($availableAt->greaterThan($observedAt)) {
                    throw new IntegrationThrottledException(
                        max(1, (int) ceil($observedAt->diffInSeconds($availableAt))),
                        'partner_rate_limit_active',
                    );
                }
            }

            $latest = $this->latest($sourceId, 'circuit_breaker', $connectorKey);
            if ($latest === null || (string) $latest->pressure_state === 'normal') {
                return;
            }

            $leaseSeconds = max(1, (int) ($latest->retry_after_seconds
                ?? config('integrations.observability.circuit_breaker_open_seconds', 60)));
            $availableAt = CarbonImmutable::parse($latest->observed_at)->addSeconds($leaseSeconds);
            if ($availableAt->greaterThan($observedAt)) {
                throw new IntegrationCircuitOpenException(
                    max(1, (int) ceil($observedAt->diffInSeconds($availableAt))),
                );
            }

            $probeLease = max(1, (int) config(
                'integrations.observability.circuit_breaker_half_open_lease_seconds',
                30,
            ));
            $this->recordCircuitTransition(
                $sourceId,
                'half_open',
                (int) ($latest->consecutive_failures ?? 0),
                $connectorKey,
                'circuit_half_open_probe_started',
                $correlationUuid,
                $observedAt,
                $probeLease,
            );
        }, 3);
    }

    /**
     * Count only partner/runtime failures that a circuit can mitigate. Invalid
     * configuration, governance, credentials, and unsupported profiles do not
     * trip the circuit and must be remediated instead of hidden behind retries.
     */
    public function recordPartnerFailure(
        int $sourceId,
        string $connectorKey,
        string $errorCode,
        ?string $correlationUuid = null,
        ?CarbonImmutable $observedAt = null,
    ): bool {
        if (! $this->isCircuitFailure($errorCode)) {
            return false;
        }

        $observedAt ??= CarbonImmutable::now();
        $connectorKey = $this->requiredConnectorKey($connectorKey);

        DB::transaction(function () use ($sourceId, $connectorKey, $errorCode, $correlationUuid, $observedAt): void {
            DB::table('integration.sources')->where('source_id', $sourceId)->lockForUpdate()->firstOrFail();
            $latest = $this->latest($sourceId, 'circuit_breaker', $connectorKey);
            $failures = max(0, (int) ($latest->consecutive_failures ?? 0)) + 1;
            $threshold = max(2, (int) config('integrations.observability.circuit_breaker_trip_failures', 5));
            $open = $failures >= $threshold || (string) ($latest->pressure_state ?? '') === 'half_open';

            $this->recordCircuitTransition(
                $sourceId,
                $open ? 'open' : 'normal',
                $failures,
                $connectorKey,
                $open ? 'consecutive_failures_tripped_circuit' : $this->reasonCode($errorCode),
                $correlationUuid,
                $observedAt,
                $open ? max(1, (int) config('integrations.observability.circuit_breaker_open_seconds', 60)) : null,
            );
        }, 3);

        return true;
    }

    public function recordPartnerSuccess(
        int $sourceId,
        string $connectorKey,
        ?string $correlationUuid = null,
        ?CarbonImmutable $observedAt = null,
    ): void {
        $observedAt ??= CarbonImmutable::now();
        $connectorKey = $this->requiredConnectorKey($connectorKey);

        DB::transaction(function () use ($sourceId, $connectorKey, $correlationUuid, $observedAt): void {
            DB::table('integration.sources')->where('source_id', $sourceId)->lockForUpdate()->firstOrFail();
            $circuit = $this->latest($sourceId, 'circuit_breaker', $connectorKey);
            if ($circuit !== null && (
                (string) $circuit->pressure_state !== 'normal'
                || (int) ($circuit->consecutive_failures ?? 0) > 0
            )) {
                $this->recordCircuitTransition(
                    $sourceId,
                    'normal',
                    0,
                    $connectorKey,
                    'partner_call_succeeded_circuit_closed',
                    $correlationUuid,
                    $observedAt,
                );
            }

            $rateLimit = $this->latest($sourceId, 'rate_limit', $connectorKey);
            if ($rateLimit !== null && (string) $rateLimit->pressure_state === 'throttled') {
                $this->recordRateLimit(
                    $sourceId,
                    'normal',
                    null,
                    null,
                    $connectorKey,
                    'partner_call_succeeded_rate_limit_cleared',
                    $correlationUuid,
                    $observedAt,
                );
            }
        }, 3);
    }

    public function retryAfterSeconds(?string $value, ?CarbonImmutable $observedAt = null): int
    {
        $default = max(1, min(604800, (int) config(
            'integrations.observability.rate_limit_default_retry_seconds',
            60,
        )));
        $value = trim((string) $value);
        if ($value === '') {
            return $default;
        }
        if (preg_match('/^\d{1,9}$/', $value) === 1) {
            return max(1, min(604800, (int) $value));
        }

        try {
            $observedAt ??= CarbonImmutable::now();
            $retryAt = CarbonImmutable::parse($value);

            return $retryAt->greaterThan($observedAt)
                ? max(1, min(604800, $observedAt->diffInSeconds($retryAt)))
                : 1;
        } catch (Throwable) {
            return $default;
        }
    }

    public function isCircuitFailure(string $errorCode): bool
    {
        $errorCode = strtolower(trim($errorCode));

        return preg_match('/^(fhir|smart_token|protocol)_http_5\d\d$/', $errorCode) === 1
            || in_array($errorCode, [
                'fhir_transport_unavailable',
                'smart_token_transport_unavailable',
                'protocol_transport_unavailable',
                'fhir_transport_timeout',
                'smart_token_transport_timeout',
                'protocol_transport_timeout',
            ], true);
    }

    /**
     * Record a 429/Retry-After (or a manual throttle/clear) rate-limit signal.
     */
    public function recordRateLimit(
        int $sourceId,
        string $state,
        ?int $httpStatus = 429,
        ?int $retryAfterSeconds = null,
        ?string $connectorKey = null,
        ?string $reasonCode = null,
        ?string $correlationUuid = null,
        ?CarbonImmutable $observedAt = null,
    ): void {
        if (! in_array($state, ['throttled', 'normal'], true)) {
            throw new InvalidArgumentException('source_rate_limit_state_invalid');
        }
        $this->insert([
            'source_id' => $sourceId,
            'connector_key' => $this->connectorKey($connectorKey),
            'pressure_kind' => 'rate_limit',
            'pressure_state' => $state,
            'http_status' => $httpStatus,
            'retry_after_seconds' => $retryAfterSeconds !== null ? max(0, min(604800, $retryAfterSeconds)) : null,
            'consecutive_failures' => null,
            'reason_code' => $this->reasonCode($reasonCode ?? ($state === 'throttled' ? 'partner_rate_limited' : 'rate_limit_cleared')),
            'cleared_at' => $state === 'normal' ? ($observedAt ?? CarbonImmutable::now()) : null,
            'correlation_uuid' => $this->correlation($correlationUuid),
            'observed_at' => $observedAt ?? CarbonImmutable::now(),
        ]);
    }

    /**
     * Record a circuit-breaker transition after N consecutive failures / recovery.
     */
    public function recordCircuitTransition(
        int $sourceId,
        string $state,
        int $consecutiveFailures,
        ?string $connectorKey = null,
        ?string $reasonCode = null,
        ?string $correlationUuid = null,
        ?CarbonImmutable $observedAt = null,
        ?int $retryAfterSeconds = null,
    ): void {
        if (! in_array($state, ['open', 'half_open', 'normal'], true)) {
            throw new InvalidArgumentException('source_circuit_state_invalid');
        }
        $this->insert([
            'source_id' => $sourceId,
            'connector_key' => $this->connectorKey($connectorKey),
            'pressure_kind' => 'circuit_breaker',
            'pressure_state' => $state,
            'http_status' => null,
            'retry_after_seconds' => $retryAfterSeconds !== null ? max(0, min(604800, $retryAfterSeconds)) : null,
            'consecutive_failures' => max(0, $consecutiveFailures),
            'reason_code' => $this->reasonCode($reasonCode ?? match ($state) {
                'open' => 'consecutive_failures_tripped_circuit',
                'half_open' => 'circuit_probing_recovery',
                default => 'circuit_closed',
            }),
            'cleared_at' => $state === 'normal' && $consecutiveFailures === 0
                ? ($observedAt ?? CarbonImmutable::now())
                : null,
            'correlation_uuid' => $this->correlation($correlationUuid),
            'observed_at' => $observedAt ?? CarbonImmutable::now(),
        ]);
    }

    /** @param array<string, mixed> $row */
    private function insert(array $row): void
    {
        $row = [
            'event_uuid' => (string) Str::uuid7(),
            'created_at' => $row['observed_at'],
            ...$row,
        ];
        $this->contentGuard->assertSafe($row, 'source_runtime_pressure_content_rejected');
        DB::table('integration.source_runtime_pressure_events')->insert($row);
    }

    private function connectorKey(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value !== null && $value !== '' && preg_match('/^[a-zA-Z0-9._:-]{1,160}$/', $value) === 1
            ? $value
            : null;
    }

    private function requiredConnectorKey(string $value): string
    {
        $connectorKey = $this->connectorKey($value);
        if ($connectorKey === null) {
            throw new InvalidArgumentException('source_runtime_connector_key_invalid');
        }

        return $connectorKey;
    }

    private function latest(int $sourceId, string $kind, string $connectorKey): ?object
    {
        return DB::table('integration.source_runtime_pressure_events')
            ->where('source_id', $sourceId)
            ->where('connector_key', $connectorKey)
            ->where('pressure_kind', $kind)
            ->orderByDesc('source_runtime_pressure_event_id')
            ->first();
    }

    private function reasonCode(string $value): string
    {
        $value = strtolower(trim($value));

        return preg_match('/^[a-z][a-z0-9_]{0,79}$/', $value) === 1 ? $value : 'runtime_pressure_recorded';
    }

    private function correlation(?string $value): ?string
    {
        return $value !== null && Str::isUuid($value) ? $value : null;
    }
}
