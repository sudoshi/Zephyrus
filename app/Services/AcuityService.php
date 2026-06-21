<?php

namespace App\Services;

use App\Models\Unit;

class AcuityService
{
    // Replaced with acuity-weighted logic in Task C1.
    public function adjustedCapacity(int $unitId): int
    {
        return Unit::find($unitId)?->staffed_bed_count ?? 0;
    }
}
