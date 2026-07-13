<?php

/*
|--------------------------------------------------------------------------
| INT-OBS 4 — OpenTelemetry-compatible metrics/trace-attribute contract
|--------------------------------------------------------------------------
|
| This is the PHI-safe metrics/trace SEAM only. It defines the safe-attribute
| contract and an in-process exporter binding; it does NOT ship an OTLP wire
| exporter and adds no composer dependency. A real OTLP exporter is
| deployment-owned and must be bound to App\Observability\Contracts\MetricExporter
| / TraceExporter and must consume this same safe-attribute contract (every
| attribute passes App\Security\ClinicalPayloads\ClinicalContentGuard) before it
| may be enabled.
|
| Default OFF: with 'enabled' => false the recorder is a guarded no-op and no
| exporter is invoked.
|
*/

return [
    // Master gate. Off by default and in tests.
    'enabled' => (bool) env('OBSERVABILITY_METRICS_ENABLED', false),

    // The service.* resource attributes an OTLP exporter would stamp on every
    // metric/span. PHI-free deployment identity only.
    'service' => [
        'name' => env('OTEL_SERVICE_NAME', 'zephyrus'),
        'namespace' => env('OTEL_SERVICE_NAMESPACE', 'zephyrus'),
        'environment' => env('OTEL_DEPLOYMENT_ENVIRONMENT', env('APP_ENV', 'production')),
    ],

    // The in-process exporter seam. 'memory' keeps a bounded ring buffer for the
    // health surface/tests; 'null' discards. A deployment binds its own OTLP
    // exporter to the MetricExporter/TraceExporter contracts instead.
    'exporter' => env('OBSERVABILITY_EXPORTER', 'memory'),

    // Bounded ring-buffer size for the in-memory exporter — never unbounded.
    'memory_buffer' => max(16, min(4096, (int) env('OBSERVABILITY_MEMORY_BUFFER', 512))),
];
