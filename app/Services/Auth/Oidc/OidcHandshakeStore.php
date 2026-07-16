<?php

namespace App\Services\Auth\Oidc;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class OidcHandshakeStore
{
    private const STATE_TTL = 300;

    private const STATE_PREFIX = 'oidc:state:';

    /** @param array{nonce: string, code_verifier: string, purpose?: string, user_id?: int|null} $meta */
    public function putState(array $meta): string
    {
        $state = Str::random(48);
        Cache::put(self::STATE_PREFIX.$state, $meta, self::STATE_TTL);

        return $state;
    }

    /** @return array{nonce: string, code_verifier: string, purpose?: string, user_id?: int|null}|null */
    public function consumeState(string $state): ?array
    {
        return Cache::pull(self::STATE_PREFIX.$state);
    }
}
