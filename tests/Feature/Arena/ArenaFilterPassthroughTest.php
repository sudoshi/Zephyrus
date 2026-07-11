<?php

namespace Tests\Feature\Arena;

use App\Domain\Arena\ArenaSidecarClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArenaFilterPassthroughTest extends TestCase
{
    public function test_discover_forwards_filters_to_the_sidecar(): void
    {
        config()->set('services.arena.url', 'http://arena:8100');

        Http::fake([
            'arena:8100/discover' => Http::response([
                'object_types' => [], 'nodes' => [], 'edges' => [], 'stats' => [],
            ], 200),
        ]);

        $client = new ArenaSidecarClient;
        $filters = [['kind' => 'event_type', 'activities' => ['triage'], 'mode' => 'include']];
        $client->discover(['events' => [], 'objects' => []], null, null, $filters);

        Http::assertSent(function ($request) use ($filters) {
            return $request->url() === 'http://arena:8100/discover'
                && $request['filters'] === $filters;
        });
    }
}
