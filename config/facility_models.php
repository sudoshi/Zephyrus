<?php

return [
    'zep_500' => [
        // facility_code is the IMMUTABLE CAD/RTDC join key — branding lives in facility_name only.
        'facility_code' => env('ZEPHYRUS_500_FACILITY_CODE', 'ZEPHYRUS-500'),
        'facility_name' => env('ZEPHYRUS_500_FACILITY_NAME', 'Summit Regional Medical Center'),
        'short_name' => env('ZEPHYRUS_500_FACILITY_SHORT_NAME', 'Summit Regional'),
        // Served from /facility-models — the /vendor/* URL namespace is blocked at the
        // edge (ModSecurity sensitive-path rule), so assets must never live under it.
        'model_url' => env('ZEPHYRUS_500_MODEL_URL', '/facility-models/zep-500/hospital_model.glb'),
        'tileset_url' => env('ZEPHYRUS_500_TILESET_URL', '/facility-models/zep-500/tileset.json'),
    ],
];
