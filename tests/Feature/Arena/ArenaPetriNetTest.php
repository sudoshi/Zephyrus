<?php

namespace Tests\Feature\Arena;

use App\Domain\Arena\ArenaSidecarClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArenaPetriNetTest extends TestCase
{
    public function test_petrinet_calls_the_sidecar_discover_petrinet_endpoint(): void
    {
        config()->set('services.arena.url', 'http://arena:8100');
        Http::fake([
            'arena:8100/discover/petrinet' => Http::response(
                ['object_types' => ['Encounter'], 'nets' => [], 'stats' => []], 200
            ),
        ]);

        $client = new ArenaSidecarClient;
        $out = $client->petrinet(['events' => [], 'objects' => []]);

        $this->assertIsArray($out);
        $this->assertSame(['Encounter'], $out['object_types']);
        Http::assertSent(fn ($r) => $r->url() === 'http://arena:8100/discover/petrinet');
    }
}
