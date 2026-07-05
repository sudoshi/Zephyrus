<?php

namespace Tests\Feature\Cockpit;

use App\Events\Cockpit\CockpitSnapshotUpdated;
use App\Services\Cockpit\SnapshotBuilder;
use Database\Seeders\CockpitKpiDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Zephyrus 2.0 P8 WS-7 — the "real broadcast" release gate (automated half).
 * A snapshot refresh must dispatch the PHI-free reload ping on the public
 * hospital.cockpit channel so the wall/desk clients refetch. The manual half
 * (prod BROADCAST_CONNECTION=reverb + a Reverb daemon relaying it) is the
 * deploy checklist item — this proves the dispatch + payload contract.
 * PHPUnit class syntax (Pest excluded on this environment).
 */
class CockpitBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(SnapshotBuilder::CACHE_KEY);
        $this->seed(CockpitKpiDefinitionSeeder::class);
    }

    public function test_refresh_broadcasts_a_phi_free_reload_ping_on_the_public_channel(): void
    {
        Event::fake([CockpitSnapshotUpdated::class]);

        app(SnapshotBuilder::class)->refresh();

        Event::assertDispatched(CockpitSnapshotUpdated::class, function (CockpitSnapshotUpdated $event): bool {
            // Public channel + stable alias the frontend listens on.
            $this->assertSame('hospital.cockpit', $event->broadcastOn()->name);
            $this->assertSame('cockpit.updated', $event->broadcastAs());

            // PHI-free reload ping — ONLY the facility key + timestamp ride the wire.
            $payload = $event->broadcastWith();
            $this->assertSame(['facility_key', 'generated_at'], array_keys($payload));
            $this->assertNotEmpty($payload['facility_key']);
            $this->assertNotEmpty($payload['generated_at']);

            return true;
        });
    }
}
