<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hummingbird mobile companion
    |--------------------------------------------------------------------------
    |
    | Configuration for the additive mobile-companion backend (Phase 0). None of
    | this affects the web session-cookie auth flow.
    |
    */

    'token' => [
        // Short-lived access token; the client refreshes silently before expiry.
        'access_ttl_minutes' => (int) env('HUMMINGBIRD_ACCESS_TTL_MINUTES', 30),
        // Longer-lived refresh token; rotated on each /auth/token/refresh.
        'refresh_ttl_days' => (int) env('HUMMINGBIRD_REFRESH_TTL_DAYS', 30),
        // Narrow, single-purpose token issued when must_change_password is true.
        'change_ttl_minutes' => (int) env('HUMMINGBIRD_CHANGE_TTL_MINUTES', 15),
    ],

    // APNs (Apple Push Notification service), token-based (.p8) auth. When key_id + team_id +
    // bundle_id and a key are present the container binds the real ApnsPushNotifier; otherwise it
    // falls back to the log-only stub. The .p8 may be inline (APNS_PRIVATE_KEY = PEM contents) or
    // a file path (APNS_PRIVATE_KEY_PATH).
    'apns' => [
        'key_id' => env('APNS_KEY_ID'),
        'team_id' => env('APNS_TEAM_ID'),
        'bundle_id' => env('APNS_BUNDLE_ID', 'net.acumenus.hummingbird'),
        'private_key' => env('APNS_PRIVATE_KEY'),
        'private_key_path' => env('APNS_PRIVATE_KEY_PATH'),
        // false = sandbox (dev/simulator builds); true = production (TestFlight / App Store).
        'production' => (bool) env('APNS_PRODUCTION', false),
    ],

    // PHI-free public Reverb channels every authenticated mobile client subscribes
    // to. Per-user unit channels (unit.{id}) are appended from the caller's unit
    // assignments. Reverb does not replay missed frames — clients MUST re-snapshot
    // tracked queries on every (re)connect.
    'realtime_channels' => [
        'hospital.beds',
    ],

    // The Reverb endpoint advertised to mobile/web clients. This is deliberately
    // SEPARATE from the broadcaster's trigger target (broadcasting.connections.reverb
    // .options.*): in production the server triggers events over loopback
    // (REVERB_HOST=127.0.0.1) so a publish never hairpins through the TLS edge, while
    // clients must be told the PUBLIC host that Apache fronts (mod_proxy_wstunnel on
    // /app). Falls back to the trigger vars so local dev — where both are the same
    // localhost:8080 — needs no extra config.
    'realtime_public' => [
        'host' => env('REVERB_PUBLIC_HOST', env('REVERB_HOST', 'localhost')),
        'port' => (int) env('REVERB_PUBLIC_PORT', (int) env('REVERB_PORT', 8080)),
        'scheme' => env('REVERB_PUBLIC_SCHEME', env('REVERB_SCHEME', 'http')),
    ],

];
