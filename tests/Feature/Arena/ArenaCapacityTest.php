<?php

namespace Tests\Feature\Arena;

use App\Domain\Arena\ArenaService;
use App\Domain\Arena\ArenaSidecarClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArenaCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_capacity_posts_quantities_payload_to_the_sidecar(): void
    {
        config()->set('services.arena.url', 'http://arena:8100');
        Http::fake([
            'arena:8100/capacity' => Http::response(['objects' => [], 'stats' => ['objects' => 0]], 200),
        ]);

        $client = new ArenaSidecarClient;
        $payload = ['initial' => [], 'operations' => []];
        $out = $client->capacity($payload, 'occupied_beds');

        $this->assertIsArray($out);
        Http::assertSent(fn ($r) => $r->url() === 'http://arena:8100/capacity'
            && $r['item_type'] === 'occupied_beds'
            && $r['quantities'] === $payload);
    }

    public function test_service_wraps_available_true_around_the_sidecar_result(): void
    {
        config()->set('services.arena.url', 'http://arena:8100');
        Http::fake([
            'arena:8100/capacity' => Http::response(['objects' => [], 'stats' => ['objects' => 0]], 200),
        ]);

        $out = app(ArenaService::class)->capacity();

        $this->assertTrue($out['available']);
        $this->assertArrayHasKey('objects', $out);
        $this->assertArrayHasKey('stats', $out);
    }

    public function test_service_degrades_to_available_false_when_the_sidecar_is_down(): void
    {
        config()->set('services.arena.url', 'http://arena:8100');
        Http::fake(['arena:8100/capacity' => Http::response('', 500)]);

        $out = app(ArenaService::class)->capacity();

        $this->assertFalse($out['available']);
        $this->assertSame('sidecar_unavailable', $out['reason']);
    }
}
