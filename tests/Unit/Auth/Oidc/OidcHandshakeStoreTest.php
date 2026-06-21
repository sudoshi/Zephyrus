<?php

namespace Tests\Unit\Auth\Oidc;

use App\Services\Auth\Oidc\OidcHandshakeStore;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class OidcHandshakeStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_stores_and_consumes_state_exactly_once(): void
    {
        $store = new OidcHandshakeStore;
        $state = $store->putState(['nonce' => 'n1', 'code_verifier' => 'v1']);

        $this->assertSame(['nonce' => 'n1', 'code_verifier' => 'v1'], $store->consumeState($state));
        $this->assertNull($store->consumeState($state)); // single-use
    }

    public function test_returns_null_for_unknown_state(): void
    {
        $this->assertNull((new OidcHandshakeStore)->consumeState('nope'));
    }
}
