<?php

/*
|--------------------------------------------------------------------------
| INT-OBS 4 — OpenTelemetry-compatible metrics/trace-attribute contract
|--------------------------------------------------------------------------
|
| The official OpenTelemetry PHP SDK sends guarded metric/span records as
| OTLP/HTTP protobuf when exporter=otlp. It is still default-off. All resource
| and record attributes pass ClinicalContentGuard before reaching the SDK.
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
        'version' => env('OTEL_SERVICE_VERSION', 'unknown'),
        'environment' => env('OTEL_DEPLOYMENT_ENVIRONMENT', env('APP_ENV', 'production')),
    ],

    // memory = bounded local buffer, null = discard, otlp = official OTLP wire.
    'exporter' => env('OBSERVABILITY_EXPORTER', 'memory'),

    // Bounded ring-buffer size for the in-memory exporter — never unbounded.
    'memory_buffer' => max(16, min(4096, (int) env('OBSERVABILITY_MEMORY_BUFFER', 512))),

    'otlp' => [
        // A local Collector agent is the preferred destination. HTTPS is
        // required for non-loopback collectors.
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://127.0.0.1:4318'),
        'metrics_endpoint' => env('OTEL_EXPORTER_OTLP_METRICS_ENDPOINT'),
        'traces_endpoint' => env('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'),
        'allowed_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('OTEL_EXPORTER_OTLP_ALLOWED_HOSTS', '127.0.0.1,::1,localhost')),
        ))),
        // JSON object of header name/value pairs resolved at runtime. Never put
        // bearer/API-key values directly in .env or config cache.
        'headers_secret_ref' => env('OTEL_EXPORTER_OTLP_HEADERS_SECRET_REF'),
        'compression' => env('OTEL_EXPORTER_OTLP_COMPRESSION', 'gzip'),
        'timeout_seconds' => (float) env('OTEL_EXPORTER_OTLP_TIMEOUT_SECONDS', 0.25),
        'retry_delay_ms' => (int) env('OTEL_EXPORTER_OTLP_RETRY_DELAY_MS', 50),
        'max_retries' => (int) env('OTEL_EXPORTER_OTLP_MAX_RETRIES', 0),
    ],
];
