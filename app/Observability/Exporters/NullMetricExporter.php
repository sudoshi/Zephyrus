<?php

namespace App\Observability\Exporters;

use App\Observability\Contracts\MetricExporter;
use App\Observability\Contracts\TraceExporter;
use App\Observability\MetricSample;
use App\Observability\SpanRecord;

/**
 * INT-OBS 4 — discards all samples/spans. The exporter bound when observability
 * is enabled but no destination is desired.
 */
final class NullMetricExporter implements MetricExporter, TraceExporter
{
    public function export(MetricSample|SpanRecord $record): void
    {
        // Intentionally does nothing.
    }
}
