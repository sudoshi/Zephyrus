<?php

namespace App\Services\Lab;

use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Lab\CriticalValue;
use App\Models\Lab\LabTestCatalog;
use App\Models\Lab\Result;
use App\Models\Lab\Specimen;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class LabResultProjector
{
    /** @return array{result:Result,critical:?CriticalValue} */
    public function project(AncillaryOrder $order, CanonicalOperationalEvent $event, int $sourceId): array
    {
        if ($order->department !== 'lab') {
            throw new InvalidArgumentException('Laboratory result projection requires a Laboratory ancillary order.');
        }

        $sourceResultKey = $this->required($event->payload, 'source_result_key');
        $version = $this->required($event->payload, 'source_result_version');
        $status = $this->required($event->payload, 'result_status');
        if (! in_array($status, ['preliminary', 'final', 'corrected', 'cancelled'], true)) {
            throw new InvalidArgumentException('Laboratory result projection received an unsupported result status.');
        }

        $existing = Result::query()
            ->where('source_id', $sourceId)
            ->where('source_result_key', $sourceResultKey)
            ->where('source_result_version', $version)
            ->first();
        if ($existing !== null) {
            if ((int) $existing->ancillary_order_id !== (int) $order->ancillary_order_id) {
                throw new InvalidArgumentException('Laboratory source result identity is already linked to another ancillary order.');
            }
            $result = $this->enrichExisting($existing, $event);

            return ['result' => $result, 'critical' => $this->criticalValue($result, $event, $sourceId)];
        }

        $catalog = $this->catalog($event);
        $specimen = $this->specimen($order, $event, $sourceId);
        $parent = null;
        if ($status === 'corrected') {
            $parent = Result::query()
                ->where('ancillary_order_id', $order->ancillary_order_id)
                ->where('source_id', $sourceId)
                ->where('source_result_key', $sourceResultKey)
                ->latest('lab_result_id')
                ->first();
            if ($parent === null) {
                throw new InvalidArgumentException('A corrected Laboratory result requires a prior source result version.');
            }
        }

        $resultedAt = $this->timestamp($event->payload['resulted_at'] ?? $event->occurredAt->toIso8601String()) ?? $event->occurredAt;
        $verifiedAt = $this->timestamp($event->payload['verified_at'] ?? null);
        $correctedAt = $status === 'corrected'
            ? ($this->timestamp($event->payload['corrected_at'] ?? null) ?? $event->occurredAt)
            : null;
        $cancelledAt = $status === 'cancelled'
            ? ($this->timestamp($event->payload['cancelled_at'] ?? null) ?? $event->occurredAt)
            : null;
        $metadata = array_filter([
            'operational_only' => true,
            'test_label' => $event->payload['test_label'] ?? null,
            'result_time_source' => $event->payload['result_time_source'] ?? null,
            'obx_set_id' => $event->payload['obx_set_id'] ?? null,
            'middleware_ref' => $event->payload['middleware_ref'] ?? null,
            'source_message_type' => $event->metadata['source_message_type'] ?? null,
            'decision_context' => $event->payload['decision_context'] ?? null,
            'analyzer_operational_state' => $event->payload['analyzer_operational_state'] ?? null,
            'analyzer_downtime_started_at' => $event->payload['analyzer_downtime_started_at'] ?? null,
            'analyzer_expected_restore_at' => $event->payload['analyzer_expected_restore_at'] ?? null,
            'operational_window' => $event->payload['operational_window'] ?? null,
            'value_storage' => 'excluded',
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $result = Result::query()->create([
            'result_uuid' => (string) Str::uuid(),
            'ancillary_order_id' => $order->ancillary_order_id,
            'lab_specimen_id' => $specimen?->lab_specimen_id,
            'lab_test_catalog_id' => $catalog->lab_test_catalog_id,
            'source_id' => $sourceId,
            'source_result_key' => $sourceResultKey,
            'source_result_version' => $version,
            'parent_lab_result_id' => $parent?->lab_result_id,
            'local_code' => (string) ($event->payload['test_code'] ?? $catalog->local_code),
            'loinc_code' => $event->payload['loinc_code'] ?? $catalog->loinc_code,
            'result_status' => $status,
            'result_stage' => $event->payload['result_stage'] ?? ($status === 'corrected' ? 'corrected' : ($status === 'cancelled' ? 'cancelled' : ($status === 'final' ? 'final' : 'preliminary'))),
            'abnormal_flag' => $event->payload['abnormal_flag'] ?? 'unknown',
            'auto_verified' => (bool) ($event->payload['auto_verified'] ?? false),
            'is_critical' => (bool) ($event->payload['is_critical'] ?? false),
            'analyzer_ref' => $event->payload['analyzer_ref'] ?? null,
            'observed_at' => $this->timestamp($event->payload['observed_at'] ?? null),
            'resulted_at' => $status === 'cancelled' ? null : $resultedAt,
            'verified_at' => $verifiedAt,
            'corrected_at' => $correctedAt,
            'cancelled_at' => $cancelledAt,
            'demo_owner' => $order->demo_owner,
            'metadata' => $metadata,
        ]);

        return ['result' => $result->refresh(), 'critical' => $this->criticalValue($result, $event, $sourceId)];
    }

    private function enrichExisting(Result $result, CanonicalOperationalEvent $event): Result
    {
        $updates = [];
        $verifiedAt = $this->timestamp($event->payload['verified_at'] ?? null);
        if ($result->verified_at === null && $verifiedAt !== null) {
            $updates['verified_at'] = $verifiedAt;
        }
        if (! $result->auto_verified && ($event->payload['auto_verified'] ?? false) === true) {
            $updates['auto_verified'] = true;
        }
        if (! $result->is_critical && ($event->payload['is_critical'] ?? false) === true) {
            $updates['is_critical'] = true;
            $updates['abnormal_flag'] = 'critical';
        }
        if ($result->analyzer_ref === null && filled($event->payload['analyzer_ref'] ?? null)) {
            $updates['analyzer_ref'] = $event->payload['analyzer_ref'];
        }
        if ($updates !== []) {
            $result->update($updates);
        }

        return $result->refresh();
    }

    private function catalog(CanonicalOperationalEvent $event): LabTestCatalog
    {
        $localCode = trim((string) ($event->payload['test_code'] ?? ''));
        $loincCode = trim((string) ($event->payload['loinc_code'] ?? ''));
        if ($localCode === '' && $loincCode === '') {
            throw new InvalidArgumentException('Laboratory result projection requires a local or LOINC test code.');
        }

        $query = LabTestCatalog::query()
            ->where('is_active', true)
            ->whereNotIn('department', ['pathology', 'blood_bank'])
            ->where(function (Builder $identity) use ($localCode, $loincCode): void {
                if ($localCode !== '') {
                    $identity->whereRaw('upper(local_code) = upper(?)', [$localCode]);
                }
                if ($loincCode !== '') {
                    $method = $localCode !== '' ? 'orWhere' : 'where';
                    $identity->{$method}('loinc_code', $loincCode);
                }
            });
        $catalog = $query
            ->orderByRaw('CASE WHEN upper(local_code) = upper(?) THEN 0 ELSE 1 END', [$localCode])
            ->latest('effective_from')
            ->first();
        if ($catalog === null) {
            throw new InvalidArgumentException('Laboratory result test identity is absent from the governed catalog.');
        }

        return $catalog;
    }

    private function specimen(AncillaryOrder $order, CanonicalOperationalEvent $event, int $sourceId): ?Specimen
    {
        $specimenKey = trim((string) ($event->payload['source_specimen_key'] ?? ''));
        if ($specimenKey === '') {
            return null;
        }

        $matches = Specimen::query()
            ->where('ancillary_order_id', $order->ancillary_order_id)
            ->where('source_specimen_key', $specimenKey)
            ->orderByRaw('CASE WHEN source_id = ? THEN 0 ELSE 1 END', [$sourceId])
            ->limit(2)
            ->get();
        if ($matches->isEmpty()) {
            throw new InvalidArgumentException('Laboratory result references a specimen that has not been projected.');
        }
        if ($matches->count() > 1 && (int) $matches[0]->source_id !== $sourceId) {
            throw new InvalidArgumentException('Laboratory result specimen identity is ambiguous across governed sources.');
        }

        return $matches->first();
    }

    private function criticalValue(Result $result, CanonicalOperationalEvent $event, int $sourceId): ?CriticalValue
    {
        if (! $result->is_critical) {
            return null;
        }

        $sourceCriticalKey = implode(':', [$result->source_result_key, $result->source_result_version ?? '']);

        return CriticalValue::query()->firstOrCreate(
            ['source_id' => $sourceId, 'source_critical_key' => $sourceCriticalKey],
            [
                'critical_value_uuid' => (string) Str::uuid(),
                'lab_result_id' => $result->lab_result_id,
                'severity' => 'critical',
                'callback_state' => 'pending_notification',
                'identified_at' => $this->timestamp($event->payload['critical_identified_at'] ?? null) ?? $result->resulted_at ?? $event->occurredAt,
                'demo_owner' => $result->demo_owner,
                'metadata' => ['flag_source' => 'result_abnormal_flag', 'notification_asserted' => false],
            ],
        );
    }

    /** @param array<string, mixed> $payload */
    private function required(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new InvalidArgumentException("Laboratory result projection requires {$key}.");
        }

        return trim((string) $value);
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof CarbonImmutable) {
            return $value->utc();
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException('Laboratory result timestamp must be an ISO string.');
        }

        return CarbonImmutable::parse($value)->utc();
    }
}
