<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Ancillary\AncillaryEventVocabulary;
use App\Integrations\Healthcare\Contracts\ProjectionHandler;
use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Models\Ancillary\AncillaryMilestone;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Encounter;
use App\Models\Integration\CanonicalEventRecord;
use App\Models\Integration\ProvenanceRecord;
use App\Services\Ancillary\AncillaryProjectionRebuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AncillaryProjectionHandler implements ProjectionHandler
{
    private const WORK_ITEM_TYPES = [
        'rad' => 'imaging_order',
        'lab' => 'lab_order',
        'pathology' => 'ap_case',
        'blood_bank' => 'blood_bank_request',
        'rx' => 'medication_order',
    ];

    public function __construct(
        private readonly AncillaryProjectionRebuilder $rebuilder,
        private readonly \App\Services\Ancillary\SlaEvaluator $slaEvaluator,
        private readonly \App\Services\Radiology\RadiologyOrderProjector $radiologyOrderProjector,
        private readonly \App\Services\Radiology\RadiologyReadProjector $radiologyReadProjector,
        private readonly \App\Services\Radiology\RadiologyCriticalResultProjector $radiologyCriticalResultProjector,
    ) {}

    public function key(): string
    {
        return 'ancillary.milestone_projection';
    }

    public function eventTypes(): array
    {
        return AncillaryEventVocabulary::eventTypes();
    }

    public function supports(CanonicalOperationalEvent $event): bool
    {
        return in_array($event->eventType, $this->eventTypes(), true);
    }

    public function project(CanonicalOperationalEvent $event): void
    {
        if (! $this->supports($event)) {
            throw new InvalidArgumentException("Unsupported ancillary projection event [{$event->eventType}].");
        }

        $orderId = DB::transaction(function () use ($event): int {
            $record = CanonicalEventRecord::query()
                ->with('source')
                ->where('event_id', $event->eventId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($record->source === null) {
                throw new InvalidArgumentException('Ancillary projection requires a governed integration source.');
            }

            $milestoneCode = $this->requiredString($event->payload, 'milestone_code');
            $department = $this->requiredString($event->payload, 'department');
            if (AncillaryEventVocabulary::milestoneCodeFor($event->eventType) !== $milestoneCode
                || AncillaryEventVocabulary::departmentFor($milestoneCode) !== $department) {
                throw new InvalidArgumentException('Ancillary event type, milestone code, and department do not agree.');
            }

            $catalog = DB::table('hosp_ref.ancillary_milestone_types')->where('code', $milestoneCode)->first();
            if ($catalog === null || $catalog->department !== $department) {
                throw new InvalidArgumentException('Ancillary milestone is not present in the governed catalog.');
            }

            $workItemType = (string) ($event->payload['work_item_type'] ?? self::WORK_ITEM_TYPES[$department] ?? '');
            if ($workItemType !== (self::WORK_ITEM_TYPES[$department] ?? null)) {
                throw new InvalidArgumentException('Ancillary work-item type does not match its department.');
            }

            $sourceOrderKey = $this->requiredString($event->payload, 'source_order_key');
            $orderedAt = is_string($event->payload['ordered_at'] ?? null)
                ? CarbonImmutable::parse($event->payload['ordered_at'])->utc()
                : $event->occurredAt;
            $encounterId = $this->resolvedEncounterId($event->payload['encounter_id'] ?? null);
            $order = $this->resolveOrder(
                sourceId: (int) $record->source_id,
                department: $department,
                sourceOrderKey: $sourceOrderKey,
                reconciliationKey: $event->payload['reconciliation_key'] ?? null,
                creationAttributes: [
                    'order_uuid' => (string) Str::uuid(),
                    'work_item_type' => $workItemType,
                    'encounter_id' => $encounterId,
                    'encounter_ref' => $event->payload['encounter_ref'] ?? null,
                    'patient_ref' => $event->payload['patient_ref'] ?? null,
                    'patient_class' => $event->payload['patient_class'] ?? 'unknown',
                    'priority' => $event->payload['priority'] ?? 'unknown',
                    'ordered_at' => $orderedAt,
                    'current_state' => 'ordered',
                    'unit_id' => $event->payload['unit_id'] ?? null,
                    'source_cutoff_at' => $record->received_at->greaterThan($event->occurredAt)
                        ? $record->received_at
                        : $event->occurredAt,
                    'demo_owner' => $event->payload['demo_owner'] ?? null,
                    'metadata' => $this->operationalMetadata($event->payload),
                ],
            );

            $this->applyLateLinks($order, $event, $encounterId);

            $assertionKey = hash('sha256', implode('|', [
                $event->idempotencyKey,
                $record->source_id,
                $order->ancillary_order_id,
                $milestoneCode,
            ]));
            $milestone = AncillaryMilestone::query()->firstOrCreate(
                ['assertion_key' => $assertionKey],
                [
                    'milestone_uuid' => (string) Str::uuid(),
                    'ancillary_order_id' => $order->ancillary_order_id,
                    'milestone_code' => $milestoneCode,
                    'occurred_at' => $event->occurredAt,
                    'received_at' => $record->received_at,
                    'source_id' => $record->source_id,
                    'canonical_event_id' => $record->canonical_event_id,
                    'source_rank' => $this->sourceRank($record->source->metadata, $record->source->system_class, $catalog),
                    'metadata' => $this->assertionMetadata($event->payload),
                ],
            );

            ProvenanceRecord::query()->firstOrCreate(
                [
                    'canonical_event_id' => $record->canonical_event_id,
                    'target_schema' => 'prod',
                    'target_table' => 'ancillary_milestones',
                    'target_pk' => (string) $milestone->ancillary_milestone_id,
                ],
                [
                    'source_id' => $record->source_id,
                    'inbound_message_id' => $record->inbound_message_id,
                    'lineage' => [
                        'canonicalEventId' => $record->canonical_event_id,
                        'ancillaryOrderId' => $order->ancillary_order_id,
                        'assertionKey' => $assertionKey,
                    ],
                ],
            );

            $this->rebuilder->rebuild($order);

            if ($department === 'rad' && isset($event->payload['source_exam_key'])) {
                $this->radiologyOrderProjector->project($order->fresh(), $event, (int) $record->source_id);
            }
            if ($department === 'rad' && isset($event->payload['read_status'])) {
                $read = $this->radiologyReadProjector->project($order->fresh(), $event, (int) $record->source_id);
                ProvenanceRecord::query()->firstOrCreate(
                    [
                        'canonical_event_id' => $record->canonical_event_id,
                        'target_schema' => 'prod',
                        'target_table' => 'rad_reads',
                        'target_pk' => (string) $read->rad_read_id,
                    ],
                    [
                        'source_id' => $record->source_id,
                        'inbound_message_id' => $record->inbound_message_id,
                        'lineage' => [
                            'canonicalEventId' => $record->canonical_event_id,
                            'ancillaryOrderId' => $order->ancillary_order_id,
                            'radExamId' => $read->rad_exam_id,
                            'sourceReportVersion' => $read->source_report_version,
                        ],
                    ],
                );
            }
            if ($department === 'rad' && isset($event->payload['critical_status'])) {
                $critical = $this->radiologyCriticalResultProjector->project($order->fresh(), $event, (int) $record->source_id);
                ProvenanceRecord::query()->firstOrCreate(
                    ['canonical_event_id' => $record->canonical_event_id, 'target_schema' => 'prod', 'target_table' => 'rad_critical_results', 'target_pk' => (string) $critical->rad_critical_result_id],
                    ['source_id' => $record->source_id, 'inbound_message_id' => $record->inbound_message_id, 'lineage' => ['canonicalEventId' => $record->canonical_event_id, 'ancillaryOrderId' => $order->ancillary_order_id, 'radExamId' => $critical->rad_exam_id]],
                );
            }

            return (int) $order->ancillary_order_id;
        });

        // SLA failure is isolated from the already-committed source fact. The
        // per-minute catch-up reports and retries the affected order.
        $this->slaEvaluator->evaluateOrderSafely($orderId);
    }

    /** @param array<string, mixed> $payload */
    private function applyLateLinks(AncillaryOrder $order, CanonicalOperationalEvent $event, ?int $encounterId): void
    {
        $updates = [];
        $metadata = $order->metadata;
        if ($order->encounter_id === null && $encounterId !== null) {
            $updates['encounter_id'] = $encounterId;
        } elseif ($order->encounter_id !== null && $encounterId !== null && (int) $order->encounter_id !== $encounterId) {
            $metadata['identity_conflict'] = ['candidateEncounterId' => $encounterId];
        }
        if ($order->encounter_ref === null && filled($event->payload['encounter_ref'] ?? null)) {
            $updates['encounter_ref'] = $event->payload['encounter_ref'];
        }
        if ($order->unit_id === null && isset($event->payload['unit_id'])) {
            $updates['unit_id'] = (int) $event->payload['unit_id'];
        }
        if ($metadata !== $order->metadata) {
            $updates['metadata'] = $metadata;
        }
        if ($updates !== []) {
            $order->update($updates);
        }
    }

    private function resolvedEncounterId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return Encounter::query()->whereKey($id)->exists() ? $id : null;
    }

    /** @param array<string, mixed> $creationAttributes */
    private function resolveOrder(
        int $sourceId,
        string $department,
        string $sourceOrderKey,
        mixed $reconciliationKey,
        array $creationAttributes,
    ): AncillaryOrder {
        if (is_scalar($reconciliationKey) && trim((string) $reconciliationKey) !== '') {
            $key = trim((string) $reconciliationKey);
            $matches = AncillaryOrder::query()
                ->where('department', $department)
                ->whereRaw("metadata->>'reconciliation_key' = ?", [$key])
                ->limit(2)
                ->get();
            if ($matches->count() > 1) {
                throw new InvalidArgumentException('Ancillary reconciliation identity is ambiguous.');
            }
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return AncillaryOrder::query()->firstOrCreate(
            [
                'source_id' => $sourceId,
                'department' => $department,
                'source_order_key' => $sourceOrderKey,
            ],
            $creationAttributes,
        );
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function operationalMetadata(array $payload): array
    {
        return array_filter([
            'modality' => $payload['modality'] ?? null,
            'test_code' => $payload['test_code'] ?? null,
            'test_family' => $payload['test_family'] ?? null,
            'decision_class' => $payload['decision_class'] ?? null,
            'route' => $payload['route'] ?? null,
            'preparation_branch' => $payload['preparation_branch'] ?? null,
            'discharge_blocking' => $payload['discharge_blocking'] ?? null,
            'demo_context' => $payload['demo_context'] ?? null,
            'demo_shift' => $payload['demo_shift'] ?? null,
            'ed_visit_id' => $payload['ed_visit_id'] ?? null,
            'or_case_id' => $payload['or_case_id'] ?? null,
            'reconciliation_key' => $payload['reconciliation_key'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function assertionMetadata(array $payload): array
    {
        return array_filter([
            'correction' => $payload['correction'] ?? null,
            'supersedes_assertion_key' => $payload['supersedes_assertion_key'] ?? null,
            'source_timestamp_valid' => $payload['source_timestamp_valid'] ?? true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param array<string, mixed> $sourceMetadata */
    private function sourceRank(array $sourceMetadata, string $systemClass, object $catalog): int
    {
        $precedence = $sourceMetadata['ancillary_precedence'][$catalog->code]
            ?? $sourceMetadata['ancillary_precedence'][$catalog->department]
            ?? json_decode($catalog->source_precedence ?? '[]', true)
            ?? [];
        $sourceClass = (string) ($sourceMetadata['ancillary_source_class'] ?? $systemClass);
        $index = array_search($sourceClass, is_array($precedence) ? $precedence : [], true);

        return $index === false ? 1000 : $index + 1;
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new InvalidArgumentException("Missing required ancillary field [{$key}].");
        }

        return trim((string) $value);
    }
}
