<?php

namespace Tests\Feature\Ancillary;

use App\Integrations\Healthcare\Ancillary\AncillaryEventVocabulary;
use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Integrations\Healthcare\DTO\WebhookEnvelope;
use App\Integrations\Healthcare\Services\CanonicalEventWriter;
use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Integrations\Healthcare\Synthetic\SyntheticHealthcareConnector;
use App\Models\Ancillary\AncillaryBreach;
use App\Models\Ancillary\AncillaryMilestone;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Ancillary\AncillarySlaDefinition;
use App\Models\Integration\Source;
use App\Services\Ancillary\SlaEvaluator;
use App\Services\Mobile\OperationalActivityLedger;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AncillarySlaEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AncillaryReferenceSeeder::class);
        config([
            'integrations.fresh_after_minutes' => 15,
            'integrations.ancillary.clock_timezone' => 'UTC',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_before_warning_warning_and_breach_states_are_deterministic(): void
    {
        $start = CarbonImmutable::parse('2026-07-11T12:00:00Z');

        [$running] = $this->clockOrder($start, null, $start->addMinutes(30));
        $runningResult = $this->statResult($running, $start->addMinutes(30));
        $this->assertSame('running', $runningResult['state']);
        $this->assertSame(30.0, $runningResult['elapsedMinutes']);

        [$warning] = $this->clockOrder($start, null, $start->addMinutes(100));
        $warningResult = $this->statResult($warning, $start->addMinutes(100));
        $this->assertSame('warning', $warningResult['state']);
        $this->assertSame($start->addMinutes(90)->toIso8601String(), $warningResult['warningAt']);
        $this->assertFalse($warningResult['breachOpened']);

        [$breached] = $this->clockOrder($start, null, $start->addMinutes(130));
        $breachResult = $this->statResult($breached, $start->addMinutes(130));
        $this->assertSame('breached', $breachResult['state']);
        $this->assertTrue($breachResult['breachOpened']);
        $this->assertDatabaseHas('prod.ancillary_breaches', [
            'ancillary_order_id' => $breached->ancillary_order_id,
            'status' => 'open',
            'elapsed_minutes_at_open' => '130.000',
        ]);
    }

    public function test_stop_before_breach_completes_without_materializing_breach(): void
    {
        $start = CarbonImmutable::parse('2026-07-11T12:00:00Z');
        [$order] = $this->clockOrder($start, $start->addMinutes(100), $start->addMinutes(101));

        $result = $this->statResult($order, $start->addMinutes(130));

        $this->assertSame('complete', $result['state']);
        $this->assertSame(100.0, $result['elapsedMinutes']);
        $this->assertFalse($result['wasBreached']);
        $this->assertSame(0, AncillaryBreach::query()->where('ancillary_order_id', $order->ancillary_order_id)->count());
    }

    public function test_historical_stop_after_breach_opens_and_clears_once_with_chronological_activity(): void
    {
        $start = CarbonImmutable::parse('2026-07-11T12:00:00Z');
        [$order, , $stop] = $this->clockOrder($start, $start->addMinutes(130), $start->addMinutes(131));

        $first = $this->statResult($order, $start->addMinutes(140));
        $second = $this->statResult($order, $start->addMinutes(141));

        $this->assertSame('complete', $first['state']);
        $this->assertTrue($first['breachOpened']);
        $this->assertTrue($first['breachCleared']);
        $this->assertFalse($second['breachOpened']);
        $this->assertFalse($second['breachCleared']);
        $breach = AncillaryBreach::query()->where('ancillary_order_id', $order->ancillary_order_id)->firstOrFail();
        $this->assertSame('cleared', $breach->status);
        $this->assertSame($stop->ancillary_milestone_id, $breach->stop_assertion_id);
        $this->assertSame('130.000', $breach->elapsed_minutes_at_clear);
        $this->assertSame(1, AncillaryBreach::query()->where('ancillary_order_id', $order->ancillary_order_id)->count());
        $events = DB::table('ops.operational_events')->where('domain', 'ancillary')->orderBy('occurred_at')->get();
        $this->assertSame(['ancillary.sla_breached', 'ancillary.sla_cleared'], $events->pluck('event_type')->all());
        $this->assertSame(2, $events->count());
    }

    public function test_open_breach_clears_on_exact_selected_stop_and_repeated_evaluation_is_idempotent(): void
    {
        $start = CarbonImmutable::parse('2026-07-11T12:00:00Z');
        [$order] = $this->clockOrder($start, null, $start->addMinutes(130));
        $opened = $this->statResult($order, $start->addMinutes(130));
        $repeatedOpen = $this->statResult($order, $start->addMinutes(131));
        $stop = $this->appendMilestone($order, 'RAD_FINAL', $start->addMinutes(140));
        $order->update(['source_cutoff_at' => $start->addMinutes(141)]);
        $cleared = $this->statResult($order, $start->addMinutes(141));
        $repeatedClear = $this->statResult($order, $start->addMinutes(142));

        $this->assertTrue($opened['breachOpened']);
        $this->assertFalse($repeatedOpen['breachOpened']);
        $this->assertTrue($cleared['breachCleared']);
        $this->assertFalse($repeatedClear['breachCleared']);
        $breach = AncillaryBreach::query()->where('ancillary_order_id', $order->ancillary_order_id)->firstOrFail();
        $this->assertSame($stop->ancillary_milestone_id, $breach->stop_assertion_id);
        $this->assertSame('140.000', $breach->elapsed_minutes_at_clear);
        $this->assertNotNull($breach->opened_event_uuid);
        $this->assertNotNull($breach->cleared_event_uuid);
        $this->assertSame(1, AncillaryBreach::query()->where('ancillary_order_id', $order->ancillary_order_id)->count());
        $this->assertSame(2, DB::table('ops.operational_events')->where('domain', 'ancillary')->count());
        $this->assertSame(6, DB::table('ops.operational_event_entities')->count());
        $this->assertSame(0, DB::table('ops.operational_event_entities')
            ->whereNotNull('patient_ref')->orWhereNotNull('encounter_ref')->count());
    }

    public function test_corrected_selected_stop_clears_against_new_assertion_without_mutating_history(): void
    {
        $start = CarbonImmutable::parse('2026-07-11T12:00:00Z');
        [$order] = $this->clockOrder($start, null, $start->addMinutes(130));
        $this->statResult($order, $start->addMinutes(130));
        $original = $this->appendMilestone($order, 'RAD_FINAL', $start->addMinutes(150), 20);
        $corrected = $this->appendMilestone($order, 'RAD_FINAL', $start->addMinutes(125), 10, [
            'correction' => true,
            'supersedes_assertion_key' => $original->assertion_key,
        ]);
        $order->update(['source_cutoff_at' => $start->addMinutes(151)]);

        $result = $this->statResult($order, $start->addMinutes(151));
        $breach = AncillaryBreach::query()->where('ancillary_order_id', $order->ancillary_order_id)->firstOrFail();

        $this->assertTrue($result['breachCleared']);
        $this->assertSame($corrected->ancillary_milestone_id, $breach->stop_assertion_id);
        $this->assertSame('125.000', $breach->elapsed_minutes_at_clear);
        $this->assertSame(2, $order->milestones()->where('milestone_code', 'RAD_FINAL')->count());
        $this->assertDatabaseHas('prod.ancillary_milestones', ['ancillary_milestone_id' => $original->ancillary_milestone_id]);
    }

    public function test_stale_source_is_unknown_and_does_not_open_new_breach(): void
    {
        $start = CarbonImmutable::parse('2026-07-11T12:00:00Z');
        [$order] = $this->clockOrder($start, null, $start);

        $result = $this->statResult($order, $start->addMinutes(130));

        $this->assertSame('unknown', $result['state']);
        $this->assertSame('stale', $result['freshness']['status']);
        $this->assertSame('stale_source', $result['reason']);
        $this->assertSame(0, AncillaryBreach::query()->where('ancillary_order_id', $order->ancillary_order_id)->count());
    }

    public function test_missing_start_is_unknown_not_compliant(): void
    {
        $at = CarbonImmutable::parse('2026-07-11T14:00:00Z');
        $source = $this->source('ris');
        $order = AncillaryOrder::factory()->radiology()->create([
            'source_id' => $source->source_id,
            'priority' => 'stat',
            'patient_class' => 'inpatient',
            'ordered_at' => $at->subHour(),
            'source_cutoff_at' => $at,
        ]);

        $result = $this->statResult($order, $at);

        $this->assertSame('unknown', $result['state']);
        $this->assertSame('missing_start_assertion', $result['reason']);
        $this->assertNull($result['elapsedMinutes']);
    }

    public function test_warehouse_clock_is_cutoff_qualified_and_does_not_age_to_now(): void
    {
        $start = CarbonImmutable::parse('2026-07-11T12:00:00Z');
        [$order] = $this->clockOrder(
            $start,
            null,
            $start->addMinutes(100),
            systemClass: 'clinical_warehouse',
            metadata: ['feed_mode' => 'warehouse'],
        );

        $result = $this->statResult($order, $start->addMinutes(200));

        $this->assertSame('warning', $result['state']);
        $this->assertSame(100.0, $result['elapsedMinutes']);
        $this->assertSame('batch', $result['freshness']['status']);
        $this->assertSame($start->addMinutes(100)->toIso8601String(), $result['freshness']['sourceCutoffAt']);
        $this->assertSame(0, AncillaryBreach::query()->where('ancillary_order_id', $order->ancillary_order_id)->count());
    }

    public function test_dst_boundary_uses_elapsed_instants_in_explicit_clock_timezone(): void
    {
        config(['integrations.ancillary.clock_timezone' => 'America/New_York']);
        $start = CarbonImmutable::parse('2026-03-08T06:30:00Z');
        $at = CarbonImmutable::parse('2026-03-08T07:30:00Z');
        [$order] = $this->clockOrder($start, null, $at);

        $result = $this->statResult($order, $at);

        $this->assertSame(60.0, $result['elapsedMinutes']);
        $this->assertSame('running', $result['state']);
    }

    public function test_most_specific_effective_definition_wins_for_metric_population(): void
    {
        $start = CarbonImmutable::parse('2026-07-11T12:00:00Z');
        [$order] = $this->clockOrder($start, null, $start->addMinutes(60), metadata: ['modality' => 'CT']);
        $global = $this->definition('test.rad.modality_clock', 1, [], 90, 120);
        $specific = $this->definition('test.rad.modality_clock', 2, ['modality' => 'CT'], 40, 50);

        $evaluations = collect(app(SlaEvaluator::class)->evaluateOrder($order, $start->addMinutes(60)))
            ->map(fn ($result): array => $result->toArray());
        $selected = $evaluations->firstWhere('metricKey', 'test.rad.modality_clock');

        $this->assertSame($specific->definition_uuid, $selected['definitionUuid']);
        $this->assertNotSame($global->definition_uuid, $selected['definitionUuid']);
        $this->assertSame('breached', $selected['state']);
    }

    public function test_projection_triggers_evaluation_and_stop_event_clears_breach(): void
    {
        $now = CarbonImmutable::parse('2026-07-11T16:00:00Z');
        CarbonImmutable::setTestNow($now);
        $connector = app(SyntheticHealthcareConnector::class);
        $connector->handleWebhook(new WebhookEnvelope(['messages' => [
            $this->syntheticMessage('RAD_ORDERED', 'event-clock-order', 'event-clock-start', $now->subMinutes(130)),
        ]]));

        $order = AncillaryOrder::query()->where('source_order_key', 'event-clock-order')->firstOrFail();
        $this->assertDatabaseHas('prod.ancillary_breaches', [
            'ancillary_order_id' => $order->ancillary_order_id,
            'status' => 'open',
        ]);

        $connector->handleWebhook(new WebhookEnvelope(['messages' => [
            $this->syntheticMessage('RAD_FINAL', 'event-clock-order', 'event-clock-stop', $now),
        ]]));

        $this->assertDatabaseHas('prod.ancillary_breaches', [
            'ancillary_order_id' => $order->ancillary_order_id,
            'status' => 'cleared',
        ]);
        $this->assertSame(2, DB::table('ops.operational_events')->where('domain', 'ancillary')->count());
    }

    public function test_safe_evaluation_and_batch_command_report_one_failure_without_skipping_next_order(): void
    {
        $start = CarbonImmutable::parse('2026-07-11T12:00:00Z');
        [$failing] = $this->clockOrder($start, null, $start->addMinutes(130));
        [$healthy] = $this->clockOrder($start, null, $start->addMinutes(30));
        $ledger = Mockery::mock(OperationalActivityLedger::class);
        $ledger->shouldReceive('record')->once()->andThrow(new RuntimeException('test ledger failure'));
        $isolated = new SlaEvaluator($ledger);

        $failed = $isolated->evaluateOrderSafely($failing, $start->addMinutes(130));
        $continued = $isolated->evaluateOrderSafely($healthy, $start->addMinutes(30));

        $this->assertFalse($failed['ok']);
        $this->assertSame('ancillary_sla_evaluation_failed', $failed['errorCode']);
        $this->assertTrue($continued['ok']);
        $this->assertSame('running', collect($continued['evaluations'])->firstWhere('metricKey', 'rad.stat_order_final')['state']);
        $this->assertSame(0, AncillaryBreach::query()->where('ancillary_order_id', $failing->ancillary_order_id)->count());

        $commandMock = Mockery::mock(SlaEvaluator::class);
        $commandMock->shouldReceive('evaluateOrderSafely')->twice()->andReturnUsing(
            fn (AncillaryOrder $order): array => $order->is($failing) ? [
                'ok' => false, 'orderUuid' => $failing->order_uuid,
                'errorCode' => 'ancillary_sla_evaluation_failed', 'exceptionClass' => RuntimeException::class,
                'evaluations' => [],
            ] : [
                'ok' => true, 'orderUuid' => $healthy->order_uuid,
                'evaluations' => [['state' => 'running', 'breachOpened' => false, 'breachCleared' => false]],
            ],
        );
        $this->app->instance(SlaEvaluator::class, $commandMock);
        $this->artisan('ancillary:evaluate-slas', [
            '--order-id' => [$failing->ancillary_order_id, $healthy->ancillary_order_id],
            '--at' => $start->addMinutes(130)->toIso8601String(),
            '--json' => true,
        ])
            ->expectsOutputToContain('"failures":1')
            ->assertExitCode(1)
            ->execute();
    }

    public function test_scheduler_registers_per_minute_catch_up_command(): void
    {
        $this->artisan('schedule:list')
            ->assertSuccessful()
            ->expectsOutputToContain('ancillary:evaluate-slas --json');
    }

    /** @return array{AncillaryOrder, AncillaryMilestone, AncillaryMilestone|null} */
    private function clockOrder(
        CarbonImmutable $start,
        ?CarbonImmutable $stop,
        CarbonImmutable $sourceCutoff,
        string $systemClass = 'ris',
        array $metadata = [],
    ): array {
        $source = $this->source($systemClass);
        $order = AncillaryOrder::factory()->radiology()->create([
            'source_id' => $source->source_id,
            'priority' => 'stat',
            'patient_class' => 'inpatient',
            'ordered_at' => $start,
            'source_cutoff_at' => $sourceCutoff,
            'metadata' => $metadata,
        ]);
        $startMilestone = $this->appendMilestone($order, 'RAD_ORDERED', $start);
        $stopMilestone = $stop !== null ? $this->appendMilestone($order, 'RAD_FINAL', $stop) : null;

        return [$order->refresh(), $startMilestone, $stopMilestone];
    }

    private function appendMilestone(
        AncillaryOrder $order,
        string $code,
        CarbonImmutable $occurredAt,
        int $sourceRank = 100,
        array $metadata = [],
    ): AncillaryMilestone {
        $event = new CanonicalOperationalEvent(
            eventId: (string) Str::uuid(),
            eventType: AncillaryEventVocabulary::eventTypeFor($code),
            entityType: 'ancillary_order',
            entityRef: $order->source_order_key,
            payload: [],
            occurredAt: $occurredAt,
            idempotencyKey: 'sla-fixture-'.Str::uuid(),
        );
        $record = app(CanonicalEventWriter::class)->write($event, $order->source);

        return AncillaryMilestone::query()->create([
            'milestone_uuid' => (string) Str::uuid(),
            'ancillary_order_id' => $order->ancillary_order_id,
            'milestone_code' => $code,
            'occurred_at' => $occurredAt,
            'received_at' => $order->source_cutoff_at,
            'source_id' => $order->source_id,
            'canonical_event_id' => $record->canonical_event_id,
            'assertion_key' => 'sla-assertion-'.Str::uuid(),
            'source_rank' => $sourceRank,
            'metadata' => $metadata,
        ]);
    }

    private function source(string $systemClass): Source
    {
        return app(SourceRegistryService::class)->ensureSource([
            'source_key' => 'sla-source-'.Str::uuid(),
            'source_name' => 'SLA fixture source',
            'system_class' => $systemClass,
            'metadata' => ['ancillary_source_class' => $systemClass],
        ]);
    }

    /** @return array<string, mixed> */
    private function statResult(AncillaryOrder $order, CarbonImmutable $at): array
    {
        $results = app(SlaEvaluator::class)->evaluateOrder($order, $at);

        return collect($results)
            ->map(fn ($result): array => $result->toArray())
            ->firstWhere('metricKey', 'rad.stat_order_final');
    }

    private function definition(
        string $metricKey,
        int $version,
        array $scope,
        int $warning,
        int $breach,
    ): AncillarySlaDefinition {
        return AncillarySlaDefinition::query()->create([
            'definition_uuid' => (string) Str::uuid(),
            'department' => 'rad',
            'metric_key' => $metricKey,
            'label' => 'Test scoped clock',
            'start_milestone_code' => 'RAD_ORDERED',
            'stop_milestone_code' => 'RAD_FINAL',
            'priority' => 'stat',
            'scope' => $scope,
            'statistic' => 'item_clock',
            'warning_minutes' => $warning,
            'breach_minutes' => $breach,
            'direction' => 'lower_is_better',
            'unit' => 'minutes',
            'effective_from' => CarbonImmutable::parse('2026-01-01T00:00:00Z'),
            'version' => $version,
            'active' => true,
            'definition_text' => 'Test scoped definition.',
            'source_reference_id' => 'test',
        ]);
    }

    /** @return array<string, mixed> */
    private function syntheticMessage(
        string $code,
        string $sourceOrderKey,
        string $externalId,
        CarbonImmutable $occurredAt,
    ): array {
        return [
            'message_type' => 'synthetic.'.AncillaryEventVocabulary::eventTypeFor($code),
            'event_type' => AncillaryEventVocabulary::eventTypeFor($code),
            'external_id' => $externalId,
            'department' => AncillaryEventVocabulary::departmentFor($code),
            'milestone_code' => $code,
            'source_order_key' => $sourceOrderKey,
            'patient_class' => 'inpatient',
            'priority' => 'stat',
            'ordered_at' => CarbonImmutable::now()->subMinutes(130)->toIso8601String(),
            'occurred_at' => $occurredAt->toIso8601String(),
        ];
    }
}
