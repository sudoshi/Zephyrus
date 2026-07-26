<?php

declare(strict_types=1);

namespace Tests\Feature\Ancillary;

use App\Services\Lab\LabAggregateSnapshotFactory;
use App\Services\Lab\LabDecisionPendingService;
use App\Services\Lab\LabFlowBoardService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Scenario\UsesCommittedAncillaryScenario;
use Tests\TestCase;

final class LabAggregateSnapshotFactoryTest extends TestCase
{
    use UsesCommittedAncillaryScenario;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-11T14:00:00Z'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_scoped_binding_memoizes_one_governed_calculation_per_scope(): void
    {
        $factory = app(LabAggregateSnapshotFactory::class);
        $this->assertSame($factory, app(LabAggregateSnapshotFactory::class));

        $operations = $factory->operationsHealth();
        $decisions = $factory->decisionHealth();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame($operations, $factory->operationsHealth());
        $this->assertSame($decisions, $factory->decisionHealth());
        $this->assertSame($factory->snapshot()->decisionReadiness, $factory->decisionReadiness());
        $this->assertSame([], DB::getQueryLog(), 'Memoized aggregate reads must not touch the database.');
        DB::disableQueryLog();

        // Parity with the owning services: the factory forwards, never derives.
        $this->assertSame(app(LabFlowBoardService::class)->cockpitHealth(), $operations);
        $this->assertSame(app(LabDecisionPendingService::class)->cockpitHealth(), $decisions);
        $this->assertSame(app(LabDecisionPendingService::class)->readinessSnapshot(), $factory->decisionReadiness());
    }

    public function test_scope_boundary_flush_forces_a_fresh_computation(): void
    {
        $factory = app(LabAggregateSnapshotFactory::class);
        $factory->operationsHealth();

        $this->nextRequestScope();
        $next = app(LabAggregateSnapshotFactory::class);
        $this->assertNotSame($factory, $next, 'A request/job boundary must yield a fresh scoped instance.');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $next->operationsHealth();
        $this->assertNotSame([], DB::getQueryLog(), 'The fresh scope must recompute from the database.');
        DB::disableQueryLog();
    }

    public function test_snapshot_pair_is_captured_together_for_the_reconciliation_rule(): void
    {
        $factory = app(LabAggregateSnapshotFactory::class);
        $snapshot = $factory->snapshot();

        // Mutating the freshness registry after capture must not bleed into
        // either member: the verified-empty inheritance in
        // LabCockpitHealthService depends on the pair reflecting one read.
        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'stale']);
        $this->assertSame($snapshot->operationsHealth, $factory->operationsHealth());
        $this->assertSame($snapshot->decisionReadiness, $factory->decisionReadiness());
        $this->assertSame('fresh', $factory->operationsHealth()['sourceState']);

        $factory->flush();
        $this->assertSame('stale', $factory->operationsHealth()['sourceState']);
    }
}
