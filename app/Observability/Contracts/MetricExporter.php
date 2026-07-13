<?php

namespace App\Observability\Contracts;

use App\Observability\MetricSample;

/**
 * INT-OBS 4 — the in-process metric-export seam.
 *
 * The MetricRecorder hands already-PHI-safe MetricSample values here. The
 * default binding is an in-memory ring buffer; a deployment binds its own OTLP
 * exporter to this contract. An exporter MUST NOT re-derive or enrich
 * attributes with anything that has not passed the safe-attribute contract.
 */
interface MetricExporter
{
    public function export(MetricSample $sample): void;
}
