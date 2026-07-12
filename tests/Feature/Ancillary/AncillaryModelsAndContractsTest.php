<?php

namespace Tests\Feature\Ancillary;

use App\Data\Ancillary\AncillaryPageFilters;
use App\Data\Ancillary\FreshnessEnvelope;
use App\Data\Ancillary\MilestoneAssertion;
use App\Data\Ancillary\ReadinessAxis;
use App\Data\Ancillary\SelectedClock;
use App\Models\Ancillary\AncillaryBreach;
use App\Models\Ancillary\AncillaryMilestone;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Ancillary\AncillarySlaDefinition;
use App\Models\Encounter;
use App\Models\Integration\CanonicalEventRecord;
use App\Models\Integration\ProvenanceRecord;
use App\Models\Integration\Source;
use App\Models\Unit;
use App\Models\User;
use Database\Factories\Ancillary\AncillaryScenarioFactory;
use Database\Seeders\AncillaryReferenceSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class AncillaryModelsAndContractsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AncillaryReferenceSeeder::class);
    }

    public function test_models_expose_casts_and_every_shared_spine_relationship(): void
    {
        $order = (new AncillaryScenarioFactory)->full();
        $unitId = DB::table('prod.units')->insertGetId([
            'name' => 'Ancillary test unit',
            'type' => 'med_surg',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'unit_id');
        $encounterId = DB::table('prod.encounters')->insertGetId([
            'patient_ref' => 'ancillary-patient',
            'unit_id' => $unitId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'encounter_id');
        $order->update(['unit_id' => $unitId, 'encounter_id' => $encounterId]);
        $order->load(['source', 'encounter', 'unit', 'currentMilestoneType', 'milestones.milestoneType', 'milestones.source', 'milestones.canonicalEvent']);

        $this->assertInstanceOf(Source::class, $order->source);
        $this->assertInstanceOf(Encounter::class, $order->encounter);
        $this->assertInstanceOf(Unit::class, $order->unit);
        $this->assertSame('RAD_FINAL', $order->currentMilestoneType->code);
        $this->assertTrue(AncillaryOrder::query()->forUnit($unitId)->whereKey($order->getKey())->exists());
        $this->assertTrue(AncillaryOrder::query()->forEncounter($encounterId)->whereKey($order->getKey())->exists());
        $this->assertCount(3, $order->milestones);
        $this->assertInstanceOf(DateTimeImmutable::class, $order->ordered_at);
        $this->assertInstanceOf(DateTimeImmutable::class, $order->source_cutoff_at);
        $this->assertIsArray($order->metadata);

        $milestone = $order->milestones->first();
        $this->assertInstanceOf(AncillaryOrder::class, $milestone->order);
        $this->assertInstanceOf(Source::class, $milestone->source);
        $this->assertInstanceOf(CanonicalEventRecord::class, $milestone->canonicalEvent);
        $this->assertSame($milestone->milestone_code, $milestone->milestoneType->code);
        $this->assertInstanceOf(DateTimeImmutable::class, $milestone->occurred_at);
        $this->assertIsArray($milestone->metadata);

        $provenanceId = DB::table('integration.provenance_records')->insertGetId([
            'source_id' => $order->source_id,
            'canonical_event_id' => $milestone->canonical_event_id,
            'target_schema' => 'prod',
            'target_table' => 'ancillary_milestones',
            'target_pk' => (string) $milestone->ancillary_milestone_id,
            'lineage' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'provenance_record_id');
        $withProvenance = AncillaryMilestone::factory()
            ->for($order, 'order')
            ->state(['provenance_record_id' => $provenanceId])
            ->create();
        $this->assertInstanceOf(ProvenanceRecord::class, $withProvenance->provenance);

        $definition = $this->createDefinition();
        $approver = User::factory()->create();
        $definition->update(['approved_by_user_id' => $approver->id, 'approved_at' => now()]);
        $barrierId = DB::table('prod.barriers')->insertGetId([
            'encounter_id' => $encounterId,
            'unit_id' => $unitId,
            'category' => 'medical',
            'reason_code' => 'RAD_DELAY',
            'status' => 'open',
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'barrier_id');
        $breach = AncillaryBreach::query()->create([
            'breach_uuid' => (string) Str::uuid(),
            'ancillary_order_id' => $order->ancillary_order_id,
            'ancillary_sla_definition_id' => $definition->ancillary_sla_definition_id,
            'status' => 'open',
            'breached_at' => now(),
            'start_assertion_id' => $milestone->ancillary_milestone_id,
            'elapsed_minutes_at_open' => 121,
            'barrier_id' => $barrierId,
            'last_evaluated_at' => now(),
            'metadata' => ['origin' => 'test'],
        ]);

        $breach->load(['order', 'definition', 'startAssertion', 'stopAssertion', 'barrier']);
        $this->assertTrue($breach->order->is($order));
        $this->assertTrue($breach->definition->is($definition));
        $this->assertTrue($breach->startAssertion->is($milestone));
        $this->assertNull($breach->stopAssertion);
        $this->assertNotNull($breach->barrier);
        $this->assertInstanceOf(DateTimeImmutable::class, $breach->breached_at);
        $this->assertIsArray($breach->metadata);

        $this->assertSame('RAD_ORDERED', $definition->startMilestoneType->code);
        $this->assertSame('RAD_FINAL', $definition->stopMilestoneType->code);
        $this->assertTrue($definition->approvedBy->is($approver));
        $this->assertTrue($definition->breaches->contains($breach));
    }

    public function test_order_scopes_cover_open_department_priority_freshness_demo_discharge_and_breach_lenses(): void
    {
        $fresh = AncillaryOrder::factory()->radiology()->ownedDemo('scope-demo')->create([
            'priority' => 'stat',
            'source_cutoff_at' => now(),
        ]);
        $discharge = AncillaryOrder::factory()->pharmacy()->dischargeBlocking()->create([
            'ordered_at' => now()->subHour(),
            'source_cutoff_at' => now()->subMinutes(30),
        ]);
        $terminal = (new AncillaryScenarioFactory)->terminal();

        $startMilestone = AncillaryMilestone::factory()->for($fresh, 'order')->create();
        $definition = $this->createDefinition();
        AncillaryBreach::query()->create([
            'breach_uuid' => (string) Str::uuid(),
            'ancillary_order_id' => $fresh->ancillary_order_id,
            'ancillary_sla_definition_id' => $definition->ancillary_sla_definition_id,
            'status' => 'open',
            'breached_at' => now(),
            'start_assertion_id' => $startMilestone->ancillary_milestone_id,
            'elapsed_minutes_at_open' => 121,
            'last_evaluated_at' => now(),
            'metadata' => [],
        ]);

        $this->assertTrue(AncillaryOrder::query()->open()->pluck('ancillary_order_id')->contains($fresh->ancillary_order_id));
        $this->assertFalse(AncillaryOrder::query()->open()->pluck('ancillary_order_id')->contains($terminal->ancillary_order_id));
        $this->assertSame([$fresh->ancillary_order_id], AncillaryOrder::query()->open()->department('rad')->priority('stat')->pluck('ancillary_order_id')->all());
        $this->assertSame([$fresh->ancillary_order_id], AncillaryOrder::query()->ownedByDemo('scope-demo')->pluck('ancillary_order_id')->all());
        $this->assertSame([$fresh->ancillary_order_id], AncillaryOrder::query()->freshSince(now()->subMinute())->ownedByDemo('scope-demo')->pluck('ancillary_order_id')->all());
        $this->assertSame([$discharge->ancillary_order_id], AncillaryOrder::query()->dischargeBlocking()->pluck('ancillary_order_id')->all());
        $this->assertSame([$fresh->ancillary_order_id], AncillaryOrder::query()->breached()->pluck('ancillary_order_id')->all());
    }

    public function test_factories_generate_full_degraded_conflicting_out_of_order_terminal_and_rework_sequences(): void
    {
        $factory = new AncillaryScenarioFactory;
        $full = $factory->full();
        $degraded = $factory->degraded();
        $conflict = $factory->conflictingSource();
        $outOfOrder = $factory->outOfOrder();
        $terminal = $factory->terminal();
        $rework = $factory->reworkLoop();

        $this->assertEqualsCanonicalizing(['RAD_ORDERED', 'RAD_EXAM_END', 'RAD_FINAL'], $full->milestones()->pluck('milestone_code')->all());
        $this->assertEqualsCanonicalizing(['RAD_ORDERED', 'RAD_FINAL'], $degraded->milestones()->pluck('milestone_code')->all());
        $this->assertSame('minimum', $degraded->metadata['feed_mode']);

        $this->assertSame(2, $conflict->milestones()->where('milestone_code', 'RAD_EXAM_END')->count());
        $selected = DB::table('prod.ancillary_current_assertions')
            ->where('ancillary_order_id', $conflict->ancillary_order_id)
            ->where('milestone_code', 'RAD_EXAM_END')
            ->first();
        $this->assertSame(10, $selected->source_rank);
        $this->assertSame(480, $selected->disagreement_seconds);

        $insertedCodes = $outOfOrder->milestones()->orderBy('ancillary_milestone_id')->pluck('milestone_code')->all();
        $this->assertSame(['RAD_FINAL', 'RAD_ORDERED'], $insertedCodes);
        $this->assertSame('cancelled', $terminal->current_state);
        $this->assertNotNull($terminal->terminal_at);
        $this->assertEqualsCanonicalizing(
            ['LAB_ORDERED', 'LAB_REJECTED', 'LAB_RECOLLECT_ORDERED', 'LAB_COLLECTED'],
            $rework->milestones()->pluck('milestone_code')->all(),
        );
    }

    public function test_milestone_model_refuses_update_before_query_reaches_database(): void
    {
        $milestone = AncillaryMilestone::factory()->create();
        $milestone->occurred_at = now()->subDay();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('append-only');
        $milestone->save();
    }

    public function test_sla_and_breach_factories_create_valid_governed_state(): void
    {
        $definition = AncillarySlaDefinition::factory()->create();
        $breach = AncillaryBreach::factory()->create();

        $this->assertTrue($definition->active);
        $this->assertSame('RAD_ORDERED', $definition->startMilestoneType->code);
        $this->assertSame('open', $breach->status);
        $this->assertNotNull($breach->order);
        $this->assertNotNull($breach->definition);
        $this->assertNotNull($breach->startAssertion);
        $this->assertSame($breach->ancillary_order_id, $breach->startAssertion->ancillary_order_id);
    }

    public function test_milestone_model_refuses_delete_before_query_reaches_database(): void
    {
        $milestone = AncillaryMilestone::factory()->create();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('append-only');
        $milestone->delete();
    }

    public function test_dtos_serialize_stable_camel_case_browser_contracts(): void
    {
        $asOf = new DateTimeImmutable('2026-07-11T12:00:00-04:00');
        $cutoff = new DateTimeImmutable('2026-07-11T11:57:00-04:00');
        $freshness = new FreshnessEnvelope('fresh', $asOf, $cutoff, 3, 'RIS production');
        $assertion = new MilestoneAssertion(
            'milestone-uuid',
            'RAD_FINAL',
            'Final report',
            $cutoff,
            $asOf,
            'ris-main',
            10,
            true,
            2,
            45,
        );
        $clock = new SelectedClock(
            'definition-uuid',
            'rad.stat_order_final',
            'STAT order to final',
            'RAD_ORDERED',
            'RAD_FINAL',
            $cutoff->modify('-60 minutes'),
            $cutoff,
            60,
            60,
            'complete',
            'Order placed to final report.',
            $freshness,
        );
        $axis = new ReadinessAxis('imaging', 'Imaging', 'pending', 2, 47, true, $freshness, '/radiology/worklist?encounter=42');
        $filters = new AncillaryPageFilters(['rad'], ['stat'], [7], ['ordered'], $cutoff, $asOf, 'study', 2, 25, 'cursor-1');

        $this->assertSame([
            'status', 'asOf', 'sourceCutoffAt', 'lagMinutes', 'sourceLabel', 'explanation',
        ], array_keys($freshness->toArray()));
        $this->assertArrayHasKey('milestoneUuid', $assertion->toArray());
        $this->assertArrayHasKey('disagreementSeconds', $assertion->toArray());
        $this->assertArrayHasKey('startMilestoneCode', $clock->toArray());
        $this->assertArrayHasKey('definitionText', $clock->toArray());
        $this->assertArrayHasKey('oldestAgeMinutes', $axis->toArray());
        $this->assertArrayHasKey('drillTarget', $axis->toArray());
        $this->assertArrayHasKey('topOrderUuid', $axis->toArray());
        $this->assertArrayHasKey('drillHref', $axis->toArray());
        $this->assertSame(25, $filters->toArray()['perPage']);
        $this->assertSame('2026-07-11T12:00:00-04:00', $freshness->toArray()['asOf']);
    }

    public function test_dtos_reject_impossible_freshness_clock_readiness_and_filter_states(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FreshnessEnvelope('fresh', new DateTimeImmutable, new DateTimeImmutable, -1, 'source');
    }

    private function createDefinition(): AncillarySlaDefinition
    {
        return AncillarySlaDefinition::query()->where('metric_key', 'rad.stat_order_final')->firstOrFail();
    }
}
