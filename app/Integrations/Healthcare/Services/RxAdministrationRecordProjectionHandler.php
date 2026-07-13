<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Ancillary\PharmacyAdministrationImportNormalizer;
use App\Integrations\Healthcare\Contracts\ProjectionHandler;
use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Models\Integration\CanonicalEventRecord;
use App\Models\Integration\ProvenanceRecord;
use App\Services\Pharmacy\PharmacyOrderProjector;
use App\Services\Pharmacy\RxAdministrationProjector;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Projects non-given administration records (held/refused/missed) onto the
 * prod.rx_administrations satellite ledger. These are administration-level
 * facts on a defensibly matched order — no RX_ADMINISTERED milestone, no
 * order-status movement, and no SLA clock is ever asserted from this
 * handler; 'given' administrations travel the milestone path through
 * AncillaryProjectionHandler instead.
 */
class RxAdministrationRecordProjectionHandler implements ProjectionHandler
{
    public function __construct(
        private readonly RxAdministrationProjector $administrations,
        private readonly PharmacyOrderProjector $orders,
    ) {}

    public function key(): string
    {
        return 'ancillary.rx_administration_projection';
    }

    public function eventTypes(): array
    {
        return [PharmacyAdministrationImportNormalizer::RECORD_EVENT_TYPE];
    }

    public function supports(CanonicalOperationalEvent $event): bool
    {
        return $event->eventType === PharmacyAdministrationImportNormalizer::RECORD_EVENT_TYPE;
    }

    public function project(CanonicalOperationalEvent $event): void
    {
        if (! $this->supports($event)) {
            throw new InvalidArgumentException("Unsupported administration record projection event [{$event->eventType}].");
        }

        DB::transaction(function () use ($event): void {
            $record = CanonicalEventRecord::query()
                ->with('source')
                ->where('event_id', $event->eventId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($record->source === null) {
                throw new InvalidArgumentException('Administration record projection requires a governed integration source.');
            }

            $order = $this->administrations->matchOrder($event, (int) $record->source_id);
            $medication = $this->orders->medicationOrderFor($order, $event, (int) $record->source_id);
            $administration = $this->administrations->project($order, $medication, $event, (int) $record->source_id);

            ProvenanceRecord::query()->firstOrCreate(
                [
                    'canonical_event_id' => $record->canonical_event_id,
                    'target_schema' => 'prod',
                    'target_table' => 'rx_administrations',
                    'target_pk' => (string) $administration->rx_administration_id,
                ],
                [
                    'source_id' => $record->source_id,
                    'inbound_message_id' => $record->inbound_message_id,
                    'lineage' => [
                        'canonicalEventId' => $record->canonical_event_id,
                        'rxOrderId' => $medication->rx_order_id,
                        'sourceAdministrationKey' => $administration->source_administration_key,
                        'sourceRowVersion' => $administration->source_row_version,
                        'importBatchKey' => $administration->import_batch_key,
                    ],
                ],
            );
        });
    }
}
