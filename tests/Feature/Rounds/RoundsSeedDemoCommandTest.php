<?php

namespace Tests\Feature\Rounds;

use App\Models\Rounds\RoundRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\SeedsRoundsStory;
use Tests\TestCase;

/**
 * The demo seeder feeds the 6-hourly demo refresh: it must create one open run
 * per unit, idempotently skip an existing one, and — with --refresh — retire
 * the stale run and rebuild a fresh cohort against the current census.
 */
class RoundsSeedDemoCommandTest extends TestCase
{
    use RefreshDatabase, SeedsRoundsStory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoundsStory();
    }

    private function runSeeder(array $opts = []): int
    {
        return Artisan::call('rounds:seed-demo', array_merge([
            '--units' => (string) $this->roundsUnit->unit_id,
            '--template' => $this->roundsTemplate->name,
        ], $opts));
    }

    public function test_creates_one_open_run_then_idempotently_skips(): void
    {
        $this->runSeeder();
        $first = RoundRun::query()->open()->where('scope_key', (string) $this->roundsUnit->unit_id)->sole();

        // Without --refresh a second pass reuses the same open run.
        $this->runSeeder();
        $this->assertSame(1, RoundRun::query()->open()->where('scope_key', (string) $this->roundsUnit->unit_id)->count());
        $this->assertSame($first->run_uuid, RoundRun::query()->open()->where('scope_key', (string) $this->roundsUnit->unit_id)->sole()->run_uuid);
    }

    public function test_refresh_cancels_the_stale_run_and_rebuilds(): void
    {
        $this->runSeeder();
        $original = RoundRun::query()->open()->where('scope_key', (string) $this->roundsUnit->unit_id)->sole();

        $this->runSeeder(['--refresh' => true]);

        // Exactly one open run remains, and it is a fresh one; the original is cancelled.
        $current = RoundRun::query()->open()->where('scope_key', (string) $this->roundsUnit->unit_id)->sole();
        $this->assertNotSame($original->run_uuid, $current->run_uuid);
        $this->assertSame('cancelled', $original->fresh()->status);
        $this->assertGreaterThan(0, $current->patients()->count());
    }
}
