<?php

namespace App\Services\Lab;

use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Lab\Specimen;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class LabOrderProjector
{
    /** @return list<Specimen> */
    public function project(AncillaryOrder $order, CanonicalOperationalEvent $event, int $sourceId): array
    {
        if ($order->department !== 'lab') {
            throw new InvalidArgumentException('Laboratory specimen projection requires a Laboratory ancillary order.');
        }

        $payloads = $this->specimenPayloads($event->payload);
        if ($payloads === [] && ($event->payload['milestone_code'] ?? null) === 'LAB_CANCELLED') {
            return $this->cancelPendingSpecimens($order, $event->occurredAt);
        }

        $projected = [];
        foreach ($payloads as $payload) {
            $specimenKey = trim((string) ($payload['source_specimen_key'] ?? ''));
            if ($specimenKey === '') {
                throw new InvalidArgumentException('Laboratory specimen projection requires a source specimen identity.');
            }

            $parent = null;
            $parentKey = trim((string) ($payload['parent_source_specimen_key'] ?? ''));
            if ($parentKey !== '') {
                $parent = Specimen::query()
                    ->where('ancillary_order_id', $order->ancillary_order_id)
                    ->where('source_specimen_key', $parentKey)
                    ->orderByRaw('CASE WHEN source_id = ? THEN 0 ELSE 1 END', [$sourceId])
                    ->first();
                if ($parent === null) {
                    throw new InvalidArgumentException('Laboratory recollect projection requires its parent specimen.');
                }
            }

            $specimen = Specimen::query()
                ->where('source_id', $sourceId)
                ->where('source_specimen_key', $specimenKey)
                ->first();
            if ($specimen !== null && (int) $specimen->ancillary_order_id !== (int) $order->ancillary_order_id) {
                throw new InvalidArgumentException('Laboratory source specimen identity is already linked to another ancillary order.');
            }

            $specimen ??= new Specimen([
                'specimen_uuid' => (string) Str::uuid(),
                'ancillary_order_id' => $order->ancillary_order_id,
                'source_id' => $sourceId,
                'source_specimen_key' => $specimenKey,
                'parent_specimen_id' => $parent?->lab_specimen_id,
                'encounter_id' => $order->encounter_id,
                'specimen_type' => 'unknown',
                'status' => 'collection_pending',
                'demo_owner' => $order->demo_owner,
                'metadata' => [],
            ]);

            $updates = $this->descriptiveUpdates($specimen, $payload);
            $metadata = is_array($specimen->metadata) ? $specimen->metadata : [];
            foreach ([
                'source_specimen_business_key', 'collector_ref', 'collection_source',
                'placer_order_key', 'filler_order_key', 'test_code', 'loinc_code',
            ] as $key) {
                if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
                    $metadata[$key] = $payload[$key];
                } elseif (array_key_exists($key, $event->payload) && $event->payload[$key] !== null && $event->payload[$key] !== '') {
                    $metadata[$key] = $event->payload[$key];
                }
            }

            $collectedAt = $this->timestamp($payload['collected_at'] ?? null);
            if ($collectedAt !== null) {
                if ($specimen->collected_at === null) {
                    $updates['collected_at'] = $collectedAt;
                } elseif (! $specimen->collected_at->equalTo($collectedAt)) {
                    $metadata['collection_time_conflict'] = [
                        'retained' => $specimen->collected_at->toIso8601String(),
                        'candidate' => $collectedAt->toIso8601String(),
                    ];
                }
                if (in_array((string) $specimen->status, ['', 'collection_pending'], true)) {
                    $updates['status'] = 'collected';
                }
            }

            $milestone = (string) ($event->payload['milestone_code'] ?? '');
            if ($milestone === 'LAB_RECEIVED') {
                $receivedAt = $this->timestamp($payload['received_at'] ?? null) ?? $event->occurredAt;
                $effectiveCollection = $specimen->collected_at ?? ($updates['collected_at'] ?? null);
                if ($effectiveCollection !== null) {
                    if ($receivedAt->lessThan($effectiveCollection)) {
                        throw new InvalidArgumentException('Laboratory specimen receipt precedes collection.');
                    }
                    if ($specimen->received_at === null) {
                        $updates['received_at'] = $receivedAt;
                    }
                    if (in_array((string) $specimen->status, ['', 'collection_pending', 'collected', 'in_transit'], true)) {
                        $updates['status'] = 'received';
                    }
                } else {
                    $metadata['receipt_projection'] = 'withheld_missing_collection';
                    $metadata['asserted_received_at'] = $receivedAt->toIso8601String();
                }
            }

            if ($milestone === 'LAB_IN_TRANSIT') {
                $inTransitAt = $this->timestamp($payload['in_transit_at'] ?? null) ?? $event->occurredAt;
                $effectiveCollection = $specimen->collected_at ?? ($updates['collected_at'] ?? null);
                if ($effectiveCollection === null) {
                    throw new InvalidArgumentException('Laboratory specimen transit requires prior collection evidence.');
                }
                if ($inTransitAt->lessThan($effectiveCollection)) {
                    throw new InvalidArgumentException('Laboratory specimen transit precedes collection.');
                }
                if ($specimen->in_transit_at === null) {
                    $updates['in_transit_at'] = $inTransitAt;
                }
                if (in_array((string) $specimen->status, ['', 'collection_pending', 'collected'], true)) {
                    $updates['status'] = 'in_transit';
                }
            }

            if ($milestone === 'LAB_REJECTED') {
                $reason = trim((string) ($payload['rejection_reason_code'] ?? ''));
                if ($reason === '') {
                    throw new InvalidArgumentException('Laboratory specimen rejection requires its reason code.');
                }
                $rejectedAt = $this->timestamp($payload['rejected_at'] ?? null) ?? $event->occurredAt;
                $updates['status'] = 'rejected';
                $updates['rejection_reason_code'] = $reason;
                $updates['rejected_at'] = $rejectedAt;
            }

            if ($milestone === 'LAB_RECOLLECT_ORDERED') {
                if ($parent === null || $parent->rejected_at === null || $parent->rejection_reason_code === null) {
                    throw new InvalidArgumentException('Laboratory recollect projection requires a previously rejected parent specimen.');
                }
                $recollectAt = $this->timestamp($payload['recollect_ordered_at'] ?? null) ?? $event->occurredAt;
                if ($recollectAt->lessThan($parent->rejected_at)) {
                    throw new InvalidArgumentException('Laboratory recollect request precedes specimen rejection.');
                }
                $parent->update(['status' => 'recollect_requested', 'recollect_ordered_at' => $recollectAt]);
                $metadata['collection_reason'] = 'recollect';
                $metadata['parent_rejection_reason_code'] = $parent->rejection_reason_code;
            }

            if ($milestone === 'LAB_CANCELLED' && $specimen->collected_at === null) {
                $updates['status'] = 'cancelled';
                $updates['cancelled_at'] = $event->occurredAt;
            }

            $updates['metadata'] = $metadata;
            $specimen->fill($updates);
            $specimen->save();
            $projected[] = $specimen->refresh();
            if ($parent !== null && $milestone === 'LAB_RECOLLECT_ORDERED') {
                $projected[] = $parent->refresh();
            }
        }

        return collect($projected)->unique('lab_specimen_id')->values()->all();
    }

    /** @param array<string, mixed> $payload @return list<array<string, mixed>> */
    private function specimenPayloads(array $payload): array
    {
        $specimens = $payload['specimens'] ?? null;
        if (is_array($specimens)) {
            return array_values(array_filter($specimens, 'is_array'));
        }
        if (isset($payload['source_specimen_key'])) {
            return [$payload];
        }

        return [];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function descriptiveUpdates(Specimen $specimen, array $payload): array
    {
        $updates = [];
        foreach ([
            'source_accession_key', 'specimen_type', 'container_type',
            'collector_role', 'collection_method',
        ] as $column) {
            if (($specimen->{$column} === null || $specimen->{$column} === '' || $specimen->{$column} === 'unknown')
                && array_key_exists($column, $payload)
                && $payload[$column] !== null
                && $payload[$column] !== '') {
                $updates[$column] = $payload[$column];
            }
        }

        return $updates;
    }

    /** @return list<Specimen> */
    private function cancelPendingSpecimens(AncillaryOrder $order, CarbonImmutable $cancelledAt): array
    {
        $specimens = Specimen::query()
            ->where('ancillary_order_id', $order->ancillary_order_id)
            ->where('status', 'collection_pending')
            ->whereNull('collected_at')
            ->get();
        foreach ($specimens as $specimen) {
            $specimen->update(['status' => 'cancelled', 'cancelled_at' => $cancelledAt]);
        }

        return $specimens->map->refresh()->all();
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException('Laboratory collection timestamp must be a string.');
        }

        return CarbonImmutable::parse($value)->utc();
    }
}
