<?php

return [
    /*
    | Keep this stable across APP_KEY rotations when audit correlation must persist.
    | APP_KEY remains the deploy-compatible fallback and is domain-separated by use.
    */
    'hmac_key' => env('AUDIT_HMAC_KEY', env('APP_KEY')),
];
