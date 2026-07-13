<?php

namespace App\Services\Pharmacy;

use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Pharmacy\Dispense;
use App\Models\Pharmacy\FormularyItem;
use App\Models\Pharmacy\MedicationOrder;
use App\Models\Pharmacy\Verification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Idempotently projects pharmacy lifecycle events onto prod.rx_orders,
 * prod.rx_verifications, and prod.rx_dispenses. Order status only advances
 * along the governed rank; late or out-of-order events append satellite facts
 * without regressing terminal state, and modifications append to an
 * order-change log instead of rewriting history.
 */
final class PharmacyOrderProjector
{
    public const MILESTONES = ['RX_ORDERED', 'RX_QUEUE_IN', 'RX_VERIFIED', 'RX_DISPENSED', 'RX_RETURNED', 'RX_WASTED', 'RX_DISCONTINUED'];

    private const UNSPECIFIED_LOCAL_CODE = 'UNSPECIFIED_LOCAL';

    private const UNSPECIFIED_LABEL = 'Unspecified medication';

    private const STATUS_RANK = [
        'ordered' => 10, 'queued' => 20, 'verified' => 30, 'preparing' => 40,
        'ready' => 50, 'dispensed' => 60, 'delivered' => 70, 'administered' => 80,
        'held' => 85, 'completed' => 90, 'cancelled' => 91, 'discontinued' => 92,
    ];

    private const VERIFICATION_STATE_RANK = ['queued' => 10, 'verified' => 20, 'removed' => 20, 'rejected' => 20];

    /** @return array{order: MedicationOrder, verifications: list<Verification>, dispenses: list<Dispense>} */
    public function project(AncillaryOrder $order, CanonicalOperationalEvent $event, int $sourceId, ?int $adcStationId = null): array
    {
        if ($order->department !== 'rx') {
            throw new InvalidArgumentException('Pharmacy projection requires a pharmacy ancillary order.');
        }
        $milestone = (string) ($event->payload['milestone_code'] ?? '');
        if (! in_array($milestone, self::MILESTONES, true)) {
            throw new InvalidArgumentException('Pharmacy projection does not support this milestone.');
        }

        $medication = $this->resolveMedicationOrder($order, $event, $sourceId);
        $existedBefore = $medication->exists;
        $before = $this->changeSnapshot($medication);
        $this->applyTerminology($medication, $event->payload);
        $this->applyOrderAssertion($medication, $event, $milestone, $existedBefore ? $before : null);

        $verifications = [];
        if (isset($event->payload['queue_state'])) {
            $verifications[] = $this->upsertVerification($order, $medication, $event, $sourceId);
        }
        $dispenses = [];
        if ($milestone === 'RX_DISPENSED' && isset($event->payload['source_dispense_key'])) {
            $dispenses[] = $this->upsertDispense($order, $medication, $event, $sourceId, $adcStationId);
        }

        $this->advanceStatus($medication, $event, $milestone);
        $medication->save();

        return [
            'order' => $medication->refresh(),
            'verifications' => $verifications,
            'dispenses' => $dispenses,
        ];
    }

    private function resolveMedicationOrder(AncillaryOrder $order, CanonicalOperationalEvent $event, int $sourceId): MedicationOrder
    {
        $medication = MedicationOrder::query()
            ->where('ancillary_order_id', $order->ancillary_order_id)
            ->first();
        if ($medication !== null) {
            if ($medication->encounter_id === null && $order->encounter_id !== null) {
                $medication->encounter_id = $order->encounter_id;
            }

            return $medication;
        }

        $sourceOrderKey = trim((string) ($event->payload['source_order_key'] ?? ''));
        if ($sourceOrderKey === '') {
            throw new InvalidArgumentException('Pharmacy projection requires a source order identity.');
        }
        $conflict = MedicationOrder::query()
            ->where('source_id', $sourceId)
            ->where('source_order_key', $sourceOrderKey)
            ->first();
        if ($conflict !== null) {
            throw new InvalidArgumentException('Pharmacy source order identity is already linked to another ancillary order.');
        }

        return new MedicationOrder([
            'rx_order_uuid' => (string) Str::uuid(),
            'ancillary_order_id' => $order->ancillary_order_id,
            'source_id' => $sourceId,
            'source_order_key' => $sourceOrderKey,
            'encounter_id' => $order->encounter_id,
            'local_code' => self::UNSPECIFIED_LOCAL_CODE,
            'terminology_status' => 'unmapped_local',
            'medication_label' => self::UNSPECIFIED_LABEL,
            'clock_class' => 'routine',
            'preparation_branch' => 'unknown',
            'order_status' => 'ordered',
            'demo_owner' => $order->demo_owner,
            'metadata' => [],
        ]);
    }

    /**
     * Resolves local/RxNorm/NDC mapping candidates against the governed
     * formulary. A missing mapping produces the explicit `unmapped_local`
     * state — never a failed message.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyTerminology(MedicationOrder $medication, array $payload): void
    {
        $localCode = trim((string) ($payload['local_code'] ?? ''));
        if ($localCode === '') {
            return;
        }
        if ($medication->local_code === $localCode && $medication->terminology_status === 'mapped') {
            return;
        }

        $anchor = isset($payload['ordered_at'])
            ? CarbonImmutable::parse((string) $payload['ordered_at'])->utc()
            : CarbonImmutable::now('UTC');
        $rxnorm = trim((string) ($payload['rxnorm_cui'] ?? '')) ?: null;
        $ndc = trim((string) ($payload['ndc_code'] ?? '')) ?: null;
        $label = trim((string) ($payload['medication_label'] ?? '')) ?: null;
        $formulary = FormularyItem::query()
            ->activeAt($anchor)
            ->where('local_code', $localCode)
            ->orderByDesc('effective_from')
            ->first();
        $rxnorm ??= $formulary?->rxnorm_cui;
        $ndc ??= $formulary?->ndc_code;
        $mapped = $rxnorm !== null || $ndc !== null;

        $medication->fill([
            'rx_formulary_id' => $formulary?->rx_formulary_id,
            'local_code' => $localCode,
            'rxnorm_cui' => $mapped ? $rxnorm : null,
            'ndc_code' => $mapped ? $ndc : null,
            'terminology_status' => $mapped ? 'mapped' : 'unmapped_local',
            'medication_label' => $label
                ?? $formulary?->label
                ?? ($medication->medication_label === self::UNSPECIFIED_LABEL ? $localCode : $medication->medication_label),
        ]);
        if ($formulary !== null) {
            $medication->is_controlled = (bool) $formulary->is_controlled;
            $medication->controlled_schedule = $formulary->controlled_schedule;
            $medication->is_hazardous = (bool) $formulary->is_hazardous;
            if (in_array((string) $medication->preparation_branch, ['', 'unknown'], true)) {
                $medication->preparation_branch = (string) $formulary->default_prep_branch;
            }
            $medication->dosage_form ??= $formulary->dosage_form;
            $medication->route ??= $formulary->default_route;
        }
    }

    /** @param array<string, mixed>|null $before */
    private function applyOrderAssertion(
        MedicationOrder $medication,
        CanonicalOperationalEvent $event,
        string $milestone,
        ?array $before,
    ): void {
        $payload = $event->payload;
        if ($milestone === 'RX_ORDERED') {
            foreach (['dosage_form', 'route'] as $column) {
                if (filled($payload[$column] ?? null)) {
                    $medication->{$column} = (string) $payload[$column];
                }
            }
            if (filled($payload['clock_class'] ?? null)) {
                $medication->clock_class = (string) $payload['clock_class'];
            }
            if (isset($payload['due_at'])) {
                $medication->due_at = CarbonImmutable::parse((string) $payload['due_at'])->utc();
            } elseif ($medication->clock_class !== 'timed') {
                $medication->due_at = null;
            }

            if (($payload['order_change'] ?? false) === true && $before !== null) {
                $this->appendChangeLog($medication, $event, $before);
            }

            return;
        }

        foreach (['dosage_form', 'route'] as $column) {
            if ($medication->{$column} === null && filled($payload[$column] ?? null)) {
                $medication->{$column} = (string) $payload[$column];
            }
        }
    }

    /** @return array<string, mixed> */
    private function changeSnapshot(MedicationOrder $medication): array
    {
        return [
            'local_code' => $medication->local_code,
            'medication_label' => $medication->medication_label,
            'dosage_form' => $medication->dosage_form,
            'route' => $medication->route,
            'clock_class' => $medication->clock_class,
            'due_at' => $medication->due_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $before */
    private function appendChangeLog(MedicationOrder $medication, CanonicalOperationalEvent $event, array $before): void
    {
        $metadata = $this->metadata($medication);
        $entries = is_array($metadata['order_change_log'] ?? null) ? $metadata['order_change_log'] : [];
        foreach ($entries as $entry) {
            if (($entry['correlation'] ?? null) === $event->correlationId) {
                return;
            }
        }

        $changes = [];
        foreach ($this->changeSnapshot($medication) as $field => $value) {
            if (($before[$field] ?? null) !== $value) {
                $changes[$field] = ['from' => $before[$field] ?? null, 'to' => $value];
            }
        }
        if ($changes === []) {
            return;
        }

        $entries[] = [
            'changed_at' => isset($event->payload['modified_at'])
                ? (string) $event->payload['modified_at']
                : $event->occurredAt->toIso8601String(),
            'correlation' => $event->correlationId,
            'changes' => $changes,
        ];
        $metadata['order_change_log'] = $entries;
        $medication->metadata = $metadata;
    }

    private function upsertVerification(
        AncillaryOrder $order,
        MedicationOrder $medication,
        CanonicalOperationalEvent $event,
        int $sourceId,
    ): Verification {
        if (! $medication->exists) {
            $medication->save();
        }

        $payload = $event->payload;
        $key = trim((string) ($payload['source_verification_key'] ?? ''));
        if ($key === '') {
            throw new InvalidArgumentException('Pharmacy verification projection requires its source verification identity.');
        }

        $verification = Verification::query()
            ->where('source_id', $sourceId)
            ->where('source_verification_key', $key)
            ->first();
        if ($verification !== null && (int) $verification->rx_order_id !== (int) $medication->rx_order_id) {
            throw new InvalidArgumentException('Pharmacy verification identity is already linked to another medication order.');
        }

        $occurredAt = CarbonImmutable::instance($event->occurredAt)->utc();
        $queuedAt = isset($payload['queued_at'])
            ? CarbonImmutable::parse((string) $payload['queued_at'])->utc()
            : null;
        $verification ??= new Verification([
            'verification_uuid' => (string) Str::uuid(),
            'rx_order_id' => $medication->rx_order_id,
            'source_id' => $sourceId,
            'source_verification_key' => $key,
            'verification_state' => 'queued',
            'queued_at' => $queuedAt ?? $occurredAt,
            'demo_owner' => $order->demo_owner,
            'metadata' => [],
        ]);

        $state = (string) ($payload['queue_state'] ?? '');
        $targetState = match (true) {
            $state === 'entered' => 'queued',
            $state === 'verified' => 'verified',
            ($payload['removal_reason'] ?? 'verified') === 'verified' => 'verified',
            default => 'removed',
        };

        if (isset($payload['verified_at']) && $verification->verified_at === null) {
            $verifiedAt = CarbonImmutable::parse((string) $payload['verified_at'])->utc();
            if ($verifiedAt->lessThan($verification->queued_at)) {
                $metadata = $this->metadata($verification);
                $metadata['queue_time_conflict'] = [
                    'retained' => $verifiedAt->toIso8601String(),
                    'asserted_queued_at' => CarbonImmutable::instance($verification->queued_at)->toIso8601String(),
                ];
                $verification->metadata = $metadata;
                $verification->queued_at = $verifiedAt;
            }
            $verification->verified_at = $verifiedAt;
        }
        if (isset($payload['removed_at']) && $verification->removed_at === null) {
            $removedAt = CarbonImmutable::parse((string) $payload['removed_at'])->utc();
            $verification->removed_at = $removedAt->greaterThan($verification->queued_at)
                ? $removedAt
                : CarbonImmutable::instance($verification->queued_at)->utc();
        }
        $currentRank = self::VERIFICATION_STATE_RANK[(string) $verification->verification_state] ?? 0;
        if ((self::VERIFICATION_STATE_RANK[$targetState] ?? 0) > $currentRank) {
            $verification->verification_state = $targetState;
        }

        $metadata = $this->metadata($verification);
        foreach (['queue_ref', 'verifier_ref', 'removal_reason'] as $field) {
            if (filled($payload[$field] ?? null) && ! array_key_exists($field, $metadata)) {
                $metadata[$field] = $payload[$field];
            }
        }
        $verification->metadata = $metadata;
        $verification->save();

        return $verification->refresh();
    }

    private function upsertDispense(
        AncillaryOrder $order,
        MedicationOrder $medication,
        CanonicalOperationalEvent $event,
        int $sourceId,
        ?int $adcStationId = null,
    ): Dispense {
        if (! $medication->exists) {
            $medication->save();
        }

        $payload = $event->payload;
        $key = trim((string) ($payload['source_dispense_key'] ?? ''));
        $dispensedAt = CarbonImmutable::parse((string) ($payload['dispensed_at'] ?? ''))->utc();
        $channel = (string) ($payload['dispense_channel'] ?? 'central');

        $dispense = Dispense::query()
            ->where('source_id', $sourceId)
            ->where('source_dispense_key', $key)
            ->first();
        if ($dispense !== null && (int) $dispense->rx_order_id !== (int) $medication->rx_order_id) {
            throw new InvalidArgumentException('Pharmacy dispense identity is already linked to another medication order.');
        }
        if ($dispense !== null) {
            if (! $dispense->dispensed_at->equalTo($dispensedAt)) {
                $metadata = $this->metadata($dispense);
                $metadata['dispense_time_conflict'] = [
                    'retained' => $dispense->dispensed_at->toIso8601String(),
                    'candidate' => $dispensedAt->toIso8601String(),
                ];
                $dispense->metadata = $metadata;
                $dispense->save();
            }

            return $dispense->refresh();
        }

        $dispense = new Dispense([
            'dispense_uuid' => (string) Str::uuid(),
            'rx_order_id' => $medication->rx_order_id,
            'source_id' => $sourceId,
            'source_dispense_key' => $key,
            'dispense_channel' => $channel,
            'adc_station_id' => $adcStationId,
            'status' => 'dispensed',
            'dispensed_at' => $dispensedAt,
            'demo_owner' => $order->demo_owner,
            'metadata' => ($payload['fhir_backfill'] ?? null) === true ? ['fhir_backfill' => true] : [],
        ]);
        $dispense->save();

        return $dispense->refresh();
    }

    private function advanceStatus(MedicationOrder $medication, CanonicalOperationalEvent $event, string $milestone): void
    {
        $payload = $event->payload;
        $target = match ($milestone) {
            'RX_ORDERED' => 'ordered',
            'RX_QUEUE_IN' => 'queued',
            'RX_VERIFIED' => 'verified',
            'RX_DISPENSED' => 'dispensed',
            // Returned/wasted are reverse-logistics facts on the satellite
            // ledger; they never advance or regress the order status.
            'RX_RETURNED', 'RX_WASTED' => null,
            'RX_DISCONTINUED' => isset($payload['cancelled_at']) ? 'cancelled' : 'discontinued',
        };
        if ($target === null) {
            return;
        }

        if ($target === 'discontinued' && $medication->discontinued_at === null) {
            $medication->discontinued_at = CarbonImmutable::parse(
                (string) ($payload['discontinued_at'] ?? $event->occurredAt->toIso8601String()),
            )->utc();
        }
        if ($target === 'cancelled' && $medication->cancelled_at === null) {
            $medication->cancelled_at = CarbonImmutable::parse((string) $payload['cancelled_at'])->utc();
        }

        $currentRank = self::STATUS_RANK[(string) $medication->order_status] ?? 0;
        if ((self::STATUS_RANK[$target] ?? 0) > $currentRank) {
            $medication->order_status = $target;
        }
    }

    /** @return array<string, mixed> */
    private function metadata(MedicationOrder|Verification|Dispense $model): array
    {
        return is_array($model->metadata) ? $model->metadata : [];
    }
}
