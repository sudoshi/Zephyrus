<?php

namespace App\Services\Home;

use App\Models\Bed;
use App\Models\Home\HomeEpisode;
use App\Models\Home\HomeReferral;
use App\Models\Unit;
use Illuminate\Support\Collection;

/**
 * Builds the Virtual Bed Board (/home/census) payload — Phase 0 flagship
 * (build brief §8.2). The virtual ward is a prod.units row, so occupancy
 * comes off the same census spine as the house-wide boards.
 *
 * Slot-state vocabulary: prod.beds.status is CHECK-constrained to
 * available|occupied|blocked|dirty; on the virtual ward "dirty" means
 * pending kit setup, so it is translated to `pending_setup` at this
 * boundary rather than widening the CHECK (build brief §6.1 ⚠).
 *
 * PHI posture: the payload carries pseudonymous patient_ref and service
 * zones only — never MRNs or street addresses.
 */
class HomeCensusService
{
    /** @return array<string, mixed> */
    public function build(): array
    {
        $unit = Unit::where('type', 'virtual_home')
            ->where('is_deleted', false)
            ->orderBy('unit_id')
            ->first();

        if ($unit === null) {
            return [
                'unit' => null,
                'slots' => [],
                'occupancy' => ['occupied' => 0, 'capacity' => 0, 'pct' => null],
                'pipeline' => ['counts' => (object) [], 'declines' => (object) []],
                'projectedDischarges' => ['next24h' => 0, 'next48h' => 0],
            ];
        }

        $episodes = HomeEpisode::query()
            ->with([
                'program:home_program_id,code,name',
                'encounter:encounter_id,bed_id',
            ])
            ->active()
            ->get();

        $episodesByBed = $episodes
            ->filter(fn (HomeEpisode $e): bool => $e->encounter?->bed_id !== null)
            ->keyBy(fn (HomeEpisode $e) => $e->encounter->bed_id);

        $slots = Bed::where('unit_id', $unit->unit_id)
            ->where('is_deleted', false)
            ->orderBy('label')
            ->get()
            ->map(fn (Bed $bed): array => $this->slot($bed, $episodesByBed));

        $occupied = $slots->where('status', 'occupied')->count();
        $capacity = $slots->count();

        return [
            'unit' => [
                'name' => $unit->name,
                'abbreviation' => $unit->abbreviation,
                'slotCount' => $capacity,
            ],
            'slots' => $slots->values()->all(),
            'occupancy' => [
                'occupied' => $occupied,
                'capacity' => $capacity,
                'pct' => $capacity > 0 ? round(100 * $occupied / $capacity, 1) : null,
            ],
            'pipeline' => $this->pipeline(),
            'projectedDischarges' => $this->projectedDischarges($episodes),
        ];
    }

    /**
     * @param  Collection<int, HomeEpisode>  $episodesByBed
     * @return array<string, mixed>
     */
    private function slot(Bed $bed, Collection $episodesByBed): array
    {
        $status = $bed->status === 'dirty' ? 'pending_setup' : $bed->status;
        $episode = $episodesByBed->get($bed->bed_id);

        $payload = null;
        if ($status === 'occupied' && $episode !== null) {
            $dayOfStay = $episode->started_at !== null
                ? max(1, (int) $episode->started_at->diffInDays(now()) + 1)
                : null;

            $payload = [
                'patientRef' => $episode->patient_ref,
                'program' => $episode->program?->code,
                'conditionLabel' => $episode->condition_label ?? $episode->condition_code,
                'acuityTier' => $episode->acuity_tier,
                'serviceZone' => $episode->service_zone,
                'dayOfStay' => $dayOfStay,
                'targetLosDays' => $episode->target_los_days,
                'expectedDischargeDate' => $episode->expected_discharge_date?->toDateString(),
                'provenance' => data_get($episode->metadata, 'provenance'),
            ];
        }

        return [
            'bedId' => $bed->bed_id,
            'label' => $bed->label,
            'status' => $status,
            'episode' => $payload,
        ];
    }

    /** @return array<string, mixed> */
    private function pipeline(): array
    {
        $counts = HomeReferral::where('is_deleted', false)
            ->selectRaw('status, count(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $declines = HomeReferral::where('is_deleted', false)
            ->where('status', 'declined')
            ->whereNotNull('decline_reason')
            ->selectRaw('decline_reason, count(*) AS n')
            ->groupBy('decline_reason')
            ->pluck('n', 'decline_reason');

        return [
            'counts' => $counts->isEmpty() ? (object) [] : $counts,
            'declines' => $declines->isEmpty() ? (object) [] : $declines,
        ];
    }

    /**
     * @param  Collection<int, HomeEpisode>  $episodes
     * @return array{next24h: int, next48h: int}
     */
    private function projectedDischarges(Collection $episodes): array
    {
        $dates = $episodes->pluck('expected_discharge_date')->filter();

        return [
            'next24h' => $dates->filter(fn ($d) => $d->lte(now()->addDay()))->count(),
            'next48h' => $dates->filter(fn ($d) => $d->lte(now()->addDays(2)))->count(),
        ];
    }
}
