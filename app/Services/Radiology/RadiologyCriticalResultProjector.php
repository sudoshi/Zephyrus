<?php

namespace App\Services\Radiology;

use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Radiology\CriticalResult;
use App\Models\Radiology\Exam;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RadiologyCriticalResultProjector
{
    public function project(AncillaryOrder $order, CanonicalOperationalEvent $event, int $sourceId): CriticalResult
    {
        $sourceKey = trim((string) ($event->payload['source_result_key'] ?? ''));
        $status = (string) ($event->payload['critical_status'] ?? '');
        if ($sourceKey === '' || ! in_array($status, ['notified', 'acknowledged'], true)) {
            throw new InvalidArgumentException('Critical-result projection requires source identity and governed status.');
        }

        $exam = Exam::query()->where('ancillary_order_id', $order->ancillary_order_id)->firstOrFail();
        $critical = CriticalResult::query()->firstOrNew(['source_id' => $sourceId, 'source_result_key' => $sourceKey]);
        if ($critical->exists && (int) $critical->rad_exam_id !== (int) $exam->rad_exam_id) {
            throw new InvalidArgumentException('Critical-result source identity is already linked to another exam.');
        }

        if (! $critical->exists) {
            $critical->fill([
                'critical_result_uuid' => (string) Str::uuid(), 'rad_exam_id' => $exam->rad_exam_id,
                'rad_read_id' => $exam->reads()->latest('rad_read_id')->value('rad_read_id'),
                'finding_class' => $event->payload['finding_class'] ?? 'critical',
                'identified_at' => $this->timestamp($event->payload['identified_at'] ?? null) ?? $event->occurredAt,
                'policy_state' => 'pending_notification', 'metadata' => ['operational_only' => true],
                'demo_owner' => $order->demo_owner,
            ]);
        }

        if ($status === 'notified') {
            $critical->fill(['policy_state' => 'notified', 'notified_at' => $this->timestamp($event->payload['notified_at'] ?? null) ?? $event->occurredAt, 'recipient_role' => $event->payload['recipient_role'] ?? null]);
        } else {
            if ($critical->notified_at === null) {
                throw new InvalidArgumentException('Critical-result acknowledgment requires a prior notification.');
            }
            $critical->fill(['policy_state' => 'acknowledged', 'acknowledged_at' => $this->timestamp($event->payload['acknowledged_at'] ?? null) ?? $event->occurredAt]);
        }
        $critical->save();

        return $critical->refresh();
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
    }
}
