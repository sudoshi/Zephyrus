<?php

namespace App\Observability\Contracts;

use App\Observability\MetricSample;

/**
 * INT-OBS 4 — the in-process metric-export seam.
 *
 * The MetricRecorder hands already-PHI-safe MetricSample values here. The
 * binding may be null, an in-memory ring buffer, or the official OTLP adapter.
 * An exporter MUST NOT re-derive or enrich
 * attributes with anything that has not passed the safe-attribute contract.
 */
interface MetricExporter
{
    public function export(MetricSample $sample): void;
}
