<?php

namespace Tests\Feature\Arena;

use App\Domain\Arena\ArenaSidecarClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Zephyrus 2.0 Part X (X1). Pins the Laravel↔sidecar contract: the client posts
 * the OCEL doc inline (never a PHI-bearing path), forwards discovery params, and
 * degrades to null on failure so the Arena serves a last-good cached map.
 */
class ArenaSidecarClientTest extends TestCase
{
    public function test_discover_posts_ocel_inline_with_params(): void
    {
        Http::fake([
            'arena:8100/discover' => Http::response([
                'object_types' => ['Encounter'],
                'nodes' => [], 'edges' => [], 'stats' => ['nodes' => 0, 'edges' => 0],
            ], 200),
        ]);

        $result = (new ArenaSidecarClient)->discover(['events' => [], 'objects' => []], ['Encounter'], 5);

        $this->assertSame(['Encounter'], $result['object_types']);
        Http::assertSent(function ($req) {
            return $req->url() === 'http://arena:8100/discover'
                && $req->method() === 'POST'
                && $req['object_types'] === ['Encounter']
                && $req['activity_min_freq'] === 5
                && array_key_exists('ocel', $req->data());
        });
    }

    public function test_discover_omits_optional_params_when_absent(): void
    {
        Http::fake(['arena:8100/discover' => Http::response(['object_types' => [], 'nodes' => [], 'edges' => [], 'stats' => []], 200)]);

        (new ArenaSidecarClient)->discover(['events' => []]);

        Http::assertSent(fn ($req) => ! array_key_exists('object_types', $req->data()) && ! array_key_exists('activity_min_freq', $req->data()));
    }

    public function test_health_returns_payload(): void
    {
        Http::fake(['arena:8100/health' => Http::response(['status' => 'ok', 'engine' => ['pm4py_available' => true]], 200)]);

        $this->assertSame('ok', (new ArenaSidecarClient)->health()['status']);
    }

    public function test_failures_degrade_to_null(): void
    {
        Http::fake(['arena:8100/discover' => Http::response('boom', 500)]);
        $this->assertNull((new ArenaSidecarClient)->discover(['events' => []]));

        Http::fake(['arena:8100/health' => fn () => throw new \RuntimeException('connect refused')]);
        $this->assertNull((new ArenaSidecarClient)->health());
    }
}
