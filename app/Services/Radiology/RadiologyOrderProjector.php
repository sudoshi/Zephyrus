<?php

namespace App\Services\Radiology;

use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Radiology\Exam;
use App\Models\Radiology\Scanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RadiologyOrderProjector
{
    public function project(AncillaryOrder $order, CanonicalOperationalEvent $event, int $sourceId): Exam
    {
        if ($order->department !== 'rad') {
            throw new InvalidArgumentException('Radiology exam projection requires a Radiology ancillary order.');
        }

        $sourceExamKey = trim((string) ($event->payload['source_exam_key'] ?? $event->payload['source_order_key'] ?? ''));
        if ($sourceExamKey === '') {
            throw new InvalidArgumentException('Radiology exam projection requires a source exam identity.');
        }

        $exam = Exam::query()->where('ancillary_order_id', $order->ancillary_order_id)->first();
        if ($exam === null) {
            $exam = Exam::query()->where('source_id', $sourceId)->where('source_exam_key', $sourceExamKey)->first();
        }
        if ($exam !== null && (int) $exam->ancillary_order_id !== (int) $order->ancillary_order_id) {
            throw new InvalidArgumentException('Radiology source exam identity is already linked to another ancillary order.');
        }

        $exam ??= new Exam([
            'exam_uuid' => (string) Str::uuid(),
            'ancillary_order_id' => $order->ancillary_order_id,
            'source_id' => $sourceId,
            'source_exam_key' => $sourceExamKey,
            'encounter_id' => $order->encounter_id,
            'status' => 'ordered',
            'preparation' => [],
            'metadata' => [],
            'demo_owner' => $order->demo_owner,
        ]);

        $updates = [];
        foreach ([
            'modality' => 'modality_code', 'body_region' => 'body_region', 'subspecialty_code' => 'subspecialty_code',
            'procedure_code' => 'procedure_code', 'procedure_label' => 'procedure_label', 'protocol' => 'protocol',
            'contrast_status' => 'contrast_status', 'is_portable' => 'is_portable', 'is_ir' => 'is_ir',
            'scheduled_start_at' => 'scheduled_start_at', 'scheduled_end_at' => 'scheduled_end_at',
            'started_at' => 'started_at', 'completed_at' => 'completed_at', 'cancelled_at' => 'cancelled_at',
        ] as $payloadKey => $column) {
            if (array_key_exists($payloadKey, $event->payload) && $event->payload[$payloadKey] !== '') {
                $updates[$column] = str_ends_with($column, '_at') && is_string($event->payload[$payloadKey])
                    ? CarbonImmutable::parse($event->payload[$payloadKey])->utc()
                    : $event->payload[$payloadKey];
            }
        }

        $scannerKey = trim((string) ($event->payload['scanner_key'] ?? ''));
        $modality = $updates['modality_code'] ?? $exam->modality_code;
        if ($scannerKey !== '' && is_string($modality) && $modality !== '') {
            $scanner = Scanner::query()->firstOrCreate(
                ['source_id' => $sourceId, 'source_scanner_key' => $scannerKey],
                ['scanner_uuid' => (string) Str::uuid(), 'modality_code' => $modality, 'label' => $scannerKey, 'capacity' => 1, 'status' => 'operational', 'demo_owner' => $order->demo_owner, 'metadata' => []],
            );
            $updates['rad_scanner_id'] = $scanner->rad_scanner_id;
        }

        $candidateStatus = (string) ($event->payload['exam_status'] ?? 'ordered');
        if ($this->statusRank($candidateStatus) >= $this->statusRank((string) $exam->status)) {
            $updates['status'] = $candidateStatus;
        }
        if (in_array($candidateStatus, ['cancelled', 'discontinued'], true)) {
            $updates['cancelled_at'] = $event->occurredAt;
        }

        $metadata = is_array($exam->metadata) ? $exam->metadata : [];
        $degradedFields = $event->payload['degraded_fields'] ?? [];
        if (is_array($degradedFields) && $degradedFields !== []) {
            $metadata['degraded_fields'] = array_values(array_unique(array_filter($degradedFields, 'is_string')));
        }
        if (($event->payload['correction'] ?? null) === 'order_modified') {
            $metadata['last_order_modification_at'] = $event->occurredAt->format(DATE_ATOM);
        }
        foreach (['source_sop_instance_uid_hash', 'relay_signature', 'transport_request_ref', 'demo_context', 'demo_shift', 'ed_visit_id', 'or_case_id'] as $key) {
            if (array_key_exists($key, $event->payload)) {
                $metadata[$key] = $event->payload[$key];
            }
        }
        $updates['metadata'] = $metadata;

        $exam->fill($updates);
        $exam->save();

        return $exam->refresh();
    }

    private function statusRank(string $status): int
    {
        return match ($status) {
            'ordered' => 1,
            'scheduled' => 2,
            'in_progress' => 3,
            'complete' => 4,
            'cancelled', 'discontinued' => 5,
            default => 0,
        };
    }
}
