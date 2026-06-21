<?php

namespace App\Rtdc;

use App\Models\Bed;
use App\Models\CensusSnapshot;
use App\Models\Encounter;
use App\Models\Unit;
use App\Rtdc\Events\CanonicalEvent;
use Illuminate\Support\Facades\DB;

/**
 * Applies a CanonicalEvent to the materialized census read model.
 * Pure projection: idempotent given the same event stream; rebuildable by replay.
 */
class CensusProjector
{
    public function apply(CanonicalEvent $event): void
    {
        DB::transaction(function () use ($event) {
            match ($event->type) {
                CanonicalEvent::ENCOUNTER_STARTED => $this->onStarted($event),
                CanonicalEvent::ENCOUNTER_TRANSFERRED => $this->onTransferred($event),
                CanonicalEvent::ENCOUNTER_DISCHARGED => $this->onDischarged($event),
                CanonicalEvent::BED_STATUS_CHANGED => $this->onBedStatus($event),
                CanonicalEvent::ACUITY_CHANGED => $this->onAcuity($event),
                default => null,
            };
        });
    }

    private function onStarted(CanonicalEvent $e): void
    {
        Encounter::updateOrCreate(
            ['patient_ref' => $e->encounterRef, 'status' => 'active'],
            [
                'unit_id' => $e->payload['unit_id'],
                'bed_id' => $e->payload['bed_id'] ?? null,
                'acuity_tier' => $e->payload['acuity_tier'],
                'admitted_at' => $e->occurredAt,
            ],
        );
        if (! empty($e->payload['bed_id'])) {
            Bed::where('bed_id', $e->payload['bed_id'])->update(['status' => 'occupied']);
        }
    }

    private function onTransferred(CanonicalEvent $e): void
    {
        $enc = Encounter::active()->where('patient_ref', $e->encounterRef)->first();
        if (! $enc) {
            return;
        }
        if ($enc->bed_id) {
            Bed::where('bed_id', $enc->bed_id)->update(['status' => 'dirty']);
        }
        $enc->update([
            'unit_id' => $e->payload['to_unit_id'],
            'bed_id' => $e->payload['to_bed_id'] ?? null,
        ]);
        if (! empty($e->payload['to_bed_id'])) {
            Bed::where('bed_id', $e->payload['to_bed_id'])->update(['status' => 'occupied']);
        }
    }

    private function onDischarged(CanonicalEvent $e): void
    {
        $enc = Encounter::active()->where('patient_ref', $e->encounterRef)->first();
        if (! $enc) {
            return;
        }
        if ($enc->bed_id) {
            Bed::where('bed_id', $enc->bed_id)->update(['status' => 'dirty']);
        }
        $enc->update(['status' => 'discharged', 'discharged_at' => $e->occurredAt]);
    }

    private function onBedStatus(CanonicalEvent $e): void
    {
        Bed::where('bed_id', $e->payload['bed_id'])->update(['status' => $e->payload['status']]);
    }

    private function onAcuity(CanonicalEvent $e): void
    {
        Encounter::active()->where('patient_ref', $e->encounterRef)
            ->update(['acuity_tier' => $e->payload['acuity_tier']]);
    }

    /**
     * Recompute and persist a census snapshot for a unit.
     */
    public function snapshot(int $unitId): CensusSnapshot
    {
        $unit = Unit::findOrFail($unitId);
        $beds = Bed::where('unit_id', $unitId)->where('is_deleted', false)->get();
        $occupied = $beds->where('status', 'occupied')->count();
        $available = $beds->where('status', 'available')->count();
        $blocked = $beds->whereIn('status', ['blocked', 'dirty'])->count();

        return CensusSnapshot::create([
            'unit_id' => $unitId,
            'captured_at' => now(),
            'staffed_beds' => $unit->staffed_bed_count,
            'occupied' => $occupied,
            'available' => $available,
            'blocked' => $blocked,
            'acuity_adjusted_capacity' => app(\App\Services\AcuityService::class)->adjustedCapacity($unitId),
        ]);
    }
}
