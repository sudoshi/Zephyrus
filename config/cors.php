<?php

$allowedOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Idempotency-Key',
        'Origin',
        'X-Correlation-ID',
        'X-CSRF-TOKEN',
        'X-Integration-Source',
        'X-Request-ID',
        'X-Requested-With',
        'X-XSRF-TOKEN',
    ],
    'exposed_headers' => ['Retry-After', 'X-Correlation-ID', 'X-Request-ID'],
    'max_age' => (int) env('CORS_MAX_AGE', 600),
    'supports_credentials' => filter_var(
        env('CORS_SUPPORTS_CREDENTIALS', false),
        FILTER_VALIDATE_BOOL,
    ),
];
