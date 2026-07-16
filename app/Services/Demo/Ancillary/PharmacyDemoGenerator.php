<?php

namespace App\Services\Demo\Ancillary;

use App\Integrations\Healthcare\Ancillary\PharmacyAdcTransactionNormalizer;
use App\Integrations\Healthcare\Ancillary\PharmacyAdministrationImportNormalizer;
use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Models\Pharmacy\AdcStation;
use App\Models\Pharmacy\AdcTransaction;
use App\Models\Pharmacy\Administration;
use App\Models\Pharmacy\DischargeQueueItem;
use App\Models\Pharmacy\Dispense;
use App\Models\Pharmacy\MedicationOrder;
use App\Models\Pharmacy\Preparation;
use App\Models\Pharmacy\Verification;
use App\Services\Demo\DemoClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * The canonical Pharmacy demo cohort: every order and milestone travels the
 * governed synthetic canonical-event path (writer -> projectors) exactly like
 * the Laboratory generator — never a direct table insert for anything a
 * projector owns. Station-scope ADC operational events and one non-given
 * administration record travel the same write path through their dedicated
 * X-3/X-4 projection handlers. Administration facts arrive through a
 * synthetic warehouse batch whose explicit cutoff is EARLIER than the anchor,
 * so admin-derived metrics stay honestly batch/as-of while dispense evidence
 * stays current-real-time by contrast.
 */
final class PharmacyDemoGenerator extends AbstractAncillaryDemoGenerator
{
    /** Warehouse MAR extract cutoff sits five hours behind the frozen anchor. */
    public const WAREHOUSE_CUTOFF_MINUTES = 300;

    private const EXPECTED_ORDERS = 24;

    protected function department(): string
    {
        return 'rx';
    }

    protected function systemClass(): string
    {
        return 'pharmacy';
    }

    protected function scenarios(DemoClock $clock): array
    {
        $date = $clock->anchor()->toDateString();
        $stations = $this->stationRegistry();
        $surge = $this->surgeAnchor($clock);
        $sv = fn (int $offset): int => $this->minutesAgo($clock, $surge->addMinutes($offset));
        $dip = fn (int $offset): int => $this->minutesAgo($clock, $surge->subHours(2)->addMinutes($offset));

        $scenarios = [
            // 1 — STAT dispense overdue: verified but never dispensed; the
            // rx.stat_dispense clock is an OPEN breach at the frozen anchor.
            $this->scenario($clock, 1, 25, 'stat', 'inpatient', [
                $this->e('RX_ORDERED', 25), $this->e('RX_QUEUE_IN', 24), $this->e('RX_VERIFIED', 18),
            ], $this->meta($date, 1, 'ONDANSETRON_INJ', 'Ondansetron injection', 'stat', 'intravenous', 'adc'), true),
            // 2 — STAT cleared breach on a real ED visit through an ADC vend.
            $this->scenario($clock, 2, 90, 'stat', 'emergency', [
                $this->e('RX_ORDERED', 90), $this->e('RX_QUEUE_IN', 89), $this->e('RX_VERIFIED', 75),
                $this->a('RX_DISPENSED', 60, $this->adcVend($date, 2, $stations['ed'])),
                $this->e('RX_DELIVERED', 55),
            ], $this->meta($date, 2, 'ONDANSETRON_INJ', 'Ondansetron injection', 'stat', 'intravenous', 'adc', ['context' => 'ed'])),
            // 3 — STAT on time (controlled): vended in 8 minutes, partial waste after.
            $this->scenario($clock, 3, 12, 'stat', 'inpatient', [
                $this->e('RX_ORDERED', 12), $this->e('RX_QUEUE_IN', 11), $this->e('RX_VERIFIED', 9),
                $this->a('RX_DISPENSED', 4, $this->adcVend($date, 3, $stations['medsurg'])),
                $this->a('RX_WASTED', 2, $this->adcTransaction($date, 'waste-03', 'waste', $stations['medsurg'], 0.5)),
            ], $this->meta($date, 3, 'MORPHINE_INJ', 'Morphine injection', 'stat', 'intravenous', 'adc')),
            // 4 — delivered dose refused (warehouse record, no milestone) and returned to the cabinet.
            $this->scenario($clock, 4, 420, 'routine', 'inpatient', [
                $this->e('RX_ORDERED', 420), $this->e('RX_QUEUE_IN', 419), $this->e('RX_VERIFIED', 410),
                $this->a('RX_DISPENSED', 390, $this->adcVend($date, 4, $stations['medsurg'])),
                $this->e('RX_DELIVERED', 380),
                $this->a('RX_RETURNED', 150, $this->adcTransaction($date, 'return-04', 'return', $stations['medsurg'], 1)),
            ], $this->meta($date, 4, 'ACETAMINOPHEN_500_TAB', 'Acetaminophen 500 mg tablet', 'routine', 'oral', 'adc')),
            // 5 — first dose through the full IV-room branch; warehouse
            // administration clears the rx.first_dose_admin breach (80 min).
            $this->scenario($clock, 5, 420, 'first_dose', 'inpatient', [
                $this->e('RX_ORDERED', 420), $this->e('RX_QUEUE_IN', 419), $this->e('RX_VERIFIED', 405),
                $this->e('RX_PREP_STARTED', 395), $this->e('RX_PREP_COMPLETE', 375), $this->e('RX_CHECKED', 370),
                $this->e('RX_DISPENSED', 365, $this->dispense($date, '05', 'iv_room')),
                $this->e('RX_DELIVERED', 355),
                $this->w('RX_ADMINISTERED', 340, $this->administration($clock, '05')),
            ], $this->meta($date, 5, 'VANCOMYCIN_IV', 'Vancomycin intravenous infusion', 'first_dose', 'intravenous', 'iv_room')),
            // 6 — first dose administered just past the hour (65 min): the
            // clock opens and the warehouse batch clears it five minutes late.
            $this->scenario($clock, 6, 425, 'first_dose', 'inpatient', [
                $this->e('RX_ORDERED', 425), $this->e('RX_QUEUE_IN', 424), $this->e('RX_VERIFIED', 415),
                $this->e('RX_PREP_STARTED', 410), $this->e('RX_PREP_COMPLETE', 395), $this->e('RX_CHECKED', 390),
                $this->e('RX_DISPENSED', 385, $this->dispense($date, '06', 'iv_room')),
                $this->e('RX_DELIVERED', 375),
                $this->w('RX_ADMINISTERED', 360, $this->administration($clock, '06')),
            ], $this->meta($date, 6, 'HEPARIN_INFUSION', 'Heparin continuous infusion', 'first_dose', 'intravenous', 'iv_room')),
            // 7 — sepsis antibiotic on a real ED visit: dispensed and delivered
            // but no administration evidence inside the warehouse cutoff — the
            // rx.sepsis_abx clock is an OPEN breach at the frozen anchor.
            $this->scenario($clock, 7, 190, 'sepsis', 'emergency', [
                $this->e('RX_ORDERED', 190), $this->e('RX_QUEUE_IN', 189), $this->e('RX_VERIFIED', 183),
                $this->a('RX_DISPENSED', 175, $this->adcVend($date, 7, $stations['ed'])),
                $this->e('RX_DELIVERED', 170),
            ], $this->meta($date, 7, 'CEFTRIAXONE_1G_IV', 'Ceftriaxone 1 g intravenous', 'sepsis', 'intravenous', 'adc', ['context' => 'ed']), true),
            // 8 — sepsis antibiotic cleared by the warehouse batch (200 min).
            $this->scenario($clock, 8, 510, 'sepsis', 'emergency', [
                $this->e('RX_ORDERED', 510), $this->e('RX_QUEUE_IN', 509), $this->e('RX_VERIFIED', 500),
                $this->a('RX_DISPENSED', 490, $this->adcVend($date, 8, $stations['ed'])),
                $this->e('RX_DELIVERED', 480),
                $this->w('RX_ADMINISTERED', 310, $this->administration($clock, '08')),
            ], $this->meta($date, 8, 'CEFTRIAXONE_1G_IV', 'Ceftriaxone 1 g intravenous', 'sepsis', 'intravenous', 'adc', ['context' => 'ed'])),
            // 9 — sepsis clock at risk: preparation running, 165 of 180 minutes elapsed.
            $this->scenario($clock, 9, 165, 'sepsis', 'emergency', [
                $this->e('RX_ORDERED', 165), $this->e('RX_QUEUE_IN', 164), $this->e('RX_VERIFIED', 155),
                $this->e('RX_PREP_STARTED', 140),
            ], $this->meta($date, 9, 'VANCOMYCIN_IV', 'Vancomycin intravenous infusion', 'sepsis', 'intravenous', 'iv_room', ['context' => 'ed'])),
            // 10 — missing-dose loop: vended, delivered, re-requested, re-dispensed centrally.
            $this->scenario($clock, 10, 200, 'urgent', 'inpatient', [
                $this->e('RX_ORDERED', 200), $this->e('RX_QUEUE_IN', 199), $this->e('RX_VERIFIED', 190),
                $this->a('RX_DISPENSED', 170, $this->adcVend($date, 10, $stations['medsurg'])),
                $this->e('RX_DELIVERED', 160),
                $this->e('RX_MISSING_DOSE', 90),
                $this->e('RX_DISPENSED', 70, $this->dispense($date, '10b', 'central')),
                $this->e('RX_DELIVERED', 60),
            ], $this->meta($date, 10, 'ONDANSETRON_INJ', 'Ondansetron injection', 'routine', 'intravenous', 'adc')),
            // 11 — shortage: verified but blocked on sourcing; the ED cabinet
            // carries the matching open stockout signal for this medication.
            $this->scenario($clock, 11, 140, 'routine', 'inpatient', [
                $this->e('RX_ORDERED', 140), $this->e('RX_QUEUE_IN', 139), $this->e('RX_VERIFIED', 130),
            ], $this->meta($date, 11, 'CEFTRIAXONE_1G_IV', 'Ceftriaxone 1 g intravenous', 'routine', 'intravenous', 'adc')),
            // 12 — discharge medication ready, tied to the live discharge cohort.
            $this->scenario($clock, 12, 150, 'discharge', 'inpatient', [
                $this->e('RX_ORDERED', 150), $this->e('RX_QUEUE_IN', 149), $this->e('RX_VERIFIED', 120),
                $this->e('RX_DISPENSED', 45, $this->dispense($date, '12', 'central')),
            ], $this->meta($date, 12, 'WARFARIN_5_TAB', 'Warfarin 5 mg tablet', 'discharge', 'oral', 'central', ['context' => 'discharge'])),
            // 13 — discharge medication blocking discharge: prior authorization pending.
            $this->scenario($clock, 13, 95, 'discharge', 'inpatient', [
                $this->e('RX_ORDERED', 95), $this->e('RX_QUEUE_IN', 94),
            ], $this->meta($date, 13, 'WARFARIN_5_TAB', 'Warfarin 5 mg tablet', 'discharge', 'oral', 'central', ['context' => 'discharge', 'discharge_blocking' => true])),
            // 14 — IVWMS-absent degraded branch: verified jumps straight to
            // dispensed with no preparation milestones and no rx_preps rows.
            $this->scenario($clock, 14, 260, 'routine', 'inpatient', [
                $this->e('RX_ORDERED', 260), $this->e('RX_QUEUE_IN', 259), $this->e('RX_VERIFIED', 245),
                $this->e('RX_DISPENSED', 180, $this->dispense($date, '14', 'iv_room')),
            ], $this->meta($date, 14, 'CYCLOPHOSPHAMIDE_IV', 'Cyclophosphamide intravenous infusion', 'routine', 'intravenous', 'iv_room')),
            // 15 — deterministic source-precedence conflict: pharmacy and ADC
            // both assert RX_DISPENSED; the catalog precedence selects pharmacy.
            $this->scenario($clock, 15, 130, 'routine', 'inpatient', [
                $this->e('RX_ORDERED', 130), $this->e('RX_QUEUE_IN', 129), $this->e('RX_VERIFIED', 115),
                $this->e('RX_DISPENSED', 45, $this->dispense($date, '15', 'central')),
                $this->event('RX_DISPENSED', 42, 'secondary'),
            ], $this->meta($date, 15, 'ONDANSETRON_INJ', 'Ondansetron injection', 'routine', 'intravenous', 'central')),
            // 16 — TPN batch in the IV room: unmapped-local terminology, batch + BUD on the prep.
            $this->scenario($clock, 16, 320, 'routine', 'inpatient', [
                $this->e('RX_ORDERED', 320), $this->e('RX_QUEUE_IN', 319), $this->e('RX_VERIFIED', 300),
                $this->e('RX_PREP_STARTED', 250), $this->e('RX_PREP_COMPLETE', 90),
            ], $this->meta($date, 16, 'TPN_ADULT', 'Total parenteral nutrition, adult admixture', 'routine', 'intravenous', 'iv_room')),
        ];

        // 17-22 — the 09:00-11:00 verification-queue surge: six orders enter the
        // queue inside the window; two are still awaiting a pharmacist.
        $surgeMeds = [
            ['ACETAMINOPHEN_500_TAB', 'Acetaminophen 500 mg tablet', 'oral', 'adc'],
            ['ONDANSETRON_INJ', 'Ondansetron injection', 'intravenous', 'adc'],
        ];
        foreach ([[6, 18], [21, 26], [34, 31], [47, 42], [63, null], [76, null]] as $index => [$offset, $verifyDelta]) {
            $ordinal = 17 + $index;
            [$code, $label, $route, $branch] = $surgeMeds[$index % 2];
            $events = [
                $this->e('RX_ORDERED', $sv($offset - 2)),
                $this->e('RX_QUEUE_IN', $sv($offset)),
            ];
            if ($verifyDelta !== null) {
                $events[] = $this->e('RX_VERIFIED', $sv($offset + $verifyDelta));
            }
            $scenarios[] = $this->scenario($clock, $ordinal, $sv($offset - 2), 'routine', 'inpatient', $events,
                $this->meta($date, $ordinal, $code, $label, 'routine', $route, $branch, ['shift' => 'verify_surge']));
        }

        // 23-24 — the 07:00 shift-boundary dip: sparse queue entries around handoff.
        foreach ([[23, -15, 12], [24, 20, 15]] as [$ordinal, $offset, $verifyDelta]) {
            $scenarios[] = $this->scenario($clock, $ordinal, $dip($offset - 2), 'routine', 'inpatient', [
                $this->e('RX_ORDERED', $dip($offset - 2)),
                $this->e('RX_QUEUE_IN', $dip($offset)),
                $this->e('RX_VERIFIED', $dip($offset + $verifyDelta)),
            ], $this->meta($date, $ordinal, 'ACETAMINOPHEN_500_TAB', 'Acetaminophen 500 mg tablet', 'routine', 'oral', 'adc', ['shift' => 'shift_boundary_dip']));
        }

        return $scenarios;
    }

    /**
     * Station-scope ADC operational events (X-3 path: no order, no milestone,
     * no SLA clock) plus one non-given administration record (X-4 path).
     */
    protected function operationalEvents(DemoClock $clock, string $owner): array
    {
        $date = $clock->anchor()->toDateString();
        $stations = $this->stationRegistry();
        $entries = [];

        $transactions = [
            ['suffix' => 'refill-01', 'type' => 'refill', 'station' => $stations['medsurg'], 'minutes' => 200, 'local_code' => 'ACETAMINOPHEN_500_TAB', 'label' => 'Acetaminophen 500 mg tablet', 'quantity' => 40, 'rx_event_code' => null],
            ['suffix' => 'refill-02', 'type' => 'refill', 'station' => $stations['icu'], 'minutes' => 400, 'local_code' => 'HEPARIN_INFUSION', 'label' => 'Heparin continuous infusion', 'quantity' => 10, 'rx_event_code' => null],
            ['suffix' => 'override-01', 'type' => 'override', 'station' => $stations['ed'], 'minutes' => 65, 'local_code' => 'ONDANSETRON_INJ', 'label' => 'Ondansetron injection', 'quantity' => 1, 'rx_event_code' => 'RX_OVERRIDE'],
            ['suffix' => 'disc-01-open', 'type' => 'discrepancy_open', 'station' => $stations['medsurg'], 'minutes' => 320, 'local_code' => 'MORPHINE_INJ', 'label' => 'Morphine injection', 'quantity' => 1, 'rx_event_code' => 'RX_DISCREPANCY_OPEN', 'discrepancy_key' => "demo:{$date}:rx:disc:01"],
            ['suffix' => 'disc-01-resolved', 'type' => 'discrepancy_resolved', 'station' => $stations['medsurg'], 'minutes' => 250, 'local_code' => 'MORPHINE_INJ', 'label' => 'Morphine injection', 'quantity' => 1, 'rx_event_code' => 'RX_DISCREPANCY_RESOLVED', 'discrepancy_key' => "demo:{$date}:rx:disc:01"],
            ['suffix' => 'disc-02-open', 'type' => 'discrepancy_open', 'station' => $stations['ed'], 'minutes' => 95, 'local_code' => 'MORPHINE_INJ', 'label' => 'Morphine injection', 'quantity' => 1, 'rx_event_code' => 'RX_DISCREPANCY_OPEN', 'discrepancy_key' => "demo:{$date}:rx:disc:02"],
            ['suffix' => 'stockout-01', 'type' => 'stockout', 'station' => $stations['ed'], 'minutes' => 150, 'local_code' => 'CEFTRIAXONE_1G_IV', 'label' => 'Ceftriaxone 1 g intravenous', 'quantity' => null, 'rx_event_code' => null, 'stockout_state' => 'open'],
        ];
        foreach ($transactions as $transaction) {
            $key = "demo:{$date}:rx:txn:{$transaction['suffix']}";
            $identity = 'rx-station|'.$key;
            $occurredAt = $clock->anchor()->subMinutes((int) $transaction['minutes']);
            $payload = array_filter([
                'department' => 'rx',
                'adc_station_scope' => true,
                'source_transaction_key' => $key,
                'transaction_type' => $transaction['type'],
                'rx_event_code' => $transaction['rx_event_code'],
                'station_key' => $transaction['station']['key'],
                'station_unit' => $transaction['station']['unit'],
                'station_label' => $transaction['station']['label'],
                'station_type' => $transaction['station']['type'],
                'local_code' => $transaction['local_code'],
                'medication_label' => $transaction['label'],
                'quantity' => $transaction['quantity'],
                'discrepancy_key' => $transaction['discrepancy_key'] ?? null,
                'stockout_state' => $transaction['stockout_state'] ?? null,
                'demo_owner' => $owner,
                'source_timestamp_valid' => true,
            ], fn (mixed $value): bool => $value !== null && $value !== '');
            $entries[] = ['source' => 'secondary', 'event' => new CanonicalOperationalEvent(
                eventId: $this->demoUuid('event|'.$date.'|'.$identity),
                eventType: PharmacyAdcTransactionNormalizer::STATION_EVENT_TYPE,
                entityType: 'adc_station',
                entityRef: $transaction['station']['key'],
                payload: $payload,
                occurredAt: $occurredAt,
                idempotencyKey: 'demo:ancillary:'.hash('sha256', $date.'|'.$identity),
                correlationId: $key,
                sequenceKey: $transaction['station']['key'],
                metadata: ['demo_owner' => $owner],
            )];
        }

        // Non-given administration record: the delivered dose in scenario 4
        // was refused per the warehouse extract — a satellite fact on the
        // strictly matched order with no milestone and no clock movement.
        $orderKey = $this->orderKey($date, 4);
        $administrationKey = "demo:{$date}:rx:admin:04-refused";
        $identity = 'rx-admin-record|'.$administrationKey;
        $administeredAt = $clock->anchor()->subMinutes(340);
        $entries[] = ['source' => 'warehouse', 'event' => new CanonicalOperationalEvent(
            eventId: $this->demoUuid('event|'.$date.'|'.$identity),
            eventType: PharmacyAdministrationImportNormalizer::RECORD_EVENT_TYPE,
            entityType: 'rx_administration',
            entityRef: $administrationKey,
            payload: [
                'department' => 'rx',
                'rx_administration_scope' => true,
                'require_existing_order' => true,
                'source_order_key' => $orderKey,
                'source_administration_key' => $administrationKey,
                'import_batch_key' => "demo:{$date}:rx:mar-extract:01",
                'administration_source_class' => 'bcma_warehouse',
                'administration_status' => 'refused',
                'administration_route' => 'oral',
                'administered_at' => $administeredAt->toIso8601String(),
                'source_cutoff_at' => $this->warehouseCutoff($clock)->toIso8601String(),
                'demo_owner' => $owner,
                'source_timestamp_valid' => true,
            ],
            occurredAt: $administeredAt,
            idempotencyKey: 'demo:ancillary:'.hash('sha256', $date.'|'.$identity),
            correlationId: $orderKey,
            sequenceKey: $orderKey,
            metadata: ['demo_owner' => $owner],
        )];

        return $entries;
    }

    public function refresh(DemoClock $clock, string $owner): array
    {
        return DB::transaction(function () use ($clock, $owner): array {
            $this->resetOwnedStationScopeFacts($owner);

            $result = parent::refresh($clock, $owner);

            $date = $clock->anchor()->toDateString();
            $anchor = $clock->anchor();
            $medications = MedicationOrder::query()->where('demo_owner', $owner)->get()->keyBy('source_order_key');
            if ($medications->count() !== self::EXPECTED_ORDERS) {
                throw new RuntimeException('Pharmacy demo requires exactly '.self::EXPECTED_ORDERS.' owned medication orders.');
            }
            $medication = function (int $ordinal) use ($medications, $date): MedicationOrder {
                $order = $medications->get($this->orderKey($date, $ordinal));
                if ($order === null) {
                    throw new RuntimeException("Pharmacy demo scenario {$ordinal} did not project its medication order.");
                }

                return $order;
            };

            // Shortage state: no canonical projector owns rx_orders.on_shortage
            // yet, so the demo asserts it directly on the owned satellite row.
            $shortage = $medication(11);
            $shortage->on_shortage = true;
            $shortage->metadata = [
                ...$shortage->metadata,
                'shortage_context' => [
                    'reason_code' => 'RX_STOCKOUT',
                    'station_key' => $this->stationRegistry()['ed']['key'],
                    'noted_at' => $anchor->subMinutes(130)->toIso8601String(),
                ],
            ];
            $shortage->save();

            // P4-3 optional station inventory evidence. Two current snapshots,
            // one stale-but-valid snapshot, one already-observed stockout, and
            // an ICU station with transaction velocity but no inventory are
            // deliberate so every forecast coverage state is reproducible.
            $inventoryByStation = [
                $this->stationRegistry()['ed']['key'] => [
                    'ONDANSETRON_INJ' => [
                        'medication_label' => 'Ondansetron injection',
                        'on_hand' => 2,
                        'par_level' => 12,
                        'captured_at' => $anchor->subMinutes(20)->toIso8601String(),
                        'refill_cadence_minutes' => 240,
                    ],
                    'CEFTRIAXONE_1G_IV' => [
                        'medication_label' => 'Ceftriaxone 1 g intravenous',
                        'on_hand' => 0,
                        'par_level' => 8,
                        'captured_at' => $anchor->subMinutes(20)->toIso8601String(),
                        'refill_cadence_minutes' => 180,
                    ],
                ],
                $this->stationRegistry()['medsurg']['key'] => [
                    'ACETAMINOPHEN_500_TAB' => [
                        'medication_label' => 'Acetaminophen 500 mg tablet',
                        'on_hand' => 18,
                        'par_level' => 40,
                        'captured_at' => $anchor->subMinutes(25)->toIso8601String(),
                        'refill_cadence_minutes' => 360,
                    ],
                    'MORPHINE_INJ' => [
                        'medication_label' => 'Morphine injection',
                        'on_hand' => 3,
                        'par_level' => 10,
                        'captured_at' => $anchor->subMinutes(180)->toIso8601String(),
                        'refill_cadence_minutes' => 480,
                    ],
                ],
            ];
            foreach ($inventoryByStation as $stationKey => $inventory) {
                $station = AdcStation::query()
                    ->where('demo_owner', $owner)
                    ->where('source_station_key', $stationKey)
                    ->firstOrFail();
                $stationMetadata = is_array($station->metadata) ? $station->metadata : [];
                $station->metadata = [...$stationMetadata, 'inventory' => $inventory];
                $station->save();
            }

            // IV-room preparation satellites (batch refs + BUD). Scenario 14
            // deliberately gets none: that is the IVWMS-absent degraded branch.
            foreach ([
                ['ordinal' => 5, 'type' => 'iv_batch', 'state' => 'checked', 'batch' => "demo:{$date}:rx:iv-batch:am", 'started' => 395, 'completed' => 375, 'checked' => 370, 'bud_hours' => 18],
                ['ordinal' => 6, 'type' => 'iv_batch', 'state' => 'checked', 'batch' => "demo:{$date}:rx:iv-batch:am", 'started' => 410, 'completed' => 395, 'checked' => 390, 'bud_hours' => 18],
                ['ordinal' => 9, 'type' => 'iv_batch', 'state' => 'in_progress', 'batch' => null, 'started' => 140, 'completed' => null, 'checked' => null, 'bud_hours' => 12],
                ['ordinal' => 16, 'type' => 'tpn', 'state' => 'complete', 'batch' => "demo:{$date}:rx:tpn-batch:01", 'started' => 250, 'completed' => 90, 'checked' => null, 'bud_hours' => 24],
            ] as $prep) {
                $order = $medication($prep['ordinal']);
                Preparation::query()->create([
                    'prep_uuid' => Uuid::uuid5(Uuid::NAMESPACE_URL, "zephyrus|ancillary-demo|rx-prep|{$date}|{$prep['ordinal']}")->toString(),
                    'rx_order_id' => $order->rx_order_id,
                    'source_id' => $order->source_id,
                    'source_prep_key' => sprintf('demo:%s:rx:prep:%02d', $date, $prep['ordinal']),
                    'prep_type' => $prep['type'],
                    'prep_branch' => 'iv_room',
                    'batch_ref' => $prep['batch'],
                    'prep_state' => $prep['state'],
                    'started_at' => $anchor->subMinutes($prep['started']),
                    'completed_at' => $prep['completed'] !== null ? $anchor->subMinutes($prep['completed']) : null,
                    'checked_at' => $prep['checked'] !== null ? $anchor->subMinutes($prep['checked']) : null,
                    'bud_expires_at' => $anchor->addHours($prep['bud_hours']),
                    'demo_owner' => $owner,
                    'metadata' => ['scenario' => 'iv_room_batch'],
                ]);
            }

            // Discharge medication pipeline rows tied to the live discharge cohort.
            foreach ([
                ['ordinal' => 12, 'status' => 'ready', 'columns' => ['verification_started_at' => $anchor->subMinutes(120), 'filling_started_at' => $anchor->subMinutes(90), 'ready_at' => $anchor->subMinutes(45)], 'changed' => 45, 'planned' => 180],
                ['ordinal' => 13, 'status' => 'prior_auth_pending', 'columns' => ['prior_auth_pending_at' => $anchor->subMinutes(80)], 'changed' => 80, 'planned' => 120],
            ] as $queue) {
                $order = $medication($queue['ordinal']);
                DischargeQueueItem::query()->create([
                    'discharge_queue_uuid' => Uuid::uuid5(Uuid::NAMESPACE_URL, "zephyrus|ancillary-demo|rx-dcq|{$date}|{$queue['ordinal']}")->toString(),
                    'rx_order_id' => $order->rx_order_id,
                    'source_id' => $order->source_id,
                    'source_queue_key' => sprintf('demo:%s:rx:dcq:%02d', $date, $queue['ordinal']),
                    'encounter_id' => $order->encounter_id,
                    'pipeline_status' => $queue['status'],
                    'status_changed_at' => $anchor->subMinutes($queue['changed']),
                    'planned_discharge_at' => $anchor->addMinutes($queue['planned']),
                    ...$queue['columns'],
                    'demo_owner' => $owner,
                    'metadata' => ['scenario' => $queue['status'] === 'ready' ? 'discharge_ready' : 'discharge_blocked'],
                ]);
            }

            return [...$result,
                'medicationOrders' => $medications->count(),
                'verifications' => Verification::query()->where('demo_owner', $owner)->count(),
                'preparations' => Preparation::query()->where('demo_owner', $owner)->count(),
                'dispenses' => Dispense::query()->where('demo_owner', $owner)->count(),
                'administrations' => Administration::query()->where('demo_owner', $owner)->count(),
                'adcStations' => AdcStation::query()->where('demo_owner', $owner)->count(),
                'adcTransactions' => AdcTransaction::query()->where('demo_owner', $owner)->count(),
                'dischargeQueue' => DischargeQueueItem::query()->where('demo_owner', $owner)->count(),
                'openStockoutStations' => AdcStation::query()->where('demo_owner', $owner)
                    ->whereRaw("coalesce(metadata->'open_stockouts', '{}'::jsonb) NOT IN ('{}'::jsonb, '[]'::jsonb)")
                    ->count(),
            ];
        }, 3);
    }

    /**
     * Station-scope facts do not cascade from the ancillary order reset: the
     * transaction ledger, its provenance, per-medication stockout state, and
     * optional planning inventory on owned registry stations are cleared here
     * before replay so two same-anchor refreshes converge. Non-owned rows are
     * never selected.
     */
    private function resetOwnedStationScopeFacts(string $owner): void
    {
        $operationalEventIds = DB::table('integration.canonical_events')
            ->whereIn('event_type', [
                PharmacyAdcTransactionNormalizer::STATION_EVENT_TYPE,
                PharmacyAdministrationImportNormalizer::RECORD_EVENT_TYPE,
            ])
            ->whereRaw("metadata->>'demo_owner' = ?", [$owner])
            ->pluck('canonical_event_id');
        if ($operationalEventIds->isNotEmpty()) {
            DB::table('integration.provenance_records')
                ->where('target_schema', 'prod')
                ->whereIn('target_table', ['adc_transactions', 'rx_administrations'])
                ->whereIn('canonical_event_id', $operationalEventIds)
                ->delete();
        }

        AdcTransaction::query()->where('demo_owner', $owner)->delete();
        AdcStation::query()->where('demo_owner', $owner)->get()->each(function (AdcStation $station): void {
            $metadata = is_array($station->metadata) ? $station->metadata : [];
            if (array_key_exists('open_stockouts', $metadata)) {
                unset($metadata['open_stockouts']);
            }
            unset($metadata['inventory']);
            $station->metadata = $metadata;
            $station->save();
        });
    }

    /** @return array<string, array{key: string, unit: string, label: string, type: string}> */
    private function stationRegistry(): array
    {
        $units = DB::table('prod.units')
            ->where('is_deleted', false)
            ->whereNotNull('name')
            ->orderByRaw("CASE WHEN lower(type) = 'ed' THEN 0 ELSE 1 END")
            ->orderBy('unit_id')
            ->get(['unit_id', 'name'])
            ->unique('name')
            ->values();
        if ($units->isEmpty()) {
            throw new RuntimeException('Pharmacy demo requires at least one hospital unit for ADC stations.');
        }
        $unit = fn (int $index): string => (string) $units[$index % $units->count()]->name;

        return [
            'ed' => ['key' => 'demo:rx:station:ED-01', 'unit' => $unit(0), 'label' => 'Demo ADC — ED Bay', 'type' => 'emergency'],
            'medsurg' => ['key' => 'demo:rx:station:MS-01', 'unit' => $unit(1), 'label' => 'Demo ADC — Med/Surg', 'type' => 'general'],
            'icu' => ['key' => 'demo:rx:station:ICU-01', 'unit' => $unit(2), 'label' => 'Demo ADC — ICU', 'type' => 'general'],
        ];
    }

    /** @return array<string, mixed> */
    private function e(string $code, int $minutesAgo, array $attributes = []): array
    {
        return $this->event($code, $minutesAgo, 'primary', false, $attributes);
    }

    /** Warehouse-sourced event (the synthetic MAR extract). @return array<string, mixed> */
    private function w(string $code, int $minutesAgo, array $attributes = []): array
    {
        return $this->event($code, $minutesAgo, 'warehouse', false, $attributes);
    }

    /** ADC-sourced event (order-linked cabinet transactions). @return array<string, mixed> */
    private function a(string $code, int $minutesAgo, array $attributes = []): array
    {
        return $this->event($code, $minutesAgo, 'secondary', false, $attributes);
    }

    /** @return array<string, mixed> */
    private function meta(
        string $date,
        int $ordinal,
        string $localCode,
        string $label,
        string $clockClass,
        string $route,
        string $branch,
        array $attributes = [],
    ): array {
        return [
            'local_code' => $localCode,
            'medication_label' => $label,
            'clock_class' => $clockClass,
            'route' => $route,
            'preparation_branch' => $branch,
            'source_verification_key' => sprintf('demo:%s:rx:verif:%02d', $date, $ordinal),
            ...$attributes,
        ];
    }

    /** Order-linked ADC vend: milestone + dispense (channel adc) + station transaction. @return array<string, mixed> */
    private function adcVend(string $date, int $ordinal, array $station): array
    {
        return [
            'source_dispense_key' => sprintf('demo:%s:rx:disp:%02d', $date, $ordinal),
            'dispense_channel' => 'adc',
            ...$this->adcTransaction($date, sprintf('vend-%02d', $ordinal), 'vend', $station, 1),
        ];
    }

    /** @return array<string, mixed> */
    private function adcTransaction(string $date, string $suffix, string $type, array $station, float $quantity): array
    {
        return [
            'source_transaction_key' => "demo:{$date}:rx:txn:{$suffix}",
            'transaction_type' => $type,
            'quantity' => $quantity,
            'station_key' => $station['key'],
            'station_unit' => $station['unit'],
            'station_label' => $station['label'],
            'station_type' => $station['type'],
        ];
    }

    /** @return array<string, mixed> */
    private function dispense(string $date, string $suffix, string $channel): array
    {
        return [
            'source_dispense_key' => "demo:{$date}:rx:disp:{$suffix}",
            'dispense_channel' => $channel,
        ];
    }

    /** Warehouse administration row identity + explicit as-of cutoff. @return array<string, mixed> */
    private function administration(DemoClock $clock, string $suffix, string $status = 'given'): array
    {
        $date = $clock->anchor()->toDateString();

        return [
            'source_administration_key' => "demo:{$date}:rx:admin:{$suffix}",
            'import_batch_key' => "demo:{$date}:rx:mar-extract:01",
            'administration_source_class' => 'bcma_warehouse',
            'administration_status' => $status,
            'administration_route' => 'intravenous',
            'source_cutoff_at' => $this->warehouseCutoff($clock)->toIso8601String(),
        ];
    }

    private function orderKey(string $date, int $ordinal): string
    {
        return implode(':', ['demo', $date, 'rx', str_pad((string) $ordinal, 2, '0', STR_PAD_LEFT)]);
    }

    private function warehouseCutoff(DemoClock $clock): CarbonImmutable
    {
        return $clock->anchor()->subMinutes(self::WAREHOUSE_CUTOFF_MINUTES);
    }

    private function demoUuid(string $name): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'zephyrus|ancillary-demo|'.$name)->toString();
    }

    private function surgeAnchor(DemoClock $clock): CarbonImmutable
    {
        $local = $clock->anchor()->setTimezone((string) config('app.timezone', 'UTC'));
        $anchor = $local->startOfDay()->addHours(9);
        if ($local->lessThan($anchor)) {
            $anchor = $anchor->subDay();
        }

        return $anchor;
    }

    private function minutesAgo(DemoClock $clock, CarbonImmutable $at): int
    {
        return max(0, (int) round($at->diffInSeconds($clock->anchor(), false) / 60));
    }
}
