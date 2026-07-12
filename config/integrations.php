<?php

$ancillaryFhirEnabled = (bool) env('INTEGRATION_ENABLE_ANCILLARY_FHIR', false);

return [
    'fresh_after_minutes' => (int) env('INTEGRATION_FRESH_AFTER_MINUTES', 15),
    'stale_after_minutes' => (int) env('INTEGRATION_STALE_AFTER_MINUTES', 60),
    'health_timeout_seconds' => max(2, (int) env('INTEGRATION_HEALTH_TIMEOUT_SECONDS', 10)),
    'fhir_page_limit' => max(1, (int) env('INTEGRATION_FHIR_PAGE_LIMIT', 10)),
    'fhir_resource_limit' => max(1, (int) env('INTEGRATION_FHIR_RESOURCE_LIMIT', 1000)),
    'ancillary' => [
        'assertion_conflict_tolerance_seconds' => max(0, (int) env('ANCILLARY_ASSERTION_CONFLICT_TOLERANCE_SECONDS', 300)),
        'clock_timezone' => env('ANCILLARY_CLOCK_TIMEZONE', 'UTC'),
        'max_message_bytes' => max(1024, (int) env('ANCILLARY_MAX_MESSAGE_BYTES', 262144)),
        'bulk_max_records' => max(1, (int) env('ANCILLARY_BULK_MAX_RECORDS', 500)),
    ],
    'fhir_resources' => [
        'Encounter' => ['enabled' => true, 'scope' => 'system/Encounter.rs', 'family' => 'patient_flow'],
        'Location' => ['enabled' => true, 'scope' => 'system/Location.rs', 'family' => 'patient_flow'],
        'ServiceRequest' => ['enabled' => $ancillaryFhirEnabled, 'scope' => 'system/ServiceRequest.rs', 'family' => 'ancillary'],
        'Appointment' => ['enabled' => $ancillaryFhirEnabled, 'scope' => 'system/Appointment.rs', 'family' => 'ancillary'],
        'ImagingStudy' => ['enabled' => $ancillaryFhirEnabled, 'scope' => 'system/ImagingStudy.rs', 'family' => 'ancillary'],
        'DiagnosticReport' => ['enabled' => $ancillaryFhirEnabled, 'scope' => 'system/DiagnosticReport.rs', 'family' => 'ancillary'],
        'Specimen' => ['enabled' => $ancillaryFhirEnabled, 'scope' => 'system/Specimen.rs', 'family' => 'ancillary'],
        'Observation' => ['enabled' => $ancillaryFhirEnabled, 'scope' => 'system/Observation.rs', 'family' => 'ancillary'],
        'MedicationRequest' => ['enabled' => $ancillaryFhirEnabled, 'scope' => 'system/MedicationRequest.rs', 'family' => 'ancillary'],
        'MedicationDispense' => ['enabled' => $ancillaryFhirEnabled, 'scope' => 'system/MedicationDispense.rs', 'family' => 'ancillary'],
    ],
    'secret_file_root' => env('INTEGRATION_SECRET_FILE_ROOT', '/etc/zephyrus/secrets'),
    'epic_sandbox' => [
        'source_key' => 'epic.fhir-r4.sandbox',
        'base_url' => 'https://fhir.epic.com/interconnect-fhir-oauth/api/FHIR/R4',
        'smart_configuration_url' => 'https://fhir.epic.com/interconnect-fhir-oauth/api/FHIR/R4/.well-known/smart-configuration',
        'token_url' => 'https://fhir.epic.com/interconnect-fhir-oauth/oauth2/token',
        'default_scopes' => array_values(array_filter([
            'system/Encounter.rs',
            'system/Location.rs',
            $ancillaryFhirEnabled ? 'system/ServiceRequest.rs' : null,
            $ancillaryFhirEnabled ? 'system/ImagingStudy.rs' : null,
            $ancillaryFhirEnabled ? 'system/DiagnosticReport.rs' : null,
            $ancillaryFhirEnabled ? 'system/Specimen.rs' : null,
            $ancillaryFhirEnabled ? 'system/Observation.rs' : null,
            $ancillaryFhirEnabled ? 'system/MedicationRequest.rs' : null,
            $ancillaryFhirEnabled ? 'system/MedicationDispense.rs' : null,
        ])),
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
