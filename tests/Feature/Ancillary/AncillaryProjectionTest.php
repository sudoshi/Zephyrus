<?php

namespace Tests\Feature\Ancillary;

use App\Integrations\Healthcare\Ancillary\AncillaryEventVocabulary;
use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Integrations\Healthcare\DTO\ReplayRequest;
use App\Integrations\Healthcare\DTO\WebhookEnvelope;
use App\Integrations\Healthcare\Services\AncillaryProjectionHandler;
use App\Integrations\Healthcare\Services\CanonicalEventWriter;
use App\Integrations\Healthcare\Services\IntegrationConfigurationAuditService;
use App\Integrations\Healthcare\Services\ProjectionDispatcher;
use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Integrations\Healthcare\Synthetic\SyntheticHealthcareConnector;
use App\Jobs\ReplayPendingIntegrationEvents;
use App\Models\Ancillary\AncillaryOrder;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AncillaryProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AncillaryReferenceSeeder::class);
    }

    public function test_synthetic_message_traverses_raw_canonical_milestone_provenance_and_projection(): void
    {
        $run = app(SyntheticHealthcareConnector::class)->handleWebhook(new WebhookEnvelope([
            'messages' => [$this->message('RAD_ORDERED', 'rad-order-1', 'rad-msg-1')],
        ]));

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->messages_succeeded);
        $this->assertDatabaseHas('raw.inbound_messages', [
            'external_id' => 'rad-msg-1',
            'parse_status' => 'projected',
        ]);
        $canonical = DB::table('integration.canonical_events')
            ->where('event_type', AncillaryEventVocabulary::eventTypeFor('RAD_ORDERED'))
            ->first();
        $this->assertNotNull($canonical);
        $this->assertSame('projected', $canonical->projection_status);

        $order = DB::table('prod.ancillary_orders')->where('source_order_key', 'rad-order-1')->first();
        $this->assertNotNull($order);
        $this->assertSame('rad', $order->department);
        $this->assertSame('RAD_ORDERED', $order->current_milestone_code);
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('ancillary_order_id', $order->ancillary_order_id)->count());
        $this->assertDatabaseHas('integration.provenance_records', [
            'canonical_event_id' => $canonical->canonical_event_id,
            'target_schema' => 'prod',
            'target_table' => 'ancillary_milestones',
        ]);
        $this->assertDatabaseHas('integration.connector_watermarks', [
            'connector_key' => 'synthetic.healthcare',
            'scope_type' => 'webhook',
        ]);
        $this->assertDatabaseMissing('integration.provenance_records', [
            'canonical_event_id' => $canonical->canonical_event_id,
            'target_table' => 'operational_events',
        ]);
    }

    public function test_duplicate_and_forced_replay_do_not_duplicate_assertions_or_provenance(): void
    {
        $connector = app(SyntheticHealthcareConnector::class);
        $message = $this->message('RAD_ORDERED', 'rad-order-replay', 'rad-msg-replay');
        $connector->handleWebhook(new WebhookEnvelope(['messages' => [$message]]));
        $second = $connector->handleWebhook(new WebhookEnvelope(['messages' => [$message]]));
        $canonicalId = (int) DB::table('integration.canonical_events')
            ->where('entity_ref', 'rad-order-replay')
            ->value('canonical_event_id');
        $connector->replay(new ReplayRequest([$canonicalId], force: true));

        $this->assertSame(1, $second->messages_skipped);
        $this->assertSame(1, DB::table('prod.ancillary_orders')->where('source_order_key', 'rad-order-replay')->count());
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('canonical_event_id', $canonicalId)->count());
        $this->assertSame(1, DB::table('integration.provenance_records')
            ->where('canonical_event_id', $canonicalId)
            ->where('target_table', 'ancillary_milestones')
            ->count());
    }

    public function test_out_of_order_history_is_retained_without_regressing_current_projection(): void
    {
        $connector = app(SyntheticHealthcareConnector::class);
        $orderedAt = now()->subHour()->startOfSecond();
        $connector->handleWebhook(new WebhookEnvelope(['messages' => [
            $this->message('RAD_FINAL', 'rad-order-late', 'rad-msg-final', [
                'ordered_at' => $orderedAt->toIso8601String(),
                'occurred_at' => $orderedAt->copy()->addMinutes(45)->toIso8601String(),
            ]),
        ]]));
        $connector->handleWebhook(new WebhookEnvelope(['messages' => [
            $this->message('RAD_ORDERED', 'rad-order-late', 'rad-msg-ordered-late', [
                'ordered_at' => $orderedAt->toIso8601String(),
                'occurred_at' => $orderedAt->toIso8601String(),
            ]),
        ]]));

        $order = DB::table('prod.ancillary_orders')->where('source_order_key', 'rad-order-late')->first();
        $this->assertSame('RAD_FINAL', $order->current_milestone_code);
        $this->assertSame(2, DB::table('prod.ancillary_milestones')->where('ancillary_order_id', $order->ancillary_order_id)->count());
        $this->assertSame(
            ['RAD_FINAL', 'RAD_ORDERED'],
            DB::table('prod.ancillary_milestones')
                ->where('ancillary_order_id', $order->ancillary_order_id)
                ->orderBy('ancillary_milestone_id')
                ->pluck('milestone_code')
                ->all(),
        );
    }

    public function test_late_valid_encounter_identity_links_existing_order_without_duplication(): void
    {
        $unitId = DB::table('prod.units')->insertGetId([
            'name' => 'Projection unit', 'type' => 'med_surg', 'created_at' => now(), 'updated_at' => now(),
        ], 'unit_id');
        $encounterId = DB::table('prod.encounters')->insertGetId([
            'patient_ref' => 'encounter-pseudonym', 'unit_id' => $unitId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ], 'encounter_id');
        $connector = app(SyntheticHealthcareConnector::class);
        $connector->handleWebhook(new WebhookEnvelope(['messages' => [
            $this->message('RAD_ORDERED', 'rad-order-link', 'rad-msg-link-1'),
        ]]));
        $connector->handleWebhook(new WebhookEnvelope(['messages' => [
            $this->message('RAD_EXAM_START', 'rad-order-link', 'rad-msg-link-2', [
                'encounter_id' => $encounterId,
                'unit_id' => $unitId,
            ]),
        ]]));

        $this->assertSame(1, DB::table('prod.ancillary_orders')->where('source_order_key', 'rad-order-link')->count());
        $this->assertDatabaseHas('prod.ancillary_orders', [
            'source_order_key' => 'rad-order-link',
            'encounter_id' => $encounterId,
            'unit_id' => $unitId,
        ]);
    }

    public function test_corrections_append_history_and_late_events_do_not_regress_terminal_state(): void
    {
        $connector = app(SyntheticHealthcareConnector::class);
        $base = now()->subHour()->startOfSecond();
        foreach ([
            $this->message('LAB_ORDERED', 'lab-correction-order', 'lab-correction-1', ['ordered_at' => $base->toIso8601String(), 'occurred_at' => $base->toIso8601String()]),
            $this->message('LAB_VERIFIED', 'lab-correction-order', 'lab-correction-2', ['ordered_at' => $base->toIso8601String(), 'occurred_at' => $base->copy()->addMinutes(30)->toIso8601String()]),
            $this->message('LAB_CORRECTED', 'lab-correction-order', 'lab-correction-3', [
                'ordered_at' => $base->toIso8601String(),
                'occurred_at' => $base->copy()->addMinutes(40)->toIso8601String(),
                'correction' => true,
                'supersedes_assertion_key' => 'source-correction-reference',
            ]),
        ] as $message) {
            $connector->handleWebhook(new WebhookEnvelope(['messages' => [$message]]));
        }

        $labOrder = AncillaryOrder::query()->where('source_order_key', 'lab-correction-order')->firstOrFail();
        $this->assertSame('LAB_CORRECTED', $labOrder->current_milestone_code);
        $this->assertSame(3, $labOrder->milestones()->count());
        $this->assertTrue((bool) $labOrder->milestones()->where('milestone_code', 'LAB_CORRECTED')->firstOrFail()->metadata['correction']);

        foreach ([
            $this->message('RAD_ORDERED', 'rad-cancel-order', 'rad-cancel-1', ['ordered_at' => $base->toIso8601String(), 'occurred_at' => $base->toIso8601String()]),
            $this->message('RAD_CANCELLED', 'rad-cancel-order', 'rad-cancel-2', ['ordered_at' => $base->toIso8601String(), 'occurred_at' => $base->copy()->addMinutes(20)->toIso8601String()]),
            $this->message('RAD_EXAM_START', 'rad-cancel-order', 'rad-cancel-late', ['ordered_at' => $base->toIso8601String(), 'occurred_at' => $base->copy()->addMinutes(10)->toIso8601String()]),
        ] as $message) {
            $connector->handleWebhook(new WebhookEnvelope(['messages' => [$message]]));
        }

        $cancelled = AncillaryOrder::query()->where('source_order_key', 'rad-cancel-order')->firstOrFail();
        $this->assertSame('RAD_CANCELLED', $cancelled->current_milestone_code);
        $this->assertNotNull($cancelled->terminal_at);
        $this->assertSame(3, $cancelled->milestones()->count());
    }

    public function test_competing_sources_reconcile_to_one_order_retain_both_and_select_configured_precedence(): void
    {
        $sources = app(SourceRegistryService::class);
        $ris = $sources->ensureSource([
            'source_key' => 'ancillary.ris.test',
            'system_class' => 'ris',
            'metadata' => ['ancillary_source_class' => 'ris'],
        ]);
        $mpps = $sources->ensureSource([
            'source_key' => 'ancillary.mpps.test',
            'system_class' => 'pacs',
            'metadata' => ['ancillary_source_class' => 'mpps'],
        ]);
        $base = CarbonImmutable::parse('2026-07-11T12:00:00-04:00');

        $this->projectDirect($ris, 'RAD_EXAM_END', 'ris-order-1', 'shared-study-1', $base->addMinutes(30));
        $this->projectDirect($mpps, 'RAD_EXAM_END', 'mpps-study-9', 'shared-study-1', $base->addMinutes(40));

        $this->assertSame(1, AncillaryOrder::query()->where('department', 'rad')->count());
        $order = AncillaryOrder::query()->firstOrFail();
        $this->assertSame(2, $order->milestones()->where('milestone_code', 'RAD_EXAM_END')->count());
        $selected = DB::table('prod.ancillary_current_assertions')
            ->where('ancillary_order_id', $order->ancillary_order_id)
            ->where('milestone_code', 'RAD_EXAM_END')
            ->first();
        $this->assertSame($mpps->source_id, $selected->source_id);
        $this->assertSame(1, $selected->source_rank);
        $this->assertSame(600, $selected->disagreement_seconds);
        $this->assertTrue($order->refresh()->metadata['has_source_conflict']);
        $this->assertSame('RAD_EXAM_END', $order->current_milestone_code);
    }

    public function test_event_milestone_department_mismatch_is_sanitized_to_dead_letter(): void
    {
        $message = $this->message('RAD_ORDERED', 'bad-order', 'bad-msg');
        $message['event_type'] = AncillaryEventVocabulary::eventTypeFor('LAB_ORDERED');
        $run = app(SyntheticHealthcareConnector::class)->handleWebhook(new WebhookEnvelope(['messages' => [$message]]));

        $this->assertSame('failed', $run->status);
        $deadLetter = DB::table('raw.dead_letters')->where('inbound_message_id', function ($query): void {
            $query->select('inbound_message_id')->from('raw.inbound_messages')->where('external_id', 'bad-msg')->limit(1);
        })->first();
        $this->assertNotNull($deadLetter);
        $this->assertSame('message_mapping_failed', $deadLetter->reason_code);
        $this->assertStringNotContainsString('patient-pseudonym', $deadLetter->message);
        $this->assertSame(0, DB::table('prod.ancillary_orders')->where('source_order_key', 'bad-order')->count());
    }

    public function test_rebuild_command_restores_byte_equivalent_projection_and_supports_bounded_dry_run(): void
    {
        $connector = app(SyntheticHealthcareConnector::class);
        $connector->handleWebhook(new WebhookEnvelope(['messages' => [
            $this->message('RAD_ORDERED', 'rad-order-rebuild', 'rad-msg-rebuild-1'),
            $this->message('RAD_FINAL', 'rad-order-rebuild', 'rad-msg-rebuild-2'),
        ]]));
        $order = AncillaryOrder::query()->where('source_order_key', 'rad-order-rebuild')->firstOrFail();
        $expected = $this->projectionBytes($order);

        $order->update([
            'current_state' => 'corrupted',
            'current_milestone_code' => 'RAD_ORDERED',
            'current_milestone_at' => $order->ordered_at,
            'terminal_at' => null,
        ]);
        $this->assertSame(0, Artisan::call('ancillary:rebuild-projections', [
            '--order-id' => [$order->ancillary_order_id],
            '--dry-run' => true,
        ]));
        $this->assertSame('corrupted', $order->refresh()->current_state);

        $this->assertSame(0, Artisan::call('ancillary:rebuild-projections', [
            '--order-id' => [$order->ancillary_order_id],
        ]));
        $this->assertSame($expected, $this->projectionBytes($order->refresh()));
    }

    public function test_pending_replay_job_dispatches_ancillary_family_and_records_real_projector(): void
    {
        $source = app(SourceRegistryService::class)->ensureSource([
            'source_key' => 'ancillary.replay.source',
            'system_class' => 'ris',
            'metadata' => ['ancillary_source_class' => 'ris'],
        ]);
        $occurredAt = CarbonImmutable::now()->subMinutes(5);
        $event = new CanonicalOperationalEvent(
            eventId: (string) Str::uuid(),
            eventType: AncillaryEventVocabulary::eventTypeFor('RAD_ORDERED'),
            entityType: 'ancillary_order',
            entityRef: 'replay-rad-order',
            payload: [
                'department' => 'rad',
                'milestone_code' => 'RAD_ORDERED',
                'source_order_key' => 'replay-rad-order',
                'patient_class' => 'inpatient',
                'priority' => 'routine',
                'ordered_at' => $occurredAt->toIso8601String(),
            ],
            occurredAt: $occurredAt,
            idempotencyKey: 'ancillary-replay-event',
        );
        $record = app(CanonicalEventWriter::class)->write($event, $source);
        $replayId = DB::table('integration.event_replay_jobs')->insertGetId([
            'replay_uuid' => (string) Str::uuid(),
            'replay_type' => 'canonical_event_replay',
            'status' => 'queued',
            'scope' => json_encode([
                'sourceId' => $source->source_id,
                'from' => $occurredAt->subMinute()->toIso8601String(),
                'to' => $occurredAt->addMinute()->toIso8601String(),
                'eventTypes' => [$event->eventType],
                'limit' => 10,
            ], JSON_THROW_ON_ERROR),
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'event_replay_job_id');

        (new ReplayPendingIntegrationEvents($replayId, null, (string) Str::uuid()))->handle(
            app(ProjectionDispatcher::class),
            app(IntegrationConfigurationAuditService::class),
        );

        $this->assertDatabaseHas('integration.event_replay_jobs', [
            'event_replay_job_id' => $replayId,
            'status' => 'completed',
            'events_replayed' => 1,
            'events_failed' => 0,
        ]);
        $this->assertDatabaseHas('integration.canonical_events', [
            'canonical_event_id' => $record->canonical_event_id,
            'projection_status' => 'projected',
        ]);
        $this->assertDatabaseHas('prod.ancillary_orders', ['source_order_key' => 'replay-rad-order']);
        $this->assertDatabaseHas('prod.ancillary_milestones', ['canonical_event_id' => $record->canonical_event_id]);
        $this->assertSame(0, DB::table('integration.event_projection_errors')->where('canonical_event_id', $record->canonical_event_id)->count());
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function message(string $milestoneCode, string $sourceOrderKey, string $externalId, array $overrides = []): array
    {
        $department = AncillaryEventVocabulary::departmentFor($milestoneCode);

        return array_merge([
            'message_type' => 'synthetic.'.AncillaryEventVocabulary::eventTypeFor($milestoneCode),
            'event_type' => AncillaryEventVocabulary::eventTypeFor($milestoneCode),
            'external_id' => $externalId,
            'department' => $department,
            'milestone_code' => $milestoneCode,
            'source_order_key' => $sourceOrderKey,
            'patient_ref' => 'patient-pseudonym',
            'patient_class' => 'emergency',
            'priority' => 'stat',
            'ordered_at' => now()->subMinutes(30)->toIso8601String(),
            'occurred_at' => now()->toIso8601String(),
        ], $overrides);
    }

    private function projectDirect(
        \App\Models\Integration\Source $source,
        string $milestoneCode,
        string $sourceOrderKey,
        string $reconciliationKey,
        CarbonImmutable $occurredAt,
    ): void {
        $event = new CanonicalOperationalEvent(
            eventId: (string) Str::uuid(),
            eventType: AncillaryEventVocabulary::eventTypeFor($milestoneCode),
            entityType: 'ancillary_order',
            entityRef: $sourceOrderKey,
            payload: [
                'department' => AncillaryEventVocabulary::departmentFor($milestoneCode),
                'milestone_code' => $milestoneCode,
                'source_order_key' => $sourceOrderKey,
                'reconciliation_key' => $reconciliationKey,
                'patient_class' => 'inpatient',
                'priority' => 'routine',
                'ordered_at' => $occurredAt->subMinutes(30)->toIso8601String(),
            ],
            occurredAt: $occurredAt,
            idempotencyKey: 'direct-'.Str::uuid(),
        );
        $record = app(CanonicalEventWriter::class)->write($event, $source);
        app(AncillaryProjectionHandler::class)->project($event->withEventId($record->event_id));
    }

    private function projectionBytes(AncillaryOrder $order): string
    {
        return json_encode([
            'current_state' => $order->current_state,
            'current_milestone_code' => $order->current_milestone_code,
            'current_milestone_at' => $order->current_milestone_at?->toJSON(),
            'terminal_at' => $order->terminal_at?->toJSON(),
            'source_cutoff_at' => $order->source_cutoff_at?->toJSON(),
            'metadata' => $order->metadata,
        ], JSON_THROW_ON_ERROR);
    }
}
