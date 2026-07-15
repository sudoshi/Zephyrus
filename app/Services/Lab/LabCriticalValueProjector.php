<?php

namespace App\Services\Lab;

use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Lab\CriticalValue;
use App\Models\Lab\Result;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class LabCriticalValueProjector
{
    public function project(AncillaryOrder $order, CanonicalOperationalEvent $event): CriticalValue
    {
        if ($order->department !== 'lab') {
            throw new InvalidArgumentException('Laboratory critical callback projection requires a Laboratory ancillary order.');
        }
        $sourceResultKey = trim((string) ($event->payload['source_result_key'] ?? ''));
        if ($sourceResultKey === '') {
            throw new InvalidArgumentException('Laboratory critical callback requires its source result identity.');
        }

        $results = Result::query()
            ->where('ancillary_order_id', $order->ancillary_order_id)
            ->where('source_result_key', $sourceResultKey);
        $version = trim((string) ($event->payload['source_result_version'] ?? ''));
        if ($version !== '') {
            $results->where('source_result_version', $version);
        }
        $result = $results->latest('lab_result_id')->first();
        if ($result === null || ! $result->is_critical) {
            throw new InvalidArgumentException('Laboratory critical callback requires an existing critical result fact.');
        }

        $critical = CriticalValue::query()->where('lab_result_id', $result->lab_result_id)->first();
        if ($critical === null) {
            throw new InvalidArgumentException('Laboratory critical callback requires an open critical-value fact.');
        }

        $milestone = (string) ($event->payload['milestone_code'] ?? '');
        $metadata = is_array($critical->metadata) ? $critical->metadata : [];
        $updates = [];
        if ($milestone === 'LAB_CRITICAL_NOTIFIED') {
            $notifiedAt = $this->timestamp($event->payload['notified_at'] ?? null) ?? $event->occurredAt;
            if ($notifiedAt->lessThan($critical->identified_at)) {
                throw new InvalidArgumentException('Laboratory critical notification precedes critical identification.');
            }
            if ($critical->notified_at === null) {
                $updates['notified_at'] = $notifiedAt;
            }
            if ($critical->callback_state === 'pending_notification') {
                $updates['callback_state'] = 'notified';
            }
            $metadata['notification_asserted'] = true;
        } elseif ($milestone === 'LAB_CRITICAL_ACKED') {
            $notifiedAt = $critical->notified_at ?? $this->timestamp($event->payload['notified_at'] ?? null);
            if ($notifiedAt === null) {
                throw new InvalidArgumentException('Laboratory critical acknowledgement requires prior notification evidence.');
            }
            $acknowledgedAt = $this->timestamp($event->payload['acknowledged_at'] ?? null) ?? $event->occurredAt;
            if ($acknowledgedAt->lessThan($notifiedAt)) {
                throw new InvalidArgumentException('Laboratory critical acknowledgement precedes notification.');
            }
            $updates['notified_at'] = $notifiedAt;
            $updates['acknowledged_at'] = $critical->acknowledged_at ?? $acknowledgedAt;
            $updates['callback_state'] = 'acknowledged';
            $metadata['notification_asserted'] = true;
            $metadata['acknowledgement_asserted'] = true;
        } else {
            throw new InvalidArgumentException('Laboratory critical callback milestone is unsupported.');
        }

        if ($critical->recipient_role === null && filled($event->payload['recipient_role'] ?? null)) {
            $updates['recipient_role'] = $event->payload['recipient_role'];
        }
        $updates['metadata'] = $metadata;
        $critical->update($updates);

        return $critical->refresh();
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException('Laboratory critical callback timestamp must be an ISO string.');
        }

        return CarbonImmutable::parse($value)->utc();
    }
}
