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

];
