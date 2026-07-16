<?php

namespace App\Observability\Exporters;

use App\Observability\Contracts\MetricExporter;
use App\Observability\Contracts\TraceExporter;
use App\Observability\MetricSample;
use App\Observability\SpanRecord;

/**
 * INT-OBS 4 — the default in-process exporter seam.
 *
 * Keeps a bounded ring buffer of the most recent PHI-safe samples/spans so the
 * seam is observable in tests and (optionally) a diagnostics surface without a
 * wire dependency. It is NOT an OTLP exporter and never leaves the process.
 */
final class InMemoryMetricExporter implements MetricExporter, TraceExporter
{
    /** @var list<MetricSample> */
    private array $samples = [];

    /** @var list<SpanRecord> */
    private array $spans = [];

    public function __construct(private readonly int $bufferSize = 512) {}

    public function export(MetricSample|SpanRecord $record): void
    {
        if ($record instanceof MetricSample) {
            $this->samples[] = $record;
            if (count($this->samples) > $this->bufferSize) {
                array_shift($this->samples);
            }

            return;
        }

        $this->spans[] = $record;
        if (count($this->spans) > $this->bufferSize) {
            array_shift($this->spans);
        }
    }

    /** @return list<MetricSample> */
    public function samples(): array
    {
        return $this->samples;
    }

    /** @return list<SpanRecord> */
    public function spans(): array
    {
        return $this->spans;
    }

    public function flush(): void
    {
        $this->samples = [];
        $this->spans = [];
    }
}
