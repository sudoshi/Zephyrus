<?php

namespace App\Observability\Contracts;

use App\Observability\SpanRecord;

/**
 * INT-OBS 4 — the in-process trace-export seam.
 *
 * The MetricRecorder hands already-PHI-safe SpanRecord values here. Correlation
 * from receipt through projection is carried on the span's attributes
 * (request.id + canonical event UUID). A deployment binds its own OTLP span
 * exporter to this contract; this repository ships only the seam.
 */
interface TraceExporter
{
    public function export(SpanRecord $span): void;
}
