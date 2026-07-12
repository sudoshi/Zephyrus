<?php

namespace App\Services\Demo\Ancillary;

use App\Integrations\Healthcare\Ancillary\AncillaryEventVocabulary;
use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Integrations\Healthcare\Services\CanonicalEventWriter;
use App\Integrations\Healthcare\Services\ProjectionDispatcher;
use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Integration\Source;
use App\Services\Demo\DemoClock;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;

abstract class AbstractAncillaryDemoGenerator implements AncillaryDemoGenerator
{
    public function __construct(
        private readonly SourceRegistryService $sources,
        private readonly CanonicalEventWriter $writer,
        private readonly ProjectionDispatcher $projector,
    ) {}

    abstract protected function department(): string;

    abstract protected function systemClass(): string;

    /** @return list<array<string, mixed>> */
    abstract protected function scenarios(DemoClock $clock): array;

    public function preview(DemoClock $clock): array
    {
        $scenarios = $this->scenarios($clock);

        return [
            'department' => $this->department(),
            'orders' => count($scenarios),
            'milestones' => array_sum(array_map(fn (array $scenario): int => count($scenario['events']), $scenarios)),
            'expectedBreaches' => count(array_filter($scenarios, fn (array $scenario): bool => (bool) ($scenario['expected_breach'] ?? false))),
            'collisions' => $this->collisions($scenarios),
        ];
    }

    public function refresh(DemoClock $clock, string $owner): array
    {
        if (trim($owner) === '') {
            throw new RuntimeException('Ancillary demo owner must be explicit.');
        }
        $scenarios = $this->scenarios($clock);
        $collisions = $this->collisions($scenarios);
        if ($collisions !== []) {
            throw new RuntimeException('Refusing to replace non-owned ancillary natural keys: '.implode(', ', $collisions));
        }

        return DB::transaction(function () use ($clock, $owner, $scenarios): array {
            $sources = $this->governedSources($owner);
            $this->removeOwnedRows($owner);
            $events = 0;

            foreach ($scenarios as $scenario) {
                foreach ($scenario['events'] as $ordinal => $event) {
                    $source = $sources[$event['source'] ?? 'primary'];
                    $canonical = $this->canonicalEvent($clock, $owner, $scenario, $event, $ordinal);
                    $record = $this->writer->write($canonical, $source);
                    $this->projector->project($canonical->withEventId($record->event_id));
                    $record->update(['projection_status' => 'projected', 'projected_at' => now()]);
                    $events++;
                }
            }

            $orders = AncillaryOrder::query()->where('demo_owner', $owner)->where('department', $this->department())->count();

            return [
                'department' => $this->department(),
                'orders' => $orders,
                'milestones' => $events,
                'breaches' => DB::table('prod.ancillary_breaches as b')
                    ->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 'b.ancillary_order_id')
                    ->where('o.demo_owner', $owner)
                    ->where('o.department', $this->department())
                    ->count(),
                'collisions' => [],
            ];
        }, 3);
    }

    /** @param list<array<string, mixed>> $scenarios @return list<string> */
    private function collisions(array $scenarios): array
    {
        $sourceId = DB::table('integration.sources')->where('source_key', $this->sourceKey('primary'))->value('source_id');
        if ($sourceId === null) {
            return [];
        }

        $keys = array_column($scenarios, 'source_order_key');

        return DB::table('prod.ancillary_orders')
            ->where('source_id', $sourceId)
            ->where('department', $this->department())
            ->whereIn('source_order_key', $keys)
            ->where(fn ($query) => $query->whereNull('demo_owner')->orWhere('demo_owner', '!=', AncillaryDemoScenarioService::OWNER))
            ->orderBy('source_order_key')
            ->pluck('source_order_key')
            ->all();
    }

    /** @return array<string, Source> */
    private function governedSources(string $owner): array
    {
        return [
            'primary' => $this->source('primary', $this->systemClass(), $owner),
            'secondary' => $this->source('secondary', $this->secondarySystemClass(), $owner),
            'warehouse' => $this->source('warehouse', 'clinical_warehouse', $owner),
        ];
    }

    private function source(string $role, string $systemClass, string $owner): Source
    {
        return $this->sources->ensureSource([
            'source_key' => $this->sourceKey($role),
            'source_name' => 'Ancillary demo '.strtoupper($this->department())." {$role}",
            'vendor' => 'Zephyrus',
            'system_class' => $systemClass,
            'interface_type' => 'synthetic',
            'active_status' => 'inactive',
            'phi_allowed' => false,
            'go_live_status' => 'sandbox',
            'metadata' => ['demo_owner' => $owner, 'feed_mode' => $role === 'warehouse' ? 'warehouse' : 'realtime'],
        ]);
    }

    private function secondarySystemClass(): string
    {
        return match ($this->department()) {
            'rad' => 'mpps',
            'lab' => 'lab_middleware',
            'rx' => 'adc',
            default => $this->systemClass(),
        };
    }

    private function sourceKey(string $role): string
    {
        return 'demo.ancillary.'.$this->department().'.'.$role;
    }

    private function removeOwnedRows(string $owner): void
    {
        $orders = AncillaryOrder::query()->where('demo_owner', $owner)->where('department', $this->department());
        $orderIds = (clone $orders)->pluck('ancillary_order_id');
        $orderUuids = (clone $orders)->pluck('order_uuid');
        $canonicalIds = DB::table('prod.ancillary_milestones as m')
            ->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 'm.ancillary_order_id')
            ->where('o.demo_owner', $owner)
            ->where('o.department', $this->department())
            ->pluck('m.canonical_event_id');
        if ($orderUuids->isNotEmpty()) {
            DB::table('ops.operational_events')
                ->where('source_surface', 'ancillary_sla')
                ->whereIn(DB::raw("payload->>'orderUuid'"), $orderUuids)
                ->delete();
        }
        if ($canonicalIds->isNotEmpty()) {
            DB::table('integration.provenance_records')
                ->where('target_schema', 'prod')
                ->whereIn('target_table', ['ancillary_milestones', 'rad_reads', 'rad_critical_results'])
                ->whereIn('canonical_event_id', $canonicalIds)
                ->delete();
        }
        if ($orderIds->isNotEmpty()) {
            DB::table('prod.ancillary_breaches')->whereIn('ancillary_order_id', $orderIds)->delete();
        }

        DB::statement("SELECT set_config('zephyrus.allow_ancillary_demo_reset', 'on', true)");
        $orders->delete();
    }

    /** @param array<string, mixed> $scenario @param array<string, mixed> $event */
    private function canonicalEvent(
        DemoClock $clock,
        string $owner,
        array $scenario,
        array $event,
        int $ordinal,
    ): CanonicalOperationalEvent {
        $code = $event['code'];
        $occurredAt = $clock->anchor()->subMinutes((int) $event['minutes_ago']);
        $identity = implode('|', [$scenario['source_order_key'], $code, $ordinal, $event['source'] ?? 'primary']);
        $context = $this->clinicalContext((int) $scenario['ordinal'], $scenario);
        $payload = array_filter([
            'department' => $this->department(),
            'milestone_code' => $code,
            'work_item_type' => $this->workItemType(),
            'source_order_key' => $scenario['source_order_key'],
            'reconciliation_key' => $scenario['reconciliation_key'],
            'encounter_id' => $context?->encounter_id,
            'encounter_ref' => $context?->encounter_id !== null ? 'demo-encounter-'.$context->encounter_id : null,
            'ed_visit_id' => $context?->ed_visit_id,
            'patient_ref' => $context?->patient_ref,
            'patient_class' => $scenario['patient_class'],
            'priority' => $scenario['priority'],
            'ordered_at' => $clock->anchor()->subMinutes((int) $scenario['ordered_minutes_ago'])->toIso8601String(),
            'unit_id' => $context?->unit_id,
            'demo_owner' => $owner,
            'modality' => $scenario['metadata']['modality'] ?? null,
            'test_code' => $scenario['metadata']['test_code'] ?? null,
            'decision_class' => $scenario['metadata']['decision_class'] ?? null,
            'test_family' => $scenario['metadata']['test_family'] ?? null,
            'route' => $scenario['metadata']['route'] ?? null,
            'preparation_branch' => $scenario['metadata']['preparation_branch'] ?? null,
            'discharge_blocking' => $scenario['metadata']['discharge_blocking'] ?? null,
            'correction' => $event['correction'] ?? null,
            'source_exam_key' => $this->department() === 'rad' ? $scenario['reconciliation_key'] : null,
            'procedure_code' => $scenario['metadata']['procedure_code'] ?? null,
            'procedure_label' => $scenario['metadata']['procedure_label'] ?? null,
            'is_portable' => $scenario['metadata']['is_portable'] ?? null,
            'is_ir' => $scenario['metadata']['is_ir'] ?? null,
            'scanner_key' => $scenario['metadata']['scanner_key'] ?? null,
            'demo_context' => $scenario['metadata']['context'] ?? null,
            'demo_shift' => $scenario['metadata']['shift'] ?? null,
            'or_case_id' => $scenario['metadata']['or_case_id'] ?? null,
            ...$this->radiologyEventPayload($scenario, $event, $occurredAt, $identity),
            ...$this->laboratoryEventPayload($scenario, $event, $occurredAt, $context),
            'source_timestamp_valid' => true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return new CanonicalOperationalEvent(
            eventId: $this->uuid('event|'.$clock->anchor()->toDateString().'|'.$identity),
            eventType: AncillaryEventVocabulary::eventTypeFor($code),
            entityType: 'ancillary_order',
            entityRef: $scenario['source_order_key'],
            payload: $payload,
            occurredAt: $occurredAt,
            idempotencyKey: 'demo:ancillary:'.hash('sha256', $clock->anchor()->toDateString().'|'.$identity),
            correlationId: $scenario['reconciliation_key'],
            sequenceKey: $scenario['source_order_key'],
            metadata: ['demo_owner' => $owner],
        );
    }

    /** @param array<string, mixed> $scenario */
    private function clinicalContext(int $ordinal, array $scenario): ?object
    {
        if (($scenario['metadata']['context'] ?? null) === 'ed') {
            $query = DB::table('prod.ed_visits as v')
                ->where('v.is_deleted', false)
                ->orderByRaw('CASE WHEN v.departed_at IS NULL THEN 0 ELSE 1 END')
                ->orderBy('v.ed_visit_id');
            $candidateCount = (clone $query)->count();

            return $candidateCount > 0 ? $query
                ->offset(($ordinal - 1) % $candidateCount)
                ->first([
                    DB::raw('NULL::bigint AS encounter_id'),
                    'v.ed_visit_id',
                    'v.unit_id',
                    'v.patient_ref',
                ]) : null;
        }

        $query = DB::table('prod.encounters as e')
            ->where('is_deleted', false)
            ->when(($scenario['metadata']['context'] ?? null) === 'discharge', fn ($q) => $q->whereNotNull('expected_discharge_date'))
            ->orderByRaw('CASE WHEN e.discharged_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('e.encounter_id');
        $candidateCount = (clone $query)->count();
        $selected = $candidateCount > 0 ? $query
            ->offset(($ordinal - 1) % $candidateCount)
            ->first(['e.encounter_id', DB::raw('NULL::bigint AS ed_visit_id'), 'e.unit_id', 'e.patient_ref']) : null;

        return $selected ?? DB::table('prod.encounters')->where('is_deleted', false)->orderBy('encounter_id')->first(['encounter_id', DB::raw('NULL::bigint AS ed_visit_id'), 'unit_id', 'patient_ref']);
    }

    protected function scenario(DemoClock $clock, int $ordinal, int $orderedMinutesAgo, string $priority, string $patientClass, array $events, array $metadata = [], bool $expectedBreach = false): array
    {
        $key = implode(':', ['demo', $clock->anchor()->toDateString(), $this->department(), str_pad((string) $ordinal, 2, '0', STR_PAD_LEFT)]);

        return [
            'ordinal' => $ordinal,
            'source_order_key' => $key,
            'reconciliation_key' => hash('sha256', $key),
            'ordered_minutes_ago' => $orderedMinutesAgo,
            'priority' => $priority,
            'patient_class' => $patientClass,
            'events' => $events,
            'metadata' => $metadata,
            'expected_breach' => $expectedBreach,
        ];
    }

    protected function event(string $code, int $minutesAgo, string $source = 'primary', bool $correction = false, array $attributes = []): array
    {
        return [...$attributes, 'code' => $code, 'minutes_ago' => $minutesAgo, 'source' => $source, 'correction' => $correction];
    }

    /** @param array<string, mixed> $scenario @param array<string, mixed> $event @return array<string, mixed> */
    private function radiologyEventPayload(array $scenario, array $event, mixed $occurredAt, string $identity): array
    {
        if ($this->department() !== 'rad') {
            return [];
        }

        $code = $event['code'];
        $readStatus = match ($code) {
            'RAD_PRELIM' => 'preliminary',
            'RAD_FINAL' => ($event['correction'] ?? false) ? 'corrected' : 'final',
            default => null,
        };

        return array_filter([
            'scheduled_start_at' => isset($scenario['metadata']['scheduled_start_minutes_ago'])
                ? $occurredAt->addMinutes((int) $event['minutes_ago'] - (int) $scenario['metadata']['scheduled_start_minutes_ago'])->toIso8601String()
                : null,
            'scheduled_end_at' => isset($scenario['metadata']['scheduled_start_minutes_ago'], $scenario['metadata']['scheduled_duration_minutes'])
                ? $occurredAt->addMinutes(
                    (int) $event['minutes_ago']
                    - (int) $scenario['metadata']['scheduled_start_minutes_ago']
                    + (int) $scenario['metadata']['scheduled_duration_minutes']
                )->toIso8601String()
                : null,
            'exam_status' => match ($code) {
                'RAD_EXAM_START' => 'in_progress', 'RAD_EXAM_END', 'RAD_IMAGES_AVAILABLE' => 'complete',
                'RAD_CANCELLED' => 'cancelled', default => null,
            },
            'started_at' => $code === 'RAD_EXAM_START' ? $occurredAt->toIso8601String() : null,
            'completed_at' => $code === 'RAD_EXAM_END' ? $occurredAt->toIso8601String() : null,
            'cancelled_at' => $code === 'RAD_CANCELLED' ? $occurredAt->toIso8601String() : null,
            'read_status' => $readStatus,
            'source_read_key' => $readStatus !== null ? hash('sha256', $identity) : null,
            'source_report_version' => $readStatus !== null ? (string) ($event['report_version'] ?? 1) : null,
            'preliminary_at' => $readStatus === 'preliminary' ? $occurredAt->toIso8601String() : null,
            'final_at' => $readStatus === 'final' ? $occurredAt->toIso8601String() : null,
            'corrected_at' => $readStatus === 'corrected' ? $occurredAt->toIso8601String() : null,
            'critical_status' => $code === 'RAD_CRITICAL_NOTIFIED' ? 'notified' : ($code === 'RAD_CRITICAL_ACKED' ? 'acknowledged' : null),
            'source_result_key' => in_array($code, ['RAD_CRITICAL_NOTIFIED', 'RAD_CRITICAL_ACKED'], true) ? hash('sha256', $scenario['source_order_key'].'|critical') : null,
            'finding_class' => in_array($code, ['RAD_CRITICAL_NOTIFIED', 'RAD_CRITICAL_ACKED'], true) ? 'critical' : null,
            'identified_at' => $code === 'RAD_CRITICAL_NOTIFIED' ? $occurredAt->subMinutes(2)->toIso8601String() : null,
            'notified_at' => $code === 'RAD_CRITICAL_NOTIFIED' ? $occurredAt->toIso8601String() : null,
            'acknowledged_at' => $code === 'RAD_CRITICAL_ACKED' ? $occurredAt->toIso8601String() : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param array<string, mixed> $scenario @param array<string, mixed> $event @return array<string, mixed> */
    private function laboratoryEventPayload(array $scenario, array $event, mixed $occurredAt, ?object $context): array
    {
        if ($this->department() !== 'lab') {
            return [];
        }

        $code = (string) $event['code'];
        $specimenKey = $event['source_specimen_key'] ?? $scenario['metadata']['source_specimen_key'] ?? null;
        $decisionClass = $scenario['metadata']['decision_class'] ?? 'none';
        $blockedObjectId = match ($decisionClass) {
            'ed_disposition' => $context?->ed_visit_id,
            'discharge_gate' => $context?->encounter_id,
            'or_gate' => $scenario['metadata']['or_case_id'] ?? null,
            default => null,
        };
        $decisionContext = $decisionClass !== 'none' && $blockedObjectId !== null ? [
            'decision_class' => $decisionClass,
            'blocked_object_type' => match ($decisionClass) {
                'ed_disposition' => 'ed_visit',
                'discharge_gate' => 'encounter_discharge',
                'or_gate' => 'or_case',
                default => 'none',
            },
            'blocked_object_id' => (int) $blockedObjectId,
            'explanation' => (string) ($scenario['metadata']['decision_explanation'] ?? 'Pending Laboratory evidence blocks a downstream clinical decision.'),
        ] : null;

        $allowed = [
            'source_accession_key', 'parent_source_specimen_key', 'specimen_type', 'container_type',
            'collector_role', 'collection_method', 'collection_source', 'rejection_reason_code',
            'source_result_key', 'source_result_version', 'result_status', 'result_stage', 'test_code',
            'test_label', 'loinc_code', 'abnormal_flag', 'auto_verified', 'is_critical', 'analyzer_ref',
            'observed_at', 'resulted_at', 'verified_at', 'corrected_at', 'cancelled_at',
            'critical_identified_at', 'notified_at', 'acknowledged_at', 'recipient_role',
        ];
        $attributes = array_intersect_key($event, array_flip($allowed));

        return array_filter([
            ...$attributes,
            'source_specimen_key' => $specimenKey,
            'source_accession_key' => $event['source_accession_key'] ?? $scenario['metadata']['source_accession_key'] ?? null,
            'specimen_type' => $event['specimen_type'] ?? $scenario['metadata']['specimen_type'] ?? null,
            'container_type' => $event['container_type'] ?? $scenario['metadata']['container_type'] ?? null,
            'collector_role' => $event['collector_role'] ?? $scenario['metadata']['collector_role'] ?? null,
            'collection_method' => $event['collection_method'] ?? $scenario['metadata']['collection_method'] ?? null,
            'collected_at' => $code === 'LAB_COLLECTED' ? $occurredAt->toIso8601String() : ($event['collected_at'] ?? null),
            'in_transit_at' => $code === 'LAB_IN_TRANSIT' ? $occurredAt->toIso8601String() : ($event['in_transit_at'] ?? null),
            'received_at' => $code === 'LAB_RECEIVED' ? $occurredAt->toIso8601String() : ($event['received_at'] ?? null),
            'rejected_at' => $code === 'LAB_REJECTED' ? $occurredAt->toIso8601String() : ($event['rejected_at'] ?? null),
            'recollect_ordered_at' => $code === 'LAB_RECOLLECT_ORDERED' ? $occurredAt->toIso8601String() : ($event['recollect_ordered_at'] ?? null),
            'decision_context' => $decisionContext,
            'analyzer_operational_state' => $event['analyzer_operational_state'] ?? $scenario['metadata']['analyzer_operational_state'] ?? null,
            'analyzer_downtime_started_at' => $event['analyzer_downtime_started_at'] ?? $scenario['metadata']['analyzer_downtime_started_at'] ?? null,
            'analyzer_expected_restore_at' => $event['analyzer_expected_restore_at'] ?? $scenario['metadata']['analyzer_expected_restore_at'] ?? null,
            'operational_window' => $scenario['metadata']['operational_window'] ?? 'current',
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function workItemType(): string
    {
        return [
            'rad' => 'imaging_order',
            'lab' => 'lab_order',
            'pathology' => 'ap_case',
            'blood_bank' => 'blood_bank_request',
            'rx' => 'medication_order',
        ][$this->department()];
    }

    private function uuid(string $name): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'zephyrus|ancillary-demo|'.$name)->toString();
    }
}
