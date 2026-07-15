<?php

namespace App\Integrations\Healthcare\Ancillary;

use App\Integrations\Healthcare\Contracts\CanonicalEventMapper;
use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AncillaryCanonicalEventMapper implements CanonicalEventMapper
{
    public function map(NormalizedPayload $payload): array
    {
        $groups = $payload->payload['order_groups'] ?? null;
        if (! is_array($groups) || $groups === []) {
            $groups = [$payload->payload];
        }

        $events = [];
        foreach (array_values($groups) as $index => $data) {
            if (! is_array($data)) {
                throw new InvalidArgumentException('Ancillary normalized order group must be an object.');
            }
            $events[] = match (true) {
                ($data['adc_station_scope'] ?? false) === true => $this->mapStationScope($payload, $data, $index),
                ($data['rx_administration_scope'] ?? false) === true => $this->mapAdministrationRecord($payload, $data, $index),
                default => $this->mapOne($payload, $data, $index),
            };
        }

        return $events;
    }

    /**
     * Station/unit-scoped ADC operational events carry no milestone and no
     * ancillary order; they project onto the station registry and the
     * adc_transactions rollup ledger only.
     *
     * @param  array<string, mixed>  $data
     */
    private function mapStationScope(NormalizedPayload $payload, array $data, int $index): CanonicalOperationalEvent
    {
        if ($this->required($data, 'department') !== 'rx') {
            throw new InvalidArgumentException('ADC station-scope events belong to the pharmacy department.');
        }
        $transactionKey = $this->required($data, 'source_transaction_key');
        $transactionType = $this->required($data, 'transaction_type');
        $stationKey = $this->required($data, 'station_key');
        $eventType = PharmacyAdcTransactionNormalizer::STATION_EVENT_TYPE;

        $safe = Arr::only($data, [
            'department', 'adc_station_scope', 'source_transaction_key', 'transaction_type',
            'rx_event_code', 'station_key', 'station_unit', 'station_label', 'station_type',
            'station_is_profiled', 'station_controlled_capable', 'local_code', 'ndc_code',
            'medication_label', 'quantity', 'is_controlled', 'discrepancy_key', 'stockout_state',
            'linked_order_key', 'demo_owner', 'source_timestamp_valid',
        ]);

        return new CanonicalOperationalEvent(
            eventId: (string) Str::uuid(),
            eventType: $eventType,
            entityType: 'adc_station',
            entityRef: $stationKey,
            payload: $safe,
            occurredAt: CanonicalOperationalEvent::occurredAt($data['occurred_at'] ?? $payload->occurredAt),
            idempotencyKey: "{$payload->idempotencyKey}:group:{$index}:{$eventType}:{$transactionType}:{$transactionKey}",
            correlationId: $payload->externalId,
            sequenceKey: $stationKey,
            metadata: [
                ...$payload->metadata,
                'source_message_type' => $payload->messageType,
            ],
        );
    }

    /**
     * Non-given administration records (held/refused/missed) are order-level
     * satellite facts without a milestone: they carry the strict order-match
     * keys plus the versioned warehouse row identity and as-of cutoff, and
     * project onto prod.rx_administrations only.
     *
     * @param  array<string, mixed>  $data
     */
    private function mapAdministrationRecord(NormalizedPayload $payload, array $data, int $index): CanonicalOperationalEvent
    {
        if ($this->required($data, 'department') !== 'rx') {
            throw new InvalidArgumentException('Administration record events belong to the pharmacy department.');
        }
        $administrationKey = $this->required($data, 'source_administration_key');
        $sourceOrderKey = $this->required($data, 'source_order_key');
        $eventType = PharmacyAdministrationImportNormalizer::RECORD_EVENT_TYPE;

        $safe = Arr::only($data, [
            'department', 'rx_administration_scope', 'require_existing_order', 'source_order_key',
            'reconciliation_key', 'placer_order_key', 'source_administration_key', 'source_row_version',
            'import_batch_key', 'administration_source_class', 'administration_status',
            'administration_route', 'dosage_form', 'administration_local_code', 'administration_ndc_code',
            'administration_rxnorm_cui', 'administration_medication_label', 'administered_at',
            'source_cutoff_at', 'patient_ref', 'encounter_ref', 'demo_owner', 'source_timestamp_valid',
        ]);

        return new CanonicalOperationalEvent(
            eventId: (string) Str::uuid(),
            eventType: $eventType,
            entityType: 'rx_administration',
            entityRef: $administrationKey,
            payload: $safe,
            occurredAt: CanonicalOperationalEvent::occurredAt($data['occurred_at'] ?? $payload->occurredAt),
            idempotencyKey: "{$payload->idempotencyKey}:group:{$index}:{$eventType}:{$administrationKey}",
            correlationId: $payload->externalId,
            sequenceKey: $sourceOrderKey,
            metadata: [
                ...$payload->metadata,
                'source_message_type' => $payload->messageType,
            ],
        );
    }

    /** @param array<string, mixed> $data */
    private function mapOne(NormalizedPayload $payload, array $data, int $index): CanonicalOperationalEvent
    {
        $milestoneCode = $this->required($data, 'milestone_code');
        $department = $this->required($data, 'department');
        $sourceOrderKey = $this->required($data, 'source_order_key');
        $eventType = AncillaryEventVocabulary::eventTypeFor($milestoneCode);
        if (AncillaryEventVocabulary::departmentFor($milestoneCode) !== $department) {
            throw new InvalidArgumentException('Ancillary normalized event type, milestone code, and department do not agree.');
        }

        $safe = Arr::only($data, [
            'department', 'milestone_code', 'work_item_type', 'source_order_key', 'reconciliation_key',
            'encounter_id', 'encounter_ref', 'patient_ref', 'patient_class', 'priority', 'ordered_at',
            'unit_id', 'modality', 'test_code', 'test_family', 'decision_class', 'route',
            'preparation_branch', 'discharge_blocking', 'correction', 'supersedes_assertion_key',
            'source_timestamp_valid', 'source_exam_key', 'procedure_code', 'procedure_label',
            'body_region', 'subspecialty_code', 'protocol', 'contrast_status', 'is_portable', 'is_ir',
            'scheduled_start_at', 'scheduled_end_at', 'exam_status', 'degraded_fields',
            'read_status', 'source_read_key', 'source_report_version', 'radiologist_ref',
            'subspecialty_code', 'is_teleradiology', 'preliminary_at', 'final_at', 'corrected_at',
            'scanner_key', 'source_sop_instance_uid_hash', 'started_at', 'completed_at', 'cancelled_at',
            'transport_request_ref', 'relay_signature', 'critical_status', 'source_result_key',
            'finding_class', 'identified_at', 'notified_at', 'acknowledged_at', 'recipient_role',
            'placer_order_key', 'filler_order_key', 'order_control', 'order_status', 'test_label',
            'loinc_code', 'add_on', 'source_specimen_key', 'source_specimen_business_key',
            'source_accession_key', 'specimen_type', 'container_type', 'collector_role',
            'collector_ref', 'collection_method', 'collected_at', 'specimen_status',
            'collection_source', 'specimens',
            'ordered_at_source',
            'source_result_version', 'result_status', 'result_stage', 'abnormal_flag',
            'auto_verified', 'is_critical', 'analyzer_ref', 'observed_at', 'resulted_at',
            'middleware_ref',
            'verified_at', 'critical_identified_at', 'result_time_source', 'obx_set_id',
            'received_at', 'parent_source_specimen_key', 'rejection_reason_code',
            'rejected_at', 'recollect_ordered_at', 'source_critical_key',
            'critical_callback_state',
            'clock_class', 'due_at', 'local_code', 'rxnorm_cui', 'ndc_code',
            'medication_label', 'dosage_form', 'hl7_priority', 'order_change',
            'modified_at', 'discontinued_at', 'queue_state', 'queue_ref',
            'source_verification_key', 'verifier_ref', 'queued_at', 'removed_at', 'removal_reason',
            'source_dispense_key', 'dispense_channel', 'dispensed_at', 'fhir_backfill',
            'source_transaction_key', 'transaction_type', 'station_key', 'station_unit',
            'station_label', 'station_type', 'station_is_profiled', 'station_controlled_capable',
            'quantity', 'is_controlled',
            'require_existing_order', 'source_administration_key', 'source_row_version',
            'import_batch_key', 'administration_source_class', 'administration_status',
            'administration_route', 'administered_at', 'source_cutoff_at',
            'administration_local_code', 'administration_ndc_code', 'administration_rxnorm_cui',
            'administration_medication_label',
        ]);

        return new CanonicalOperationalEvent(
            eventId: (string) Str::uuid(),
            eventType: $eventType,
            entityType: 'ancillary_order',
            entityRef: $sourceOrderKey,
            payload: $safe,
            occurredAt: CanonicalOperationalEvent::occurredAt($data['occurred_at'] ?? $payload->occurredAt),
            idempotencyKey: "{$payload->idempotencyKey}:group:{$index}:{$eventType}:{$milestoneCode}:{$sourceOrderKey}",
            correlationId: $payload->externalId,
            sequenceKey: $sourceOrderKey,
            metadata: [
                ...$payload->metadata,
                'source_message_type' => $payload->messageType,
            ],
        );
    }

    /** @param array<string, mixed> $payload */
    private function required(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new InvalidArgumentException("Missing required normalized ancillary field [{$key}].");
        }

        return trim((string) $value);
    }
}
