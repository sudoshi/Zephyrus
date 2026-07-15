<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Ancillary\PharmacyAdcTransactionNormalizer;
use App\Integrations\Healthcare\Contracts\ProjectionHandler;
use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Models\Integration\CanonicalEventRecord;
use App\Models\Integration\ProvenanceRecord;
use App\Services\Pharmacy\AdcTransactionProjector;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Projects station/unit-scoped ADC operational events (unlinked vends,
 * refills, returns, wastes, overrides, discrepancies, stockouts) onto the
 * station registry and transaction ledger. No ancillary order, milestone,
 * or SLA clock is ever touched from this handler — order-linked ADC events
 * travel the milestone path through AncillaryProjectionHandler instead.
 */
class AdcStationEventProjectionHandler implements ProjectionHandler
{
    public function __construct(private readonly AdcTransactionProjector $projector) {}

    public function key(): string
    {
        return 'ancillary.adc_station_projection';
    }

    public function eventTypes(): array
    {
        return [PharmacyAdcTransactionNormalizer::STATION_EVENT_TYPE];
    }

    public function supports(CanonicalOperationalEvent $event): bool
    {
        return $event->eventType === PharmacyAdcTransactionNormalizer::STATION_EVENT_TYPE;
    }

    public function project(CanonicalOperationalEvent $event): void
    {
        if (! $this->supports($event)) {
            throw new InvalidArgumentException("Unsupported ADC station projection event [{$event->eventType}].");
        }

        DB::transaction(function () use ($event): void {
            $record = CanonicalEventRecord::query()
                ->with('source')
                ->where('event_id', $event->eventId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($record->source === null) {
                throw new InvalidArgumentException('ADC station projection requires a governed integration source.');
            }

            $station = $this->projector->resolveStation($event, (int) $record->source_id);
            $transaction = $this->projector->project($event, (int) $record->source_id, $station);

            ProvenanceRecord::query()->firstOrCreate(
                [
                    'canonical_event_id' => $record->canonical_event_id,
                    'target_schema' => 'prod',
                    'target_table' => 'adc_transactions',
                    'target_pk' => (string) $transaction->adc_transaction_id,
                ],
                [
                    'source_id' => $record->source_id,
                    'inbound_message_id' => $record->inbound_message_id,
                    'lineage' => [
                        'canonicalEventId' => $record->canonical_event_id,
                        'adcStationId' => $station->adc_station_id,
                        'sourceTransactionKey' => $transaction->source_transaction_key,
                        'transactionType' => $transaction->transaction_type,
                    ],
                ],
            );
        });
    }
}
