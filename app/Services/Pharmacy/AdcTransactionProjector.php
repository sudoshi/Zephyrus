<?php

namespace App\Services\Pharmacy;

use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Models\Pharmacy\AdcStation;
use App\Models\Pharmacy\AdcTransaction;
use App\Models\Pharmacy\FormularyItem;
use App\Models\Pharmacy\MedicationOrder;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Idempotently projects canonical ADC transaction events onto the
 * prod.adc_stations registry and the prod.adc_transactions rollup ledger.
 * Station registry rows are created/updated from the envelope station
 * identity plus unit mapping; an unresolvable unit fails closed to the
 * dead-letter ledger instead of being silently coerced. Order links are
 * explicit only — an ambiguous candidate set is never guessed across — and
 * no user, actor, or risk attribute exists anywhere on this path (§13).
 */
final class AdcTransactionProjector
{
    public const TRANSACTION_TYPES = [
        'vend', 'refill', 'return', 'waste', 'override',
        'discrepancy_open', 'discrepancy_resolved', 'stockout',
    ];

    private const STATION_TYPES = ['general', 'anesthesia', 'procedural', 'emergency', 'other'];

    public function resolveStation(CanonicalOperationalEvent $event, int $sourceId): AdcStation
    {
        $payload = $event->payload;
        $stationKey = trim((string) ($payload['station_key'] ?? ''));
        if ($stationKey === '') {
            throw new InvalidArgumentException('ADC projection requires the station identity.');
        }

        $station = AdcStation::query()
            ->where('source_id', $sourceId)
            ->where('source_station_key', $stationKey)
            ->first();
        $station ??= new AdcStation([
            'station_uuid' => (string) Str::uuid(),
            'source_id' => $sourceId,
            'source_station_key' => $stationKey,
            'status' => 'operational',
            'demo_owner' => $payload['demo_owner'] ?? null,
            'metadata' => [],
        ]);

        $station->unit_id = $this->resolveUnitId((string) ($payload['station_unit'] ?? ''), $station);
        $station->label = trim((string) ($payload['station_label'] ?? '')) ?: ($station->label ?? $stationKey);
        $stationType = strtolower(trim((string) ($payload['station_type'] ?? '')));
        if (in_array($stationType, self::STATION_TYPES, true)) {
            $station->station_type = $stationType;
        } else {
            $station->station_type ??= 'general';
        }
        foreach (['station_is_profiled' => 'is_profiled', 'station_controlled_capable' => 'controlled_capable'] as $key => $column) {
            if (is_bool($payload[$key] ?? null)) {
                $station->{$column} = $payload[$key];
            } else {
                $station->{$column} ??= true;
            }
        }
        $station->save();

        return $station->refresh();
    }

    public function project(
        CanonicalOperationalEvent $event,
        int $sourceId,
        AdcStation $station,
        ?MedicationOrder $medication = null,
    ): AdcTransaction {
        $payload = $event->payload;
        $transactionKey = trim((string) ($payload['source_transaction_key'] ?? ''));
        $type = strtolower(trim((string) ($payload['transaction_type'] ?? '')));
        if ($transactionKey === '' || ! in_array($type, self::TRANSACTION_TYPES, true)) {
            throw new InvalidArgumentException('ADC projection requires a governed transaction identity and type.');
        }

        $occurredAt = CarbonImmutable::instance($event->occurredAt)->utc();
        $existing = AdcTransaction::query()
            ->where('source_id', $sourceId)
            ->where('source_transaction_key', $transactionKey)
            ->first();
        if ($existing !== null) {
            return $this->captureReplayConflict($existing, $occurredAt);
        }

        [$terminology, $isControlled] = $this->terminology($payload, $type, $occurredAt);
        [$rxOrderId, $orderLink] = $this->resolveOrderLink($payload, $medication);

        $transaction = AdcTransaction::query()->create([
            'transaction_uuid' => (string) Str::uuid(),
            'adc_station_id' => $station->adc_station_id,
            'source_id' => $sourceId,
            'source_transaction_key' => $transactionKey,
            'rx_order_id' => $rxOrderId,
            'unit_id' => $station->unit_id,
            'transaction_type' => $type,
            'is_controlled' => $isControlled,
            'quantity' => isset($payload['quantity']) && is_numeric($payload['quantity'])
                ? (float) $payload['quantity']
                : null,
            'discrepancy_key' => trim((string) ($payload['discrepancy_key'] ?? '')) ?: null,
            'occurred_at' => $occurredAt,
            'demo_owner' => $payload['demo_owner'] ?? null,
            'metadata' => array_filter([
                ...$terminology,
                'rx_event_code' => $payload['rx_event_code'] ?? null,
                'stockout_state' => $payload['stockout_state'] ?? null,
                'order_link' => $orderLink,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
        ]);

        if ($type === 'stockout') {
            $this->applyStockoutState($station, $payload, $occurredAt);
        }

        return $transaction->refresh();
    }

    /**
     * Resolves the envelope unit reference against prod.units. Unresolvable
     * or ambiguous units fail closed as data quality, never silent coercion.
     */
    private function resolveUnitId(string $unitRef, AdcStation $station): int
    {
        $unitRef = trim($unitRef);
        if ($unitRef === '') {
            if ($station->unit_id !== null) {
                return (int) $station->unit_id;
            }

            throw new AncillaryIngestException('missing_station_unit', 'The ADC station has no unit mapping.', 'ancillary_projection');
        }

        foreach (['abbreviation', 'name'] as $column) {
            $matches = Unit::query()
                ->where('is_deleted', false)
                ->whereRaw("lower({$column}) = ?", [strtolower($unitRef)])
                ->limit(2)
                ->get();
            if ($matches->count() > 1) {
                throw new AncillaryIngestException('ambiguous_station_unit', 'The ADC station unit mapping is ambiguous.', 'ancillary_projection');
            }
            if ($matches->count() === 1) {
                return (int) $matches->first()->unit_id;
            }
        }

        throw new AncillaryIngestException('unmapped_station_unit', 'The ADC station unit does not map to a hospital unit.', 'ancillary_projection');
    }

    /**
     * Explicit link only: a pre-resolved medication order (the milestone
     * path) wins; otherwise a station-scope `linked_order_key` must match
     * exactly one medication order. Zero matches record `unmatched`, two or
     * more record `ambiguous` — attachment is never guessed.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: ?int, 1: ?string}
     */
    private function resolveOrderLink(array $payload, ?MedicationOrder $medication): array
    {
        if ($medication !== null) {
            return [(int) $medication->rx_order_id, 'explicit'];
        }

        $linkedKey = trim((string) ($payload['linked_order_key'] ?? ''));
        if ($linkedKey === '') {
            return [null, null];
        }

        $candidates = MedicationOrder::query()
            ->where('source_order_key', $linkedKey)
            ->limit(2)
            ->get();

        return match ($candidates->count()) {
            1 => [(int) $candidates->first()->rx_order_id, 'explicit'],
            0 => [null, 'unmatched'],
            default => [null, 'ambiguous'],
        };
    }

    /**
     * Resolves the medication mapping candidates against the governed
     * formulary with the shared X-2 semantics: a missing mapping produces
     * the explicit `unmapped_local` flag with null codes, never a failure.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function terminology(array $payload, string $type, CarbonImmutable $anchor): array
    {
        $localCode = trim((string) ($payload['local_code'] ?? ''));
        $defaultControlled = is_bool($payload['is_controlled'] ?? null)
            ? $payload['is_controlled']
            : in_array($type, ['discrepancy_open', 'discrepancy_resolved'], true);
        if ($localCode === '') {
            return [[], $defaultControlled];
        }

        $formulary = FormularyItem::query()
            ->activeAt($anchor)
            ->where('local_code', $localCode)
            ->orderByDesc('effective_from')
            ->first();
        $ndc = trim((string) ($payload['ndc_code'] ?? '')) ?: $formulary?->ndc_code;
        $rxnorm = $formulary?->rxnorm_cui;
        $mapped = $rxnorm !== null || $ndc !== null;

        return [
            [
                'local_code' => $localCode,
                'rxnorm_cui' => $mapped ? $rxnorm : null,
                'ndc_code' => $mapped ? $ndc : null,
                'terminology_status' => $mapped ? 'mapped' : 'unmapped_local',
                'medication_label' => trim((string) ($payload['medication_label'] ?? '')) ?: ($formulary?->label ?? $localCode),
            ],
            $formulary !== null ? (bool) $formulary->is_controlled : $defaultControlled,
        ];
    }

    /**
     * Stockout events set/clear the station-level stockout state keyed by
     * medication local code (or the station-wide `*` key); no per-user
     * dimension exists.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyStockoutState(AdcStation $station, array $payload, CarbonImmutable $occurredAt): void
    {
        $key = trim((string) ($payload['local_code'] ?? '')) ?: '*';
        $metadata = is_array($station->metadata) ? $station->metadata : [];
        $open = is_array($metadata['open_stockouts'] ?? null) ? $metadata['open_stockouts'] : [];

        if (($payload['stockout_state'] ?? 'open') === 'resolved') {
            unset($open[$key]);
        } else {
            $open[$key] ??= $occurredAt->toIso8601String();
        }

        // An empty PHP array would encode as jsonb `[]`, not `{}` — drop the
        // key entirely so "no open stockout" stays one unambiguous state.
        if ($open === []) {
            unset($metadata['open_stockouts']);
        } else {
            $metadata['open_stockouts'] = $open;
        }
        $station->metadata = $metadata;
        $station->save();
    }

    private function captureReplayConflict(AdcTransaction $existing, CarbonImmutable $occurredAt): AdcTransaction
    {
        if (! $existing->occurred_at->equalTo($occurredAt)) {
            $metadata = is_array($existing->metadata) ? $existing->metadata : [];
            if (! array_key_exists('replay_conflict', $metadata)) {
                $metadata['replay_conflict'] = [
                    'retained' => $existing->occurred_at->toIso8601String(),
                    'candidate' => $occurredAt->toIso8601String(),
                ];
                $existing->metadata = $metadata;
                $existing->save();
            }
        }

        return $existing->refresh();
    }
}
