<?php

return [
    'fresh_after_minutes' => (int) env('INTEGRATION_FRESH_AFTER_MINUTES', 15),
    'stale_after_minutes' => (int) env('INTEGRATION_STALE_AFTER_MINUTES', 60),
    'health_timeout_seconds' => max(2, (int) env('INTEGRATION_HEALTH_TIMEOUT_SECONDS', 10)),
    'observability' => [
        'observation_fresh_for_seconds' => max(60, (int) env('INTEGRATION_OBSERVATION_FRESH_SECONDS', 180)),
        'retry_budget_per_run' => max(1, min(20, (int) env('INTEGRATION_RETRY_BUDGET_PER_RUN', 3))),
        'queue' => [
            'warning_depth' => max(1, (int) env('INTEGRATION_QUEUE_WARNING_DEPTH', 25)),
            'critical_depth' => max(1, (int) env('INTEGRATION_QUEUE_CRITICAL_DEPTH', 100)),
            'warning_age_seconds' => max(30, (int) env('INTEGRATION_QUEUE_WARNING_AGE_SECONDS', 120)),
            'critical_age_seconds' => max(30, (int) env('INTEGRATION_QUEUE_CRITICAL_AGE_SECONDS', 600)),
        ],
    ],
    'fhir_page_limit' => max(1, (int) env('INTEGRATION_FHIR_PAGE_LIMIT', 10)),
    'fhir_resource_limit' => max(1, (int) env('INTEGRATION_FHIR_RESOURCE_LIMIT', 1000)),
    'secret_file_root' => env('INTEGRATION_SECRET_FILE_ROOT', '/etc/zephyrus/secrets'),
    'secret_max_bytes' => max(1024, (int) env('INTEGRATION_SECRET_MAX_BYTES', 65_536)),
    'credential_rotation_threshold_days' => [90, 60, 30, 14, 7],
    'secret_providers' => [
        'file' => [
            'enabled' => (bool) env('INTEGRATION_FILE_SECRETS_ENABLED', true),
        ],
        'vault' => [
            'enabled' => (bool) env('INTEGRATION_VAULT_ENABLED', false),
            'base_url' => env('INTEGRATION_VAULT_ADDR'),
            'token' => env('INTEGRATION_VAULT_TOKEN'),
            'namespace' => env('INTEGRATION_VAULT_NAMESPACE'),
            'kv_version' => max(1, min(2, (int) env('INTEGRATION_VAULT_KV_VERSION', 2))),
            'allowed_mounts' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('INTEGRATION_VAULT_ALLOWED_MOUNTS', '')),
            ))),
        ],
        'aws-secretsmanager' => [
            'enabled' => (bool) env('INTEGRATION_AWS_SECRETS_ENABLED', false),
            'access_key_id' => env('INTEGRATION_AWS_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret_access_key' => env('INTEGRATION_AWS_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'session_token' => env('INTEGRATION_AWS_SESSION_TOKEN', env('AWS_SESSION_TOKEN')),
            'allowed_regions' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('INTEGRATION_AWS_ALLOWED_REGIONS', env('AWS_DEFAULT_REGION', ''))),
            ))),
            'endpoint_template' => env(
                'INTEGRATION_AWS_SECRETS_ENDPOINT_TEMPLATE',
                'https://secretsmanager.%s.amazonaws.com',
            ),
        ],
        'gcp-secretmanager' => [
            'enabled' => (bool) env('INTEGRATION_GCP_SECRETS_ENABLED', false),
            'access_token' => env('INTEGRATION_GCP_ACCESS_TOKEN'),
            'credentials_file' => env('INTEGRATION_GCP_CREDENTIALS_FILE'),
            'allowed_projects' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('INTEGRATION_GCP_ALLOWED_PROJECTS', '')),
            ))),
            'api_base_url' => env(
                'INTEGRATION_GCP_SECRET_MANAGER_URL',
                'https://secretmanager.googleapis.com',
            ),
        ],
        'azure-keyvault' => [
            'enabled' => (bool) env('INTEGRATION_AZURE_KEYVAULT_ENABLED', false),
            'access_token' => env('INTEGRATION_AZURE_ACCESS_TOKEN'),
            'tenant_id' => env('INTEGRATION_AZURE_TENANT_ID'),
            'client_id' => env('INTEGRATION_AZURE_CLIENT_ID'),
            'client_secret' => env('INTEGRATION_AZURE_CLIENT_SECRET'),
            'allowed_vaults' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('INTEGRATION_AZURE_ALLOWED_VAULTS', '')),
            ))),
            'authority_host' => env('INTEGRATION_AZURE_AUTHORITY_HOST', 'https://login.microsoftonline.com'),
            'vault_dns_suffix' => env('INTEGRATION_AZURE_VAULT_DNS_SUFFIX', 'vault.azure.net'),
        ],
    ],
    'epic_sandbox' => [
        'source_key' => 'epic.fhir-r4.sandbox',
        'base_url' => env('EPIC_FHIR_BASE_URL', 'https://fhir.epic.com/interconnect-fhir-oauth/api/FHIR/R4'),
        'smart_configuration_url' => env('EPIC_SMART_CONFIGURATION_URL', 'https://fhir.epic.com/interconnect-fhir-oauth/api/FHIR/R4/.well-known/smart-configuration'),
        'token_url' => env('EPIC_FHIR_TOKEN_URL', 'https://fhir.epic.com/interconnect-fhir-oauth/oauth2/token'),
        'default_scopes' => ['system/Encounter.rs', 'system/Location.rs'],
        'client_id' => env('EPIC_FHIR_CLIENT_ID'),
        'private_key_ref' => env('EPIC_FHIR_PRIVATE_KEY_REF'),
        'key_id' => env('EPIC_FHIR_KEY_ID'),
        'facility_key' => env('EPIC_FHIR_FACILITY_KEY'),
    ],
    'synthetic' => [
        'facility_key' => env('SYNTHETIC_INTEGRATION_FACILITY_KEY'),
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
