<?php

namespace App\Observability\Exporters;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Throwable;

/**
 * Converts the SDK exporter's asynchronous result into a stable success signal.
 *
 * The official OTLP exporter intentionally catches transport failures and returns
 * false. Tracking that result lets the application recorder emit its own bounded,
 * PHI-free failure code without logging the SDK exception or request context.
 */
final class SafeSpanExporter implements SpanExporterInterface
{
    private bool $lastExportSucceeded = true;

    public function __construct(private readonly SpanExporterInterface $inner) {}

    public function beginExport(): void
    {
        $this->lastExportSucceeded = true;
    }

    public function lastExportSucceeded(): bool
    {
        return $this->lastExportSucceeded;
    }

    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        return $this->inner->export($batch, $cancellation)
            ->map(function (mixed $result): bool {
                return $this->lastExportSucceeded = $result === true;
            })
            ->catch(function (Throwable $exception): bool {
                $this->lastExportSucceeded = false;

                return false;
            });
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->inner->shutdown($cancellation);
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->inner->forceFlush($cancellation);
    }
}
