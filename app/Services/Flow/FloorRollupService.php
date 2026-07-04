<?php

namespace App\Services\Flow;

use App\Models\Barrier;
use App\Models\Evs\EvsRequest;
use App\Models\Staffing\StaffingPlan;
use App\Models\Transport\TransportRequest;
use App\Models\Unit;
use App\Support\Hospital\HospitalManifest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Floors as the spatial join between the hospital manifest (floor / cad_code /
 * service_line per unit), hosp_space.facility_spaces (floor plates), and the
 * live operational spine (prod.units → prod.beds) — FLOW-WINDOW-PLAN §6.1 (W1).
 *
 * The manifest is the floor authority; facility_spaces.floor_number is used
 * as a cross-check bridge for the plate geometry, never as the source of
 * truth for which units live on a floor.
 */
class FloorRollupService
{
    public function __construct(private readonly HospitalManifest $manifest) {}

    /**
     * All floors with their units and live rollups, ordered by floor number.
     *
     * @return list<array<string, mixed>>
     */
    public function floors(): array
    {
        $units = Unit::with('beds')->where('is_deleted', false)->get()
            ->keyBy(fn (Unit $unit): string => strtoupper((string) $unit->abbreviation));

        $evsOpen = $this->openCountsByUnit();
        $transportActive = $this->activeTransportCountsByUnit($units);
        $barriersOpen = $this->openBarrierCountsByUnit();
        $staffing = $this->staffingStatusByUnit();

        $floors = [];
        foreach ($this->manifest->units() as $entry) {
            $floor = (int) ($entry['floor'] ?? 0);
            $unit = $units->get(strtoupper((string) ($entry['abbr'] ?? '')));

            $floors[$floor]['floor'] = $floor;
            $floors[$floor]['label'] = "Floor {$floor}";
            $floors[$floor]['units'][] = $this->unitRollup($entry, $unit, $evsOpen, $transportActive, $barriersOpen, $staffing);
        }

        ksort($floors);

        return array_values(array_map(function (array $floorRow): array {
            $units = collect($floorRow['units']);

            return $floorRow + [
                'staffed' => (int) $units->sum('staffed'),
                'occupied' => (int) $units->sum('occupied'),
                'available' => (int) $units->sum('available'),
                'blocked' => (int) $units->sum('blocked'),
                'occupancy_pct' => ($staffed = (int) $units->sum('staffed')) > 0
                    ? (int) round(100 * $units->sum('occupied') / $staffed)
                    : 0,
                'evs_open' => (int) $units->sum('evs_open'),
                'transport_active' => (int) $units->sum('transport_active'),
                'barriers_open' => (int) $units->sum('barriers_open'),
            ];
        }, $floors));
    }

    /** @return array<string, mixed> */
    private function unitRollup(array $entry, ?Unit $unit, Collection $evsOpen, Collection $transportActive, Collection $barriersOpen, Collection $staffing): array
    {
        $beds = $unit?->beds?->where('is_deleted', false) ?? collect();
        $occupied = $beds->where('status', 'occupied')->count();
        $available = $beds->where('status', 'available')->count();
        $blocked = $beds->whereIn('status', ['blocked', 'dirty'])->count();
        $staffed = (int) ($unit->staffed_bed_count ?? 0);
        $unitId = $unit?->unit_id !== null ? (int) $unit->unit_id : null;

        return [
            'unit_id' => $unitId,
            'abbr' => $entry['abbr'] ?? null,
            'name' => $entry['name'] ?? $unit?->name,
            'type' => $entry['type'] ?? $unit?->type,
            'service_line' => $entry['service_line'] ?? null,
            'acuity' => $entry['acuity'] ?? null,
            'cad_code' => $entry['cad_code'] ?? null,
            'facility_space_id' => $unit?->facility_space_id !== null ? (int) $unit->facility_space_id : null,
            'staffed' => $staffed,
            'occupied' => $occupied,
            'available' => $available,
            'blocked' => $blocked,
            'occupancy_pct' => $staffed > 0 ? (int) round(100 * $occupied / $staffed) : 0,
            'evs_open' => $unitId !== null ? (int) ($evsOpen[$unitId] ?? 0) : 0,
            'transport_active' => $unitId !== null ? (int) ($transportActive[$unitId] ?? 0) : 0,
            'barriers_open' => $unitId !== null ? (int) ($barriersOpen[$unitId] ?? 0) : 0,
            'staffing_status' => $unitId !== null ? ($staffing[$unitId] ?? null) : null,
        ];
    }

    /** @return Collection<int, int> open EVS turns keyed by unit_id */
    private function openCountsByUnit(): Collection
    {
        return EvsRequest::query()
            ->whereIn('status', ['requested', 'queued', 'assigned', 'in_progress'])
            ->where('is_deleted', false)
            ->whereNotNull('unit_id')
            ->selectRaw('unit_id, COUNT(*) AS n')
            ->groupBy('unit_id')
            ->pluck('n', 'unit_id');
    }

    /**
     * Active transport trips touching a unit. Transport requests carry
     * origin/destination labels, not unit ids — match by unit name/abbr.
     *
     * @param  Collection<string, Unit>  $unitsByAbbr
     * @return Collection<int, int>
     */
    private function activeTransportCountsByUnit(Collection $unitsByAbbr): Collection
    {
        $active = TransportRequest::query()
            ->whereNotIn('status', ['completed', 'canceled', 'failed'])
            ->where('is_deleted', false)
            ->get(['origin', 'destination']);

        $labels = $unitsByAbbr->values()->flatMap(fn (Unit $unit): array => array_filter([
            $unit->abbreviation !== null ? [strtoupper((string) $unit->abbreviation), (int) $unit->unit_id] : null,
            $unit->name !== null ? [strtoupper((string) $unit->name), (int) $unit->unit_id] : null,
        ]))->mapWithKeys(fn (array $pair): array => [$pair[0] => $pair[1]]);

        $counts = [];
        foreach ($active as $trip) {
            foreach ([strtoupper((string) $trip->origin), strtoupper((string) $trip->destination)] as $endpoint) {
                if (isset($labels[$endpoint])) {
                    $counts[$labels[$endpoint]] = ($counts[$labels[$endpoint]] ?? 0) + 1;
                }
            }
        }

        return collect($counts);
    }

    /** @return Collection<int, int> open barriers keyed by unit_id */
    private function openBarrierCountsByUnit(): Collection
    {
        return Barrier::query()
            ->open()
            ->whereNotNull('unit_id')
            ->selectRaw('unit_id, COUNT(*) AS n')
            ->groupBy('unit_id')
            ->pluck('n', 'unit_id');
    }

    /** @return Collection<int, string> today's worst staffing-plan status keyed by unit_id */
    private function staffingStatusByUnit(): Collection
    {
        if (! Schema::hasTable('prod.staffing_plans')) {
            return collect();
        }

        $severity = ['critical_gap' => 4, 'gap' => 3, 'watch' => 2, 'balanced' => 1, 'surplus' => 1];

        return StaffingPlan::query()
            ->whereDate('shift_date', now()->toDateString())
            ->where('is_deleted', false)
            ->whereNotNull('unit_id')
            ->get(['unit_id', 'status'])
            ->groupBy('unit_id')
            ->map(fn (Collection $plans): string => $plans
                ->sortByDesc(fn (StaffingPlan $plan): int => $severity[$plan->status] ?? 0)
                ->first()
                ->status);
    }
}
