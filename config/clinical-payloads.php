<?php

return [
    'enabled' => (bool) env('CLINICAL_PAYLOADS_ENABLED', false),
    'disk' => env('CLINICAL_PAYLOADS_DISK', 'clinical-payloads'),
    'key_reference' => env('CLINICAL_PAYLOADS_KEK_REF'),
    'compression' => env('CLINICAL_PAYLOADS_COMPRESSION', 'gzip'),
    'compression_level' => max(1, min(9, (int) env('CLINICAL_PAYLOADS_COMPRESSION_LEVEL', 6))),
    'max_plaintext_bytes' => max(1024, (int) env('CLINICAL_PAYLOADS_MAX_BYTES', 10_485_760)),
    'default_retention_policy_key' => env('CLINICAL_PAYLOADS_DEFAULT_RETENTION_POLICY', 'clinical-payload-default'),
    'default_retention_days' => max(1, min(36_500, (int) env('CLINICAL_PAYLOADS_DEFAULT_RETENTION_DAYS', 2555))),
    'allow_local_in_production' => (bool) env('CLINICAL_PAYLOADS_ALLOW_LOCAL_IN_PRODUCTION', false),
    'legacy_read_enabled' => (bool) env('CLINICAL_PAYLOADS_LEGACY_READ_ENABLED', true),
    'integrity_sample_limit' => max(1, min(100, (int) env('CLINICAL_PAYLOADS_INTEGRITY_SAMPLE_LIMIT', 25))),
    'retention_batch_limit' => max(1, min(1000, (int) env('CLINICAL_PAYLOADS_RETENTION_BATCH_LIMIT', 100))),
    'readiness_probe_enabled' => (bool) env('CLINICAL_PAYLOADS_READINESS_PROBE_ENABLED', true),
    'readiness_probe_ttl_seconds' => max(1, min(900, (int) env('CLINICAL_PAYLOADS_READINESS_PROBE_TTL_SECONDS', 60))),
];
