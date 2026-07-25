<?php

declare(strict_types=1);

namespace Tests\Support\Scenario;

use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\CaseManagementSeeder;
use Database\Seeders\CommandCenterDemoSeeder;
use Database\Seeders\RtdcSeeder;
use Database\Seeders\StaffingReferenceSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/**
 * CI plan S2 — class-scoped committed demo scenario.
 *
 * The ancillary demo scenario (5 seeders + AncillaryDemoScenarioService::
 * refresh, ~30 s) used to be rebuilt inside setUp() for EVERY test method,
 * where RefreshDatabase's per-test transaction rolled it back each time —
 * 87% of backend suite compute. This trait builds it ONCE PER CLASS,
 * COMMITTED (per-test transactions cannot survive between tests because
 * RefreshDatabase disconnects the connection after each rollback), before
 * the per-test transaction begins. Every test still runs inside its own
 * rollback transaction, so each starts from a byte-identical baseline —
 * mutations never leak between tests.
 *
 * Safety rests on audited properties of the build (2026-07-25 audit):
 * the service and seeders self-clear via owner-scoped DELETEs and
 * idempotent upserts, never COMMIT, never TRUNCATE, use one connection,
 * and derive all content deterministically from the anchor. A successor
 * scenario class simply rebuilds over the previous class's residue; a
 * NON-scenario class following a scenario class gets a fresh migration
 * via the guard in tests/TestCase.php.
 */
trait UsesCommittedAncillaryScenario
{
    use RefreshDatabase;

    /**
     * The anchor every current consumer class builds at. Override per
     * class if a future consumer needs a different scenario clock.
     */
    protected static function committedScenarioAnchor(): string
    {
        return '2026-07-11T14:00:00Z';
    }

    /**
     * Generator-contract tests exercise refresh() inside their test
     * bodies; they override this to hoist only the seeders.
     */
    protected static function committedScenarioIncludesRefresh(): bool
    {
        return true;
    }

    protected function refreshTestDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->migrateDatabases();

            $this->app[Kernel::class]->setArtisan(null);

            RefreshDatabaseState::$migrated = true;
            CommittedScenarioState::reset();
        }

        $this->ensureCommittedScenario();

        $this->beginDatabaseTransaction();
    }

    /**
     * Build the committed baseline exactly once per class. Runs OUTSIDE
     * any test transaction (autocommit), so the data survives the
     * per-test rollback + disconnect cycle.
     */
    private function ensureCommittedScenario(): void
    {
        if (CommittedScenarioState::$activeClass === static::class) {
            return;
        }

        $anchor = CarbonImmutable::parse(static::committedScenarioAnchor());

        // The build runs during parent::setUp(), before TestCase::setUp()
        // reaches its per-test wiring — apply the payload-store wiring the
        // scenario's CanonicalEventWriter path depends on.
        $this->wireClinicalPayloadTestStore();

        CarbonImmutable::setTestNow($anchor);

        try {
            $this->seed([
                RtdcSeeder::class,
                CaseManagementSeeder::class,
                StaffingReferenceSeeder::class,
                CommandCenterDemoSeeder::class,
                AncillaryReferenceSeeder::class,
            ]);

            if (static::committedScenarioIncludesRefresh()) {
                app(AncillaryDemoScenarioService::class)->refresh(new DemoClock($anchor));
            }
        } finally {
            CarbonImmutable::setTestNow();
        }

        // Committed baselines live across many DELETE+INSERT build cycles
        // in one process; without this, autovacuum timing leaves planner
        // statistics AND visibility-map coverage (which prices the suite's
        // Index Only Scan plan-shape assertions) nondeterministic. VACUUM
        // is legal here — the build runs in autocommit, outside any test
        // transaction. Same data -> same stats + VM -> same plans.
        DB::statement('VACUUM ANALYZE');

        CommittedScenarioState::$activeClass = static::class;
    }
}
