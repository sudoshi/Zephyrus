<?php

use Illuminate\Support\Facades\Broadcast;

// S2: any authenticated user may observe operational channels (read-only board data, no PHI in payloads).
Broadcast::channel('unit.{unitId}', fn ($user, $unitId) => $user !== null);
Broadcast::channel('hospital.beds', fn ($user) => $user !== null);
