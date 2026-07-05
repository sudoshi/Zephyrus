<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    // P6 cockpit alert fan-out — Teams lane. Empty = channel inert.
    'teams' => [
        'alert_webhook_url' => env('TEAMS_ALERT_WEBHOOK_URL', ''),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'oidc' => [
        'enabled' => filter_var(env('OIDC_ENABLED', false), FILTER_VALIDATE_BOOL),
        'discovery_url' => env('OIDC_DISCOVERY_URL', 'https://auth.acumenus.net/application/o/zephyrus-oidc/.well-known/openid-configuration'),
        'client_id' => env('OIDC_CLIENT_ID', ''),
        'client_secret' => env('OIDC_CLIENT_SECRET', ''),
        'redirect_uri' => env('OIDC_REDIRECT_URI', 'https://zephyrus.acumenus.net/auth/oidc/callback'),
        'scopes' => ['openid', 'profile', 'email', 'groups'],
        'allowed_groups' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('OIDC_ALLOWED_GROUPS', 'Zephyrus Users'))
        ))),
        'admin_groups' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('OIDC_ADMIN_GROUPS', 'Zephyrus Admins'))
        ))),
    ],

    // Eddy — the process-aware AI agent. The browser never calls Eddy directly;
    // the SPA calls Laravel /api/eddy/*, and Laravel proxies here server-side.
    // Provider secrets (Anthropic key, etc.) live ONLY in the Eddy service env.
    'eddy' => [
        'enabled' => filter_var(env('EDDY_ENABLED', false), FILTER_VALIDATE_BOOL),
        'url' => env('EDDY_BASE_URL', 'http://eddy:8000'),
        'timeout' => (int) env('EDDY_TIMEOUT_SECONDS', 30),
        'shared_secret' => env('EDDY_SHARED_SECRET'),   // HMAC for Laravel<->Eddy request bodies
        'callback_token' => env('EDDY_CALLBACK_TOKEN'), // bearer Eddy uses on non-user telemetry callbacks
    ],

    // Arena — the Part X OCPM sidecar. The browser never calls it directly; the
    // SPA calls Laravel /api/arena/*, and Laravel proxies here server-side,
    // caching the discovered maps in arena.maps. The sidecar is stateless and
    // PHI-free; it reads a de-identified OCEL export Laravel posts inline.
    'arena' => [
        'enabled' => filter_var(env('ARENA_ENABLED', false), FILTER_VALIDATE_BOOL),
        'url' => env('ARENA_BASE_URL', 'http://arena:8100'),
        'timeout' => (int) env('ARENA_TIMEOUT_SECONDS', 60),
        'cache_ttl' => (int) env('ARENA_CACHE_TTL_SECONDS', 900), // discovered-map cache lifetime

        // Part X (X4) — the governed AI copilot. Independent of ARENA_ENABLED so the
        // deterministic discovery/analytics can run with the AI author switched
        // entirely off (the conservative default). The copilot holds ops:draft and
        // never ops:approve; every generated map below the fitness floor is withheld.
        'ai_enabled' => filter_var(env('ARENA_AI_ENABLED', false), FILTER_VALIDATE_BOOL),
        'ai_fitness_floor' => (float) env('ARENA_AI_FITNESS_FLOOR', 0.80), // withhold a proposed map below this DFG-fitness
    ],

];
