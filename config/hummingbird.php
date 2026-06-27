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

    // PHI-free public Reverb channels every authenticated mobile client subscribes
    // to. Per-user unit channels (unit.{id}) are appended from the caller's unit
    // assignments. Reverb does not replay missed frames — clients MUST re-snapshot
    // tracked queries on every (re)connect.
    'realtime_channels' => [
        'hospital.beds',
    ],

];
