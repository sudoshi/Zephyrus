<?php

namespace App\Http\Concerns;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait RequiresIdempotencyKey
{
    protected function requireIdempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '' || mb_strlen($key) > 200 || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A 1-200 character Idempotency-Key header using letters, numbers, dot, underscore, colon, or hyphen is required.',
            ]);
        }

        return $key;
    }
}
