<?php

return [
    /*
    | Keep this stable across APP_KEY rotations when audit correlation must persist.
    | APP_KEY remains the deploy-compatible fallback and is domain-separated by use.
    */
    'hmac_key' => env('AUDIT_HMAC_KEY', env('APP_KEY')),

    /*
    | Client IPs are presented on audit surfaces only within this many days of
    | the event; older events keep the stored value but present no IP. Raw IPs
    | additionally require the manageIdentity capability to view at all.
    */
    'ip_retention_days' => (int) env('AUDIT_IP_RETENTION_DAYS', 90),
];
