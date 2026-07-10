<?php

return [
    'fresh_after_minutes' => (int) env('INTEGRATION_FRESH_AFTER_MINUTES', 15),
    'stale_after_minutes' => (int) env('INTEGRATION_STALE_AFTER_MINUTES', 60),
    'network' => [
        'require_https' => (bool) env('INTEGRATION_REQUIRE_HTTPS', true),
        'allowed_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('INTEGRATION_ALLOWED_HOSTS', '')),
        ))),
        'allowed_ports' => array_map(
            'intval',
            array_values(array_filter(array_map('trim', explode(',', (string) env('INTEGRATION_ALLOWED_PORTS', '443'))))),
        ),
        'require_dns_resolution' => (bool) env('INTEGRATION_REQUIRE_DNS_RESOLUTION', true),
        'allow_cross_host_redirects' => false,
        'max_redirects' => 3,
    ],
    'credential_reference_schemes' => [
        'vault',
        'aws-secretsmanager',
        'gcp-secretmanager',
        'azure-keyvault',
    ],
];
