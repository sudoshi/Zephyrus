<?php

namespace App\Services\Radiology;

use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Radiology\Exam;
use App\Models\Radiology\Read;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RadiologyReadProjector
{
    public function project(AncillaryOrder $order, CanonicalOperationalEvent $event, int $sourceId): Read
    {
        $status = trim((string) ($event->payload['read_status'] ?? ''));
        $sourceReadKey = trim((string) ($event->payload['source_read_key'] ?? ''));
        if (! in_array($status, ['preliminary', 'final', 'corrected', 'addendum'], true) || $sourceReadKey === '') {
            throw new InvalidArgumentException('Radiology read projection requires a governed status and source read identity.');
        }

        $exam = Exam::query()->where('ancillary_order_id', $order->ancillary_order_id)->firstOrFail();
        $existing = Read::query()->where('source_id', $sourceId)->where('source_read_key', $sourceReadKey)->first();
        if ($existing !== null) {
            if ((int) $existing->rad_exam_id !== (int) $exam->rad_exam_id) {
                throw new InvalidArgumentException('Radiology source read identity is already linked to another exam.');
            }

            return $existing;
        }

        $parentId = null;
        if (in_array($status, ['corrected', 'addendum'], true)) {
            $parentId = Read::query()
                ->where('rad_exam_id', $exam->rad_exam_id)
                ->whereIn('status', ['final', 'corrected', 'addendum'])
                ->latest('rad_read_id')
                ->value('rad_read_id');
        }

        return Read::query()->create([
            'read_uuid' => (string) Str::uuid(),
            'rad_exam_id' => $exam->rad_exam_id,
            'source_id' => $sourceId,
            'source_read_key' => $sourceReadKey,
            'source_report_version' => $event->payload['source_report_version'] ?? null,
            'status' => $status,
            'radiologist_ref' => $event->payload['radiologist_ref'] ?? null,
            'subspecialty_code' => $event->payload['subspecialty_code'] ?? null,
            'is_teleradiology' => (bool) ($event->payload['is_teleradiology'] ?? false),
            'preliminary_at' => $this->timestamp($event->payload['preliminary_at'] ?? null),
            'final_at' => $this->timestamp($event->payload['final_at'] ?? null),
            'corrected_at' => $this->timestamp($event->payload['corrected_at'] ?? null),
            'parent_rad_read_id' => $parentId,
            'demo_owner' => $order->demo_owner,
            'metadata' => [
                'operational_only' => true,
                'source_message_type' => $event->metadata['source_message_type'] ?? null,
            ],
        ]);
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
    }
}
