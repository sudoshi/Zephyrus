<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Nightingale backend foundation
    |--------------------------------------------------------------------------
    |
    | These values reserve a product-owned namespace and record a held
    | candidate. They are deliberately code-owned rather than environment
    | toggles: configuration alone must not be able to register a route,
    | resolve an identity, query an inpatient source, disclose patient data,
    | or enable a native network client.
    |
    */
    'status' => 'foundation',
    'route_namespace' => '/api/nightingale/v1',
    'routes_registered' => false,
    'network_clients_permitted' => false,
    'identity' => [
        'enabled' => false,
        'provider' => null,
        'legacy_patient_realm_reuse_permitted' => false,
    ],
    'inpatient_context' => [
        'enabled' => false,
        'candidate_path' => '/inpatient-contexts',
        'operation_id' => 'listNightingaleInpatientContexts',
        'source_adapter' => null,
        'production_query_permitted' => false,
    ],
    'activation' => [
        'clinical_approval_state' => 'absent',
        'clinical_approval_record' => null,
        'content_release_state' => 'unreleased',
        'content_release_id' => null,
        'feature_activation_state' => 'disabled',
        'pilot_enrollment_state' => 'not_enrolled',
        'source_connector_state' => 'undeployed',
    ],
    'patient_disclosure_enabled' => false,
    'patient_mutation_enabled' => false,
    'production_enabled' => false,
];
