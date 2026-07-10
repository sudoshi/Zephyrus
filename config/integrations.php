<?php

return [
    'fresh_after_minutes' => (int) env('INTEGRATION_FRESH_AFTER_MINUTES', 15),
    'stale_after_minutes' => (int) env('INTEGRATION_STALE_AFTER_MINUTES', 60),
    'health_timeout_seconds' => max(2, (int) env('INTEGRATION_HEALTH_TIMEOUT_SECONDS', 10)),
    'fhir_page_limit' => max(1, (int) env('INTEGRATION_FHIR_PAGE_LIMIT', 10)),
    'fhir_resource_limit' => max(1, (int) env('INTEGRATION_FHIR_RESOURCE_LIMIT', 1000)),
    'secret_file_root' => env('INTEGRATION_SECRET_FILE_ROOT', '/etc/zephyrus/secrets'),
    'epic_sandbox' => [
        'source_key' => 'epic.fhir-r4.sandbox',
        'base_url' => 'https://fhir.epic.com/interconnect-fhir-oauth/api/FHIR/R4',
        'smart_configuration_url' => 'https://fhir.epic.com/interconnect-fhir-oauth/api/FHIR/R4/.well-known/smart-configuration',
        'token_url' => 'https://fhir.epic.com/interconnect-fhir-oauth/oauth2/token',
        'default_scopes' => ['system/Encounter.rs', 'system/Location.rs'],
        'client_id' => env('EPIC_FHIR_CLIENT_ID'),
        'private_key_ref' => env('EPIC_FHIR_PRIVATE_KEY_REF'),
        'key_id' => env('EPIC_FHIR_KEY_ID'),
    ],
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
        'file',
        'vault',
        'aws-secretsmanager',
        'gcp-secretmanager',
        'azure-keyvault',
    ],
];
