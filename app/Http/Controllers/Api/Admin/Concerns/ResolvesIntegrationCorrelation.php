<?php

namespace App\Http\Controllers\Api\Admin\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

trait ResolvesIntegrationCorrelation
{
    private function correlationId(Request $request): string
    {
        $requested = $request->header('X-Request-ID');

        return is_string($requested) && Str::isUuid($requested)
            ? $requested
            : (string) Str::uuid();
    }
}
