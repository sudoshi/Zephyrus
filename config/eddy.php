<?php

/*
|--------------------------------------------------------------------------
| Eddy — Process-Aware AI Agent
|--------------------------------------------------------------------------
|
| Operational config for Eddy on the Laravel side. The connection details
| (URL/timeout/secrets) live in config/services.php under 'eddy'. This file
| holds routing posture, budgets, PHI flags and model defaults that the proxy
| and the admin surfaces read. Eddy ships DISABLED and LOCAL-ONLY by default;
| frontier egress is a deliberate admin act gated on a BAA + per-surface policy.
|
*/

return [

    // Master kill-switch for any frontier (cloud) egress, independent of any
    // per-surface policy. While false, Eddy is local-only end to end.
    'allow_cloud' => filter_var(env('EDDY_ALLOW_CLOUD', false), FILTER_VALIDATE_BOOL),

    // Default provider mode applied when a surface has no explicit policy row.
    // local_only | local_first | cloud_first | auto_by_complexity | auto_by_budget
    'default_provider_mode' => env('EDDY_DEFAULT_PROVIDER_MODE', 'local_first'),

    // PHI egress guards — a detected identifier hard-blocks the cloud call and
    // forces the local model, never "best-effort redacts and sends".
    'phi' => [
        'detection_enabled' => filter_var(env('EDDY_PHI_DETECTION_ENABLED', true), FILTER_VALIDATE_BOOL),
        'block_on_detection' => filter_var(env('EDDY_PHI_BLOCK_ON_DETECTION', true), FILTER_VALIDATE_BOOL),
    ],

    // Cloud spend circuit breaker. When monthly spend crosses cutoff x budget,
    // the chat router force-locals (keeps Eddy answering on MedGemma).
    'budget' => [
        'monthly_usd' => (float) env('EDDY_CLOUD_MONTHLY_BUDGET_USD', 500.0),
        'cutoff_threshold' => (float) env('EDDY_CLOUD_BUDGET_CUTOFF_THRESHOLD', 0.95),
        'alert_thresholds' => array_values(array_filter(array_map(
            static fn ($v) => is_numeric(trim((string) $v)) ? (float) trim((string) $v) : null,
            explode(',', (string) env('EDDY_CLOUD_BUDGET_ALERT_THRESHOLDS', '0.50,0.80,0.95'))
        ), static fn ($v) => $v !== null)),
    ],

    // Model defaults (current IDs — do NOT append a date suffix). The agent loop
    // (subsystem B) defaults to Opus; chat (subsystem A) cloud to Sonnet.
    'models' => [
        'agent_cloud' => env('EDDY_AGENT_MODEL', 'claude-opus-4-8'),
        'agent_effort' => env('EDDY_AGENT_EFFORT', 'xhigh'),
        'chat_cloud' => env('EDDY_CLOUD_CHAT_MODEL', 'claude-sonnet-4-6'),
        'chat_local' => env('EDDY_OLLAMA_MODEL', 'puyangwang/medgemma-27b-it:q4_0'),
        // Agent loop reaches Ollama only through an Anthropic-compatible proxy and
        // uses a tool-calling model (NOT MedGemma, which is not a reliable caller).
        'agent_local' => env('EDDY_AGENT_LOCAL_MODEL', 'qwen2.5-coder:32b'),
    ],

    // Scoped-token abilities minted to the Eddy agent. ops:approve is NEVER minted
    // to Eddy — humans approve. (Enforced again by the approve route middleware.)
    'abilities' => [
        'read' => ['ops:read'],
        'write' => ['ops:read', 'ops:draft'],
    ],

    // Mobile (Hummingbird) push — the PHI-free "Eddy has a suggestion" doorbell.
    // The doorbell carries ONLY {approval_uuid, action_uuid, action_type, surface,
    // tier, deep_link} — never params, rationale, or any clinical/patient detail.
    // The native app fetches the dry-run on open over the biometric-gated token.
    'push' => [
        // When false, an Eddy proposal lands pending in the inbox without ringing
        // the doorbell (the seam stays inert until mobile push is provisioned).
        'enabled' => filter_var(env('EDDY_PUSH_ENABLED', false), FILTER_VALIDATE_BOOL),

        // Deep link the native app routes on tap (mirrors the web /ops agent-inbox).
        'deep_link' => env('EDDY_PUSH_DEEP_LINK', 'zephyrus://eddy/approvals'),

        // Action-catalog risk → notification tier. Tier-1 is an iOS Critical Alert /
        // high-priority FCM channel, RESERVED for genuine capacity breaches (earned
        // urgency). Everything routine is Tier-2/3. Derived server-side; the app is
        // presentation-only. Keys are the EddyActionService::CATALOG risk levels.
        'tier_by_risk' => [
            'critical' => 'tier_1',
            'high' => 'tier_1',
            'medium' => 'tier_2',
            'low' => 'tier_3',
        ],
    ],

    // Phase 6 — institutional knowledge RAG. The schema gets an `embedding vector(N)`
    // column iff pgvector is present (the migration adds it); this flag gates whether
    // retrieval actually computes/uses embeddings. OFF → deterministic keyword/FTS
    // (the Phase 2 path) still works. The model must emit `dimensions`-wide vectors.
    'embeddings' => [
        'enabled' => filter_var(env('EDDY_EMBEDDINGS_ENABLED', false), FILTER_VALIDATE_BOOL),
        'model' => env('EDDY_EMBEDDING_MODEL', 'nomic-embed-text'),
        'dimensions' => (int) env('EDDY_EMBEDDING_DIMENSIONS', 768),
        // Weight blending the cosine similarity (0..1) with the keyword overlap when
        // both are available. 1.0 = pure vector, 0.0 = pure keyword.
        'vector_weight' => (float) env('EDDY_EMBEDDING_VECTOR_WEIGHT', 0.7),
    ],

    // Phase 6 — preference learning. Human approve/reject/override of Eddy proposals
    // rolls up into per-user action preferences injected into the chat envelope so
    // Eddy's runner-up ordering shifts toward what this user actually sanctions.
    'learning' => [
        'enabled' => filter_var(env('EDDY_LEARNING_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],

];
