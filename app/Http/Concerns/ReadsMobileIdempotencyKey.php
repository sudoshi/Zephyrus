<?php

namespace App\Http\Concerns;

use Illuminate\Http\Request;

trait ReadsMobileIdempotencyKey
{
    protected function mobileIdempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key')
            ?? $request->header('X-Idempotency-Key')
            ?? $request->input('idempotency_key');

        if (! is_string($key)) {
            return null;
        }

        $key = trim($key);

        return $key !== '' ? mb_substr($key, 0, 200) : null;
    }
}
