<?php

use App\Services\Patient\Messaging\DatabasePatientMessageHandoffConsumer;

return [

    /*
    |--------------------------------------------------------------------------
    | Hummingbird Patient product boundary
    |--------------------------------------------------------------------------
    |
    | The patient application is a separate product, identity realm, API, and
    | disclosure boundary. Every feature is fail-closed by default. Enabling
    | the staff Hummingbird application never enables any patient capability.
    |
    */

    'enabled' => (bool) env('HUMMINGBIRD_PATIENT_ENABLED', false),

    // Dedicated domain key for patient-realm lookup and audit digests. This
    // must not reuse APP_KEY. PatientHmac supplies a deterministic fallback in
    // APP_ENV=testing only and fails closed everywhere else.
    'hmac_secret' => env('HUMMINGBIRD_PATIENT_HMAC_SECRET'),

    'policy_version' => env('HUMMINGBIRD_PATIENT_POLICY_VERSION', 'patient-disclosure-v1-draft'),

    'features' => [
        'enrollment' => (bool) env('HUMMINGBIRD_PATIENT_ENROLLMENT_ENABLED', false),
        'token_exchange' => (bool) env('HUMMINGBIRD_PATIENT_TOKEN_EXCHANGE_ENABLED', false),
        'profile' => (bool) env('HUMMINGBIRD_PATIENT_PROFILE_ENABLED', false),
        'session_management' => (bool) env('HUMMINGBIRD_PATIENT_SESSION_MANAGEMENT_ENABLED', false),
        // Registers an encrypted, revocable provider token only. This does
        // not enable a notification provider, payload delivery, or push.
        'notification_devices' => (bool) env('HUMMINGBIRD_PATIENT_NOTIFICATION_DEVICES_ENABLED', false),
        'encounters' => (bool) env('HUMMINGBIRD_PATIENT_ENCOUNTERS_ENABLED', false),
        'today' => (bool) env('HUMMINGBIRD_PATIENT_TODAY_ENABLED', false),
        'pathway' => (bool) env('HUMMINGBIRD_PATIENT_PATHWAY_ENABLED', false),
        // Produces a governed *draft* My Path projection from already
        // version-pinned, append-only pathway history. This cannot release a
        // projection or substitute for an approved production source adapter.
        'pathway_history_drafts' => (bool) env('HUMMINGBIRD_PATIENT_PATHWAY_HISTORY_DRAFTS_ENABLED', false),
        // Admits only an allowlisted, connector-internal pathway status
        // snapshot. It appends history only and cannot release a projection.
        'pathway_source_reconciliation' => (bool) env('HUMMINGBIRD_PATIENT_PATHWAY_SOURCE_RECONCILIATION_ENABLED', false),
        // Requires a separate clinical review and a different catalog release
        // manager before one pathway draft can become patient-visible.
        'pathway_history_releases' => (bool) env('HUMMINGBIRD_PATIENT_PATHWAY_HISTORY_RELEASES_ENABLED', false),
        // Allows a patient to request clarification about an education item
        // that is already present in their currently released pathway. This
        // creates an accountable message only; it never records completion,
        // comprehension, consent, or a clinician assessment.
        'teach_back' => (bool) env('HUMMINGBIRD_PATIENT_TEACH_BACK_ENABLED', false),
        // Persists a content-free, patient-authored care-preference association
        // beside the existing encrypted accountable message. It never writes
        // or amends a clinical care plan, order, consent, or assessment.
        'care_preferences' => (bool) env('HUMMINGBIRD_PATIENT_CARE_PREFERENCES_ENABLED', false),
        // Persists a content-free patient-authored personal-goal association
        // beside its encrypted accountable message. It is never a clinical
        // goal, care-plan change, order, consent, or assessment.
        'patient_goals' => (bool) env('HUMMINGBIRD_PATIENT_GOALS_ENABLED', false),
        // Allows only the explicitly approved `rounds_question` messaging
        // topic. It does not grant access to the staff virtual-rounds API.
        'rounds_questions' => (bool) env('HUMMINGBIRD_PATIENT_ROUNDS_QUESTIONS_ENABLED', false),
        // A released, plain-language patient summary of rounds. This never
        // enables or proxies the staff virtual-rounds workspace.
        'rounds_summary' => (bool) env('HUMMINGBIRD_PATIENT_ROUNDS_SUMMARY_ENABLED', false),
        'care_team' => (bool) env('HUMMINGBIRD_PATIENT_CARE_TEAM_ENABLED', false),
        'messaging' => (bool) env('HUMMINGBIRD_PATIENT_MESSAGING_ENABLED', false),
    ],

    'token' => [
        'access_ttl_minutes' => max(1, (int) env('HUMMINGBIRD_PATIENT_ACCESS_TTL_MINUTES', 15)),
        'refresh_ttl_days' => max(1, (int) env('HUMMINGBIRD_PATIENT_REFRESH_TTL_DAYS', 14)),
    ],

    'enrollment' => [
        'max_attempts' => max(1, (int) env('HUMMINGBIRD_PATIENT_ENROLLMENT_MAX_ATTEMPTS', 5)),
    ],

    'notification_devices' => [
        // This key ring is deliberately independent of APP_KEY and messaging
        // body encryption. The registered provider token is never included in
        // API responses, audit metadata, logs, or notification payloads.
        'encryption_key_version' => env('HUMMINGBIRD_PATIENT_NOTIFICATION_DEVICE_ENCRYPTION_KEY_VERSION'),
        'encryption_key' => env('HUMMINGBIRD_PATIENT_NOTIFICATION_DEVICE_ENCRYPTION_KEY'),
        'previous_encryption_keys_json' => env(
            'HUMMINGBIRD_PATIENT_NOTIFICATION_DEVICE_PREVIOUS_ENCRYPTION_KEYS_JSON',
        ),
    ],

    'reference_provisioning' => [
        // Fail closed. Enable only in the deployed application runtime that
        // owns the configured APP_KEY and dedicated patient HMAC secret.
        'enabled' => (bool) env('HUMMINGBIRD_PATIENT_REFERENCE_PROVISIONING_ENABLED', false),
        'encryption_key_version' => env('HUMMINGBIRD_PATIENT_REFERENCE_ENCRYPTION_KEY_VERSION'),
        'challenge_ttl_minutes' => max(5, min(30, (int) env(
            'HUMMINGBIRD_PATIENT_REFERENCE_CHALLENGE_TTL_MINUTES',
            10,
        ))),
    ],

    'pathway_history_drafts' => [
        // These values deliberately describe the producer, not a source
        // identifier. They are used only in protected cursor/provenance facts.
        'source_system_key' => 'care-pathways.pathway-history-v1',
        'producer_version' => 'patient-pathway-history-draft-v1',
        'current_after_minutes' => max(1, (int) env(
            'HUMMINGBIRD_PATIENT_PATHWAY_HISTORY_CURRENT_AFTER_MINUTES',
            30,
        )),
        'stale_after_minutes' => max(2, (int) env(
            'HUMMINGBIRD_PATIENT_PATHWAY_HISTORY_STALE_AFTER_MINUTES',
            240,
        )),
    ],

    'pathway_source_reconciliation' => [
        // No source is approved by default. Add a versioned key only through
        // reviewed deployment code after clinical, privacy, and integration
        // owners have approved its source contract. Never make this an env
        // list: an arbitrary environment value must not authorize a source.
        'approved_sources' => [],
    ],

    'pathway_history_releases' => [
        'producer_version' => 'patient-pathway-history-clinical-release-v1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Patient-to-care-team messaging safety gate
    |--------------------------------------------------------------------------
    |
    | The feature flag is necessary but not sufficient. The registry refuses
    | service unless this separate policy is explicitly approved and every
    | patient-visible safety/response string is populated. Candidate topics
    | are code-owned to prevent an arbitrary environment value from becoming
    | an unreviewed routing destination.
    |
    */

    'messaging' => [
        'governance_status' => env(
            'HUMMINGBIRD_PATIENT_MESSAGING_POLICY_STATUS',
            'draft_requires_approval',
        ),
        'policy_version' => env('HUMMINGBIRD_PATIENT_MESSAGING_POLICY_VERSION'),
        'urgent_guidance_version' => env('HUMMINGBIRD_PATIENT_MESSAGING_URGENT_GUIDANCE_VERSION'),
        'urgent_guidance_text' => env('HUMMINGBIRD_PATIENT_MESSAGING_URGENT_GUIDANCE_TEXT'),
        'default_response_window' => env('HUMMINGBIRD_PATIENT_MESSAGING_DEFAULT_RESPONSE_WINDOW'),
        'encryption_key_version' => env('HUMMINGBIRD_PATIENT_MESSAGING_ENCRYPTION_KEY_VERSION'),
        'encryption_key' => env('HUMMINGBIRD_PATIENT_MESSAGING_ENCRYPTION_KEY'),
        'previous_encryption_keys_json' => env(
            'HUMMINGBIRD_PATIENT_MESSAGING_PREVIOUS_ENCRYPTION_KEYS_JSON',
        ),
        'handoff_consumer' => env(
            'HUMMINGBIRD_PATIENT_MESSAGING_HANDOFF_CONSUMER',
            DatabasePatientMessageHandoffConsumer::class,
        ),
        'topics' => [
            'rounds_question' => [
                'label' => 'Question for care-team rounds',
                'description' => 'Share a non-urgent question your care team may review before a care conversation. Sending it does not promise it will be discussed in a particular round.',
                'responsibility_pool_key' => 'encounter.care_coordination',
            ],
            'nursing_need' => [
                'label' => 'Nursing question or need',
                'description' => 'Ask the nursing team about non-urgent care needs during this stay.',
                'responsibility_pool_key' => 'encounter.nursing',
            ],
            'medication_question' => [
                'label' => 'Medication question',
                'description' => 'Ask the care team a non-urgent question about your medicines.',
                'responsibility_pool_key' => 'encounter.medication',
            ],
            'test_or_procedure' => [
                'label' => 'Test or procedure question',
                'description' => 'Ask about preparation or next steps for a test or procedure.',
                'responsibility_pool_key' => 'encounter.tests_procedures',
            ],
            'discharge_planning' => [
                'label' => 'Discharge planning',
                'description' => 'Ask about preparing to leave the hospital and what comes next.',
                'responsibility_pool_key' => 'encounter.discharge_planning',
            ],
            'therapy_or_mobility' => [
                'label' => 'Therapy or mobility',
                'description' => 'Ask a non-urgent question about therapy, movement, or mobility.',
                'responsibility_pool_key' => 'encounter.therapy_mobility',
            ],
            'nutrition' => [
                'label' => 'Nutrition',
                'description' => 'Ask a non-urgent question about meals or nutrition during this stay.',
                'responsibility_pool_key' => 'encounter.nutrition',
            ],
            'interpreter' => [
                'label' => 'Interpreter or language help',
                'description' => 'Ask the care team for approved language or communication support.',
                'responsibility_pool_key' => 'encounter.language_access',
            ],
            'care_coordination' => [
                'label' => 'Care coordination',
                'description' => 'Ask a non-urgent question about how your care is being coordinated.',
                'responsibility_pool_key' => 'encounter.care_coordination',
            ],
            // This captures a patient-authored preference as an
            // encrypted, accountable message. It deliberately does not
            // create or amend a clinical care-plan record or order.
            'care_preference' => [
                'label' => 'What matters to you',
                'description' => 'Share a non-urgent preference for this hospital stay. Your care team can review it, but it does not change your care plan or create a clinical order on its own.',
                'responsibility_pool_key' => 'encounter.care_coordination',
            ],
            'patient_goal' => [
                'label' => 'A personal goal for my stay',
                'description' => 'Share a non-urgent personal goal for this hospital stay. Your care team can review it, but it does not change your care plan or create a clinical order on its own.',
                'responsibility_pool_key' => 'encounter.care_coordination',
            ],
            // This topic is intentionally not listed in the general composer
            // and cannot be used through the ordinary thread endpoint. The
            // dedicated clarification endpoint verifies that its opaque item
            // UUID belongs to a currently released pathway education item.
            'education_clarification' => [
                'label' => 'Question about care information',
                'description' => 'Ask the care team to explain information shown in your care pathway. Sending a question does not confirm you understand it or complete an education task.',
                'responsibility_pool_key' => 'encounter.care_coordination',
                'composition_mode' => 'released_education_only',
            ],
            'technical_help' => [
                'label' => 'App help',
                'description' => 'Ask for help using Hummingbird Patient.',
                'responsibility_pool_key' => 'patient_app.technical_support',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Accountable staff-message handoff
    |--------------------------------------------------------------------------
    |
    | This is an independent kill switch and governance gate. Patient compose
    | remains unavailable until every approved pilot unit has an effective
    | topic pool, an eligible responder, and a recently healthy consumer.
    |
    */

    'staff_messaging' => [
        'enabled' => (bool) env('HUMMINGBIRD_PATIENT_STAFF_MESSAGING_ENABLED', false),
        'governance_status' => env(
            'HUMMINGBIRD_PATIENT_STAFF_MESSAGING_POLICY_STATUS',
            'draft_requires_approval',
        ),
        'consumer_key' => 'patient-message-staff-inbox-v1',
        'pilot_unit_ids' => array_values(array_filter(
            array_map(
                static fn (string $value): int => (int) trim($value),
                explode(',', (string) env('HUMMINGBIRD_PATIENT_STAFF_MESSAGING_PILOT_UNIT_IDS', '')),
            ),
            static fn (int $value): bool => $value > 0,
        )),
        'heartbeat_ttl_seconds' => max(
            30,
            min(600, (int) env('HUMMINGBIRD_PATIENT_STAFF_MESSAGING_HEARTBEAT_TTL_SECONDS', 120)),
        ),
        'batch_size' => max(
            1,
            min(500, (int) env('HUMMINGBIRD_PATIENT_STAFF_MESSAGING_BATCH_SIZE', 100)),
        ),
    ],
];
