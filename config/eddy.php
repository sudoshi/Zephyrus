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

];
