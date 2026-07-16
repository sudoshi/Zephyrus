<?php

$csv = static fn (string $value): array => array_values(array_filter(array_map(
    static fn (string $item): string => trim($item),
    explode(',', $value),
)));

$connectSources = array_values(array_unique([
    "'self'",
    ...$csv((string) env('CSP_CONNECT_SOURCES', '')),
]));

return [
    'step_up' => [
        'ttl_seconds' => (int) env('STEP_UP_TTL_SECONDS', 600),
        'oidc_auth_time_max_age_seconds' => (int) env('OIDC_STEP_UP_MAX_AGE_SECONDS', 300),
        'oidc_mfa_amr' => $csv((string) env('OIDC_STEP_UP_AMR', 'mfa,otp,hwk,webauthn')),
        'oidc_mfa_acr' => $csv((string) env('OIDC_STEP_UP_ACR', '')),
    ],

    'governed_changes' => [
        'ttl_seconds' => (int) env('GOVERNED_CHANGE_TTL_SECONDS', 604800),
    ],

    'content_security_policy' => [
        'enabled' => filter_var(env('CSP_ENABLED', true), FILTER_VALIDATE_BOOL),
        'report_only' => filter_var(env('CSP_REPORT_ONLY', false), FILTER_VALIDATE_BOOL),
        'directives' => [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
            'frame-src' => ["'none'"],
            'object-src' => ["'none'"],
            'script-src' => ["'self'"],
            // React/chart libraries still emit element style attributes. Script
            // execution remains nonce-only; style-src can be tightened later.
            'style-src' => [
                "'self'",
                "'unsafe-inline'",
                'https://fonts.bunny.net',
                'https://fonts.googleapis.com',
            ],
            'font-src' => [
                "'self'",
                'data:',
                'https://fonts.bunny.net',
                'https://fonts.gstatic.com',
            ],
            'img-src' => ["'self'", 'data:', 'blob:'],
            'connect-src' => $connectSources,
            'media-src' => ["'self'", 'blob:'],
            'worker-src' => ["'self'", 'blob:'],
            'manifest-src' => ["'self'"],
        ],
    ],
];
