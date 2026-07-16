<?php

namespace App\Observability;

use App\Observability\Contracts\MetricExporter;
use App\Observability\Contracts\TraceExporter;
use App\Security\ClinicalPayloads\ClinicalContentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * INT-OBS 4 — the PHI-safe, OpenTelemetry-compatible metric/trace recorder.
 *
 * This is the application-facing seam. It is config-gated (default OFF). When
 * enabled it validates every attribute through the safe-attribute contract and
 * forwards a PHI-safe record to memory/null or the official OTLP SDK adapter.
 *
 * Correlation from receipt through projection/outbound ACK is carried on the
 * attributes: 'zephyrus.correlation.uuid' (AssignRequestIdentity) and
 * 'zephyrus.event.uuid' (canonical event UUID). Recording is best-effort: an exporter error is logged
 * (redacted) and never propagates to the caller.
 */
final class MetricRecorder
{
    public function __construct(
        private readonly MetricExporter $metricExporter,
        private readonly TraceExporter $traceExporter,
        private readonly ClinicalContentGuard $guard,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('observability.enabled', false);
    }

    /** @param array<string, int|float|bool|string|null> $attributes */
    public function counter(string $name, int|float $value, array $attributes = [], string $unit = '1'): void
    {
        $this->record($name, 'counter', $value, $attributes, $unit);
    }

    /** @param array<string, int|float|bool|string|null> $attributes */
    public function gauge(string $name, int|float $value, array $attributes = [], string $unit = '1'): void
    {
        $this->record($name, 'gauge', $value, $attributes, $unit);
    }

    /** @param array<string, int|float|bool|string|null> $attributes */
    public function histogram(string $name, int|float $value, array $attributes = [], string $unit = 'ms'): void
    {
        $this->record($name, 'histogram', $value, $attributes, $unit);
    }

    /** @param array<string, int|float|bool|string|null> $attributes */
    public function span(string $name, string $status, int $durationMs, array $attributes = []): void
    {
        if (! $this->enabled()) {
            return;
        }
        try {
            $span = new SpanRecord(
                name: $this->name($name),
                status: in_array($status, ['ok', 'error', 'unset'], true) ? $status : 'unset',
                durationMs: max(0, min(86_400_000, $durationMs)),
                attributes: $this->attributes($attributes),
                startedAtIso: CarbonImmutable::now()->subMilliseconds(max(0, $durationMs))->toIso8601String(),
            );
            $this->traceExporter->export($span);
        } catch (Throwable $exception) {
            $this->fail('span', $name);
        }
    }

    /** @param array<string, int|float|bool|string|null> $attributes */
    private function record(string $name, string $instrument, int|float $value, array $attributes, string $unit): void
    {
        if (! $this->enabled()) {
            return;
        }
        try {
            $sample = new MetricSample(
                name: $this->name($name),
                instrument: $instrument,
                value: $value,
                attributes: $this->attributes($attributes),
                unit: $unit,
                recordedAtIso: CarbonImmutable::now()->toIso8601String(),
            );
            $this->metricExporter->export($sample);
        } catch (Throwable $exception) {
            $this->fail($instrument, $name);
        }
    }

    /** @param array<string, int|float|bool|string|null> $attributes */
    private function attributes(array $attributes): SpanAttributes
    {
        return SpanAttributes::make([
            'service.name' => (string) config('observability.service.name', 'zephyrus'),
            'service.namespace' => (string) config('observability.service.namespace', 'zephyrus'),
            'deployment.environment.name' => (string) config('observability.service.environment', 'production'),
            ...$attributes,
        ], $this->guard);
    }

    private function name(string $name): string
    {
        $name = strtolower(trim($name));

        return preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)*$/', $name) === 1 ? $name : 'zephyrus.unnamed_metric';
    }

    private function fail(string $instrument, string $name): void
    {
        // Never leak the attributes or exception message — only stable codes.
        Log::warning('observability.metric_dropped', ['instrument' => $instrument, 'metric' => $this->name($name)]);
    }
}
