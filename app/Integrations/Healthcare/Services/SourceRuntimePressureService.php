<?php

namespace App\Integrations\Healthcare\Services;

use App\Security\ClinicalPayloads\ClinicalContentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

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
            'retry_after_seconds' => null,
            'consecutive_failures' => max(0, $consecutiveFailures),
            'reason_code' => $this->reasonCode($reasonCode ?? match ($state) {
                'open' => 'consecutive_failures_tripped_circuit',
                'half_open' => 'circuit_probing_recovery',
                default => 'circuit_closed',
            }),
            'cleared_at' => $state === 'normal' ? ($observedAt ?? CarbonImmutable::now()) : null,
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
