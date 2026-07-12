<?php

namespace Database\Factories\Ancillary;

use App\Models\Ancillary\AncillaryMilestone;
use App\Models\Ancillary\AncillaryOrder;
use Database\Factories\Ancillary\Concerns\CreatesAncillaryIntegrationFixtures;
use Illuminate\Support\Facades\DB;

class AncillaryScenarioFactory
{
    use CreatesAncillaryIntegrationFixtures;

    public function full(): AncillaryOrder
    {
        return DB::transaction(function (): AncillaryOrder {
            $order = AncillaryOrder::factory()->radiology()->create();
            $orderedAt = $order->ordered_at;

            $this->milestone($order, 'RAD_ORDERED', 'rad', 10, 'ancillary.radiology.order_placed', $orderedAt);
            $this->milestone($order, 'RAD_EXAM_END', 'rad', 80, 'ancillary.radiology.exam_completed', $orderedAt->addMinutes(35));
            $this->milestone($order, 'RAD_FINAL', 'rad', 110, 'ancillary.radiology.report_finalized', $orderedAt->addMinutes(70));

            $order->update([
                'current_state' => 'final',
                'current_milestone_code' => 'RAD_FINAL',
                'current_milestone_at' => $orderedAt->addMinutes(70),
                'terminal_at' => $orderedAt->addMinutes(70),
                'source_cutoff_at' => $orderedAt->addMinutes(71),
            ]);

            return $order->refresh();
        });
    }

    public function degraded(): AncillaryOrder
    {
        return DB::transaction(function (): AncillaryOrder {
            $order = AncillaryOrder::factory()->radiology()->create([
                'metadata' => ['feed_mode' => 'minimum', 'missing_optional' => ['RAD_EXAM_END']],
            ]);
            $orderedAt = $order->ordered_at;

            $this->milestone($order, 'RAD_ORDERED', 'rad', 10, 'ancillary.radiology.order_placed', $orderedAt);
            $this->milestone($order, 'RAD_FINAL', 'rad', 110, 'ancillary.radiology.report_finalized', $orderedAt->addMinutes(90));

            return $order->refresh();
        });
    }

    public function conflictingSource(): AncillaryOrder
    {
        return DB::transaction(function (): AncillaryOrder {
            $order = AncillaryOrder::factory()->radiology()->create();
            $orderedAt = $order->ordered_at;
            $competingSourceId = $this->createIntegrationSourceId('ris');

            $this->milestone($order, 'RAD_EXAM_END', 'rad', 80, 'ancillary.radiology.exam_completed', $orderedAt->addMinutes(30), 20);
            $this->milestone(
                $order,
                'RAD_EXAM_END',
                'rad',
                80,
                'ancillary.radiology.exam_completed',
                $orderedAt->addMinutes(38),
                10,
                $competingSourceId,
            );

            return $order->refresh();
        });
    }

    public function outOfOrder(): AncillaryOrder
    {
        return DB::transaction(function (): AncillaryOrder {
            $order = AncillaryOrder::factory()->radiology()->create();
            $orderedAt = $order->ordered_at;

            $this->milestone($order, 'RAD_FINAL', 'rad', 110, 'ancillary.radiology.report_finalized', $orderedAt->addMinutes(60), receivedOffset: 1);
            $this->milestone($order, 'RAD_ORDERED', 'rad', 10, 'ancillary.radiology.order_placed', $orderedAt, receivedOffset: 90);

            return $order->refresh();
        });
    }

    public function terminal(): AncillaryOrder
    {
        return DB::transaction(function (): AncillaryOrder {
            $order = AncillaryOrder::factory()->radiology()->ownedDemo()->create();
            $cancelledAt = $order->ordered_at->addMinutes(12);

            $this->milestone($order, 'RAD_ORDERED', 'rad', 10, 'ancillary.radiology.order_placed', $order->ordered_at);
            $this->milestone($order, 'RAD_CANCELLED', 'rad', 999, 'ancillary.radiology.order_cancelled', $cancelledAt, terminal: true);

            $order->update([
                'current_state' => 'cancelled',
                'current_milestone_code' => 'RAD_CANCELLED',
                'current_milestone_at' => $cancelledAt,
                'terminal_at' => $cancelledAt,
                'source_cutoff_at' => $cancelledAt,
            ]);

            return $order->refresh();
        });
    }

    public function reworkLoop(): AncillaryOrder
    {
        return DB::transaction(function (): AncillaryOrder {
            $order = AncillaryOrder::factory()->lab()->create();
            $orderedAt = $order->ordered_at;

            $this->milestone($order, 'LAB_ORDERED', 'lab', 10, 'ancillary.lab.order_placed', $orderedAt);
            $this->milestone($order, 'LAB_REJECTED', 'lab', 90, 'ancillary.lab.specimen_rejected', $orderedAt->addMinutes(25));
            $this->milestone($order, 'LAB_RECOLLECT_ORDERED', 'lab', 100, 'ancillary.lab.recollect_ordered', $orderedAt->addMinutes(28));
            $this->milestone($order, 'LAB_COLLECTED', 'lab', 20, 'ancillary.lab.specimen_collected', $orderedAt->addMinutes(40));

            return $order->refresh();
        });
    }

    private function milestone(
        AncillaryOrder $order,
        string $code,
        string $department,
        int $ordinal,
        string $eventType,
        mixed $occurredAt,
        int $sourceRank = 100,
        ?int $sourceId = null,
        int $receivedOffset = 1,
        bool $terminal = false,
    ): AncillaryMilestone {
        $sourceId ??= (int) $order->source_id;

        return AncillaryMilestone::factory()
            ->for($order, 'order')
            ->state([
                'milestone_code' => $code,
                'occurred_at' => $occurredAt,
                'received_at' => $occurredAt->addSeconds($receivedOffset),
                'source_id' => $sourceId,
                'canonical_event_id' => $this->createCanonicalEventId($sourceId, $eventType, $occurredAt),
                'source_rank' => $sourceRank,
                'metadata' => ['scenario' => 'factory'],
            ])
            ->create();
    }
}
