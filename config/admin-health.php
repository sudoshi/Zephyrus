<?php

return [
    /*
    | A reader must never treat an old green observation as current evidence.
    | Scheduled collection runs every minute; three minutes tolerates one
    | delayed tick without concealing a stopped scheduler.
    */
    'fresh_for_seconds' => max(60, (int) env('ADMIN_HEALTH_FRESH_FOR_SECONDS', 180)),

    'queue' => [
        'warning_age_seconds' => max(30, (int) env('ADMIN_HEALTH_QUEUE_WARNING_AGE_SECONDS', 120)),
        'critical_age_seconds' => max(60, (int) env('ADMIN_HEALTH_QUEUE_CRITICAL_AGE_SECONDS', 600)),
        'critical_failed_jobs' => max(1, (int) env('ADMIN_HEALTH_QUEUE_CRITICAL_FAILED_JOBS', 10)),
    ],

    'disk' => [
        'warning_free_percent' => max(1, min(99, (int) env('ADMIN_HEALTH_DISK_WARNING_FREE_PERCENT', 20))),
        'critical_free_percent' => max(1, min(99, (int) env('ADMIN_HEALTH_DISK_CRITICAL_FREE_PERCENT', 10))),
    ],

    'integration' => [
        'critical_open_exceptions' => max(1, (int) env('ADMIN_HEALTH_INTEGRATION_CRITICAL_EXCEPTIONS', 25)),
    ],

    'database' => [
        'expected_replica_count' => max(0, (int) env('ADMIN_HEALTH_EXPECTED_DB_REPLICAS', 0)),
    ],

    // Evidence files are deployment-managed. Their contents are never read or
    // returned; only a bounded file timestamp or X.509 validity window is used.
    'backup' => [
        'evidence_path' => env('ADMIN_HEALTH_BACKUP_EVIDENCE_PATH'),
        'warning_age_hours' => max(1, (int) env('ADMIN_HEALTH_BACKUP_WARNING_AGE_HOURS', 26)),
        'critical_age_hours' => max(2, (int) env('ADMIN_HEALTH_BACKUP_CRITICAL_AGE_HOURS', 48)),
    ],
    'tls' => [
        'certificate_path' => env('ADMIN_HEALTH_TLS_CERTIFICATE_PATH'),
        'warning_days' => max(1, (int) env('ADMIN_HEALTH_TLS_WARNING_DAYS', 30)),
        'critical_days' => max(1, (int) env('ADMIN_HEALTH_TLS_CRITICAL_DAYS', 14)),
    ],

    'arena_signal_warning_minutes' => max(5, (int) env('ADMIN_HEALTH_ARENA_WARNING_MINUTES', 90)),
    'runbook_base_url' => rtrim((string) env('ADMIN_HEALTH_RUNBOOK_BASE_URL', ''), '/'),

    /*
    | Component catalog
    |--------------------------------------------------------------------------
    | This is the display/ownership contract. Probe implementations return only
    | sanitized metrics and stable error codes. Optional disabled subsystems do
    | not make the required platform readiness state green or red.
    */
    'components' => [
        'database' => ['label' => 'Primary database', 'category' => 'Data plane', 'required' => true, 'owner' => 'Platform Engineering', 'runbook' => 'database'],
        'database_replicas' => ['label' => 'Database replicas', 'category' => 'Data plane', 'required' => false, 'owner' => 'Platform Engineering', 'runbook' => 'database-replicas'],
        'queue' => ['label' => 'Queue processing', 'category' => 'Application runtime', 'required' => true, 'owner' => 'Platform Engineering', 'runbook' => 'queue'],
        'scheduler' => ['label' => 'Scheduler heartbeat', 'category' => 'Application runtime', 'required' => true, 'owner' => 'Platform Engineering', 'runbook' => 'scheduler'],
        'cache' => ['label' => 'Cache round trip', 'category' => 'Application runtime', 'required' => true, 'owner' => 'Platform Engineering', 'runbook' => 'cache'],
        'sessions' => ['label' => 'Session security', 'category' => 'Security', 'required' => true, 'owner' => 'Security Engineering', 'runbook' => 'sessions'],
        'integration_runtime' => ['label' => 'Integration runtime', 'category' => 'Interoperability', 'required' => true, 'owner' => 'Integration Operations', 'runbook' => 'integration-runtime'],
        'realtime' => ['label' => 'Realtime broadcasting', 'category' => 'Application runtime', 'required' => false, 'owner' => 'Platform Engineering', 'runbook' => 'realtime'],
        'object_storage' => ['label' => 'Object storage', 'category' => 'Data plane', 'required' => true, 'owner' => 'Platform Engineering', 'runbook' => 'object-storage'],
        'disk_capacity' => ['label' => 'Local disk capacity', 'category' => 'Infrastructure', 'required' => true, 'owner' => 'Platform Engineering', 'runbook' => 'disk-capacity'],
        'backups' => ['label' => 'Backup evidence', 'category' => 'Resilience', 'required' => true, 'owner' => 'Infrastructure Operations', 'runbook' => 'backups'],
        'tls_certificate' => ['label' => 'TLS certificate', 'category' => 'Security', 'required' => true, 'owner' => 'Security Engineering', 'runbook' => 'tls-certificate'],
        'arena' => ['label' => 'Arena process intelligence', 'category' => 'Decision support', 'required' => false, 'owner' => 'Process Intelligence', 'runbook' => 'arena'],
        'eddy' => ['label' => 'Eddy decision support', 'category' => 'Decision support', 'required' => false, 'owner' => 'AI Governance', 'runbook' => 'eddy'],
    ],
];
