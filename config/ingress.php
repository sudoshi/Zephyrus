<?php

return [
    // JSON command bodies are bounded globally; explicitly large workflows get
    // their own narrowly scoped ceiling below.
    'api_max_bytes' => max(1024, (int) env('API_MAX_REQUEST_BYTES', 1_048_576)),
    'staffing_import_max_bytes' => max(1024, (int) env('STAFFING_IMPORT_MAX_REQUEST_BYTES', 10_485_760)),

    'rate_limits' => [
        'public_health_per_minute' => max(1, (int) env('RATE_LIMIT_PUBLIC_HEALTH', 30)),
        'credential_exchange_per_minute' => max(1, (int) env('RATE_LIMIT_CREDENTIAL_EXCHANGE', 5)),
        'web_api_per_minute' => max(1, (int) env('RATE_LIMIT_WEB_API', 120)),
        'sensitive_web_api_per_minute' => max(1, (int) env('RATE_LIMIT_SENSITIVE_WEB_API', 30)),
        'machine_ingest_per_minute' => max(1, (int) env('RATE_LIMIT_MACHINE_INGEST', 120)),
        'machine_agent_per_minute' => max(1, (int) env('RATE_LIMIT_MACHINE_AGENT', 60)),
        'mobile_auth_per_minute' => max(1, (int) env('RATE_LIMIT_MOBILE_AUTH', 60)),
        'mobile_api_per_minute' => max(1, (int) env('RATE_LIMIT_MOBILE_API', 120)),
        'patient_enrollment_per_minute' => max(1, (int) env('RATE_LIMIT_PATIENT_ENROLLMENT', 5)),
        'patient_credential_exchange_per_minute' => max(1, (int) env('RATE_LIMIT_PATIENT_CREDENTIAL_EXCHANGE', 5)),
        'patient_auth_per_minute' => max(1, (int) env('RATE_LIMIT_PATIENT_AUTH', 30)),
        'patient_api_per_minute' => max(1, (int) env('RATE_LIMIT_PATIENT_API', 90)),
    ],
];
