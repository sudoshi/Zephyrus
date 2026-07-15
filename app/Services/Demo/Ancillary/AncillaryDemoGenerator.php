<?php

namespace App\Services\Demo\Ancillary;

use App\Services\Demo\DemoClock;

interface AncillaryDemoGenerator
{
    /** @return array<string, mixed> */
    public function preview(DemoClock $clock): array;

    /** @return array<string, mixed> */
    public function refresh(DemoClock $clock, string $owner): array;
}
