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

            if (($event->payload['milestone_code'] ?? null) === 'LAB_CANCELLED' && $specimen->collected_at === null) {
                $updates['status'] = 'cancelled';
                $updates['cancelled_at'] = $event->occurredAt;
            }

            $updates['metadata'] = $metadata;
            $specimen->fill($updates);
            $specimen->save();
            $projected[] = $specimen->refresh();
        }

        return $projected;
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
