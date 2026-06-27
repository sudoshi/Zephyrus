<?php

declare(strict_types=1);

namespace App\Services\Ed;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the ED Resource Management (Operations) page payload from the live
 * `prod` schema.
 *
 * Surfaces the ED's three resource classes — physical rooms/beds, clinical
 * staffing, and equipment — sized against the current in-department census.
 * Bed inventory and the latest census snapshot come from prod.beds /
 * prod.census_snapshots; census/demand come from prod.ed_visits. Fields with no
 * source system (per-care-area room labels, equipment inventory, staffing rota)
 * are DETERMINISTICALLY enriched: every synthesized figure is a pure function of
 * the live census + a stable per-resource seed, never random, so the demo is
 * reproducible across requests.
 *
 * Deterministic and safe on empty tables (returns zeros / empty arrays, never
 * throws). Query idioms mirror App\Services\Dashboard\EdDashboardService.
 *
 * @phpstan-type ResourceRow array{
 *     id:string,
 *     name:string,
 *     category:string,
 *     icon:string,
 *     total:int,
 *     inUse:int,
 *     available:int,
 *     utilization:int,
 *     status:string
 * }
 */
class ResourceManagementService
{
    /** ED unit identifier (prod.units: "Emergency Department", type 'ed'). */
    private const ED_UNIT_ID = 1;

    /** Utilization thresholds (percent) that drive the status pairing. */
    private const UTIL_CRITICAL = 90;

    private const UTIL_WARNING = 75;

    /**
     * The five ED care areas, with their share of total staffed beds and an
     * icon. Shares sum to 1.0; the remainder lands in the last bucket. Mirrors
     * EdDashboardService::bedCategories so the two pages stay consistent.
     *
     * @var array<string,array{label:string,share:float,icon:string}>
     */
    private const CARE_AREAS = [
        'trauma' => ['label' => 'Trauma Bays', 'share' => 0.09, 'icon' => 'heroicons:bolt'],
        'acute' => ['label' => 'Acute Care', 'share' => 0.55, 'icon' => 'heroicons:heart'],
        'fastTrack' => ['label' => 'Fast Track', 'share' => 0.18, 'icon' => 'heroicons:forward'],
        'behavioral' => ['label' => 'Behavioral Health', 'share' => 0.09, 'icon' => 'heroicons:shield-check'],
        'isolation' => ['label' => 'Isolation Rooms', 'share' => 0.09, 'icon' => 'heroicons:lock-closed'],
    ];

    /**
     * Assemble the full Resource Management payload.
     *
     * @return array{
     *     summary:array<string,mixed>,
     *     resources:list<ResourceRow>,
     *     capacityChart:array{labels:list<string>,capacity:list<int>,demand:list<int>},
     *     staffing:array<string,mixed>,
     *     generatedAt:string
     * }
     */
    public function build(): array
    {
        $now = Carbon::now();

        $census = $this->edCensus();
        $active = $this->activeVisits($now);
        $beds = $this->bedInventory($census, $active);

        $roomRows = $this->roomResources($beds, $active);
        $equipmentRows = $this->equipmentResources($beds, $active);
        $staffing = $this->staffing($active);
        $staffRows = $this->staffingResources($staffing);

        $resources = array_merge($roomRows, $staffRows, $equipmentRows);

        return [
            'summary' => $this->summary($beds, $active, $staffing, $equipmentRows),
            'resources' => $resources,
            'capacityChart' => $this->capacityChart($beds, $staffing, $equipmentRows),
            'staffing' => $staffing,
            'generatedAt' => $now->toIso8601String(),
        ];
    }

    // -----------------------------------------------------------------------
    // Census + active cohort (idioms shared with EdDashboardService)
    // -----------------------------------------------------------------------

    /**
     * Latest census snapshot for the ED unit.
     *
     * @return array{staffed_beds:int,occupied:int,available:int,blocked:int}
     */
    private function edCensus(): array
    {
        $row = DB::table('prod.census_snapshots')
            ->where('unit_id', self::ED_UNIT_ID)
            ->orderByDesc('captured_at')
            ->first(['staffed_beds', 'occupied', 'available', 'blocked']);

        return [
            'staffed_beds' => (int) ($row->staffed_beds ?? 0),
            'occupied' => (int) ($row->occupied ?? 0),
            'available' => (int) ($row->available ?? 0),
            'blocked' => (int) ($row->blocked ?? 0),
        ];
    }

    /**
     * Counts for ED patients currently in the department (arrived, not yet
     * departed).
     *
     * @return array{count:int,waiting:int,treating:int,boarding:int,critical:int}
     */
    private function activeVisits(Carbon $now): array
    {
        $row = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->where('arrived_at', '<=', $now)
            ->whereNull('departed_at')
            ->selectRaw(
                'COUNT(*) AS cnt,
                 SUM(CASE WHEN provider_seen_at IS NULL THEN 1 ELSE 0 END) AS waiting,
                 SUM(CASE WHEN provider_seen_at IS NOT NULL THEN 1 ELSE 0 END) AS treating,
                 SUM(CASE WHEN disposition = ? AND bed_assigned_at IS NULL THEN 1 ELSE 0 END) AS boarding,
                 SUM(CASE WHEN esi_level <= 2 THEN 1 ELSE 0 END) AS critical',
                ['admitted']
            )
            ->first();

        return [
            'count' => (int) ($row->cnt ?? 0),
            'waiting' => (int) ($row->waiting ?? 0),
            'treating' => (int) ($row->treating ?? 0),
            'boarding' => (int) ($row->boarding ?? 0),
            'critical' => (int) ($row->critical ?? 0),
        ];
    }

    // -----------------------------------------------------------------------
    // Bed inventory (staffed view from census, physical mix from prod.beds)
    // -----------------------------------------------------------------------

    /**
     * Reconcile the census snapshot (staffed headline view) with the physical
     * prod.beds status mix. Prefer the snapshot for the headline numbers; fall
     * back to the physical table when no snapshot exists.
     *
     * @param  array{staffed_beds:int,occupied:int,available:int,blocked:int}  $census
     * @param  array{count:int}  $active
     * @return array{total:int,occupied:int,available:int,cleaning:int,blocked:int}
     */
    private function bedInventory(array $census, array $active): array
    {
        $bedRows = DB::table('prod.beds')
            ->where('unit_id', self::ED_UNIT_ID)
            ->where('is_deleted', false)
            ->selectRaw('status, COUNT(*) AS cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        $physicalTotal = (int) array_sum($bedRows);
        $physOccupied = (int) ($bedRows['occupied'] ?? 0);
        $cleaning = (int) ($bedRows['dirty'] ?? 0);
        $blockedBeds = (int) ($bedRows['blocked'] ?? 0);
        $availableBeds = (int) ($bedRows['available'] ?? 0);

        // Headline total: staffed beds from the snapshot, else physical count,
        // else at least the live census so utilization never divides by zero.
        $total = $census['staffed_beds'] > 0
            ? $census['staffed_beds']
            : max($physicalTotal, $active['count'], 1);

        $occupied = $census['occupied'] > 0 ? $census['occupied'] : $physOccupied;
        $blocked = $census['blocked'] > 0 ? $census['blocked'] : $blockedBeds;

        $available = $census['available'] > 0
            ? $census['available']
            : max(0, $total - $occupied - $cleaning - $blocked);
        if ($available === 0 && $availableBeds > 0) {
            $available = $availableBeds;
        }

        return [
            'total' => $total,
            'occupied' => min($occupied, $total),
            'available' => min($available, $total),
            'cleaning' => min($cleaning, $total),
            'blocked' => min($blocked, $total),
        ];
    }

    // -----------------------------------------------------------------------
    // Room / care-area resources
    // -----------------------------------------------------------------------

    /**
     * One resource row per ED care area. Each area's bed count is a deterministic
     * share of the staffed total; occupancy is distributed proportionally to the
     * live ED occupancy so the rows always reconcile to the headline figures.
     *
     * @param  array{total:int,occupied:int,available:int,cleaning:int,blocked:int}  $beds
     * @param  array{count:int}  $active
     * @return list<ResourceRow>
     */
    private function roomResources(array $beds, array $active): array
    {
        $total = $beds['total'];
        $occupied = max($beds['occupied'], $active['count']);
        $occupied = min($occupied, $total);

        // Split total beds across the care areas (remainder to the last bucket).
        $keys = array_keys(self::CARE_AREAS);
        $areaTotals = [];
        $assigned = 0;
        foreach ($keys as $i => $key) {
            if ($i === count($keys) - 1) {
                $areaTotals[$key] = max(0, $total - $assigned);
            } else {
                $areaTotals[$key] = (int) round($total * self::CARE_AREAS[$key]['share']);
                $assigned += $areaTotals[$key];
            }
        }

        $rows = [];
        $occRemaining = $occupied;
        foreach ($keys as $i => $key) {
            $cap = $areaTotals[$key];
            // Proportional occupancy, with the remainder absorbed by the last area.
            if ($i === count($keys) - 1) {
                $inUse = max(0, min($cap, $occRemaining));
            } else {
                $inUse = $total > 0 ? min($cap, (int) round($occupied * $cap / $total)) : 0;
                $occRemaining = max(0, $occRemaining - $inUse);
            }

            $meta = self::CARE_AREAS[$key];
            $rows[] = $this->resourceRow(
                id: 'room-'.$key,
                name: $meta['label'],
                category: 'Rooms',
                icon: $meta['icon'],
                total: $cap,
                inUse: $inUse,
            );
        }

        return $rows;
    }

    // -----------------------------------------------------------------------
    // Equipment resources (inventory scaled to occupancy + acuity)
    // -----------------------------------------------------------------------

    /**
     * Deterministic equipment inventory sized off the staffed-bed total and the
     * live acuity/occupancy load. Mirrors EdDashboardService::resources so both
     * surfaces agree on equipment counts.
     *
     * @param  array{total:int,occupied:int}  $beds
     * @param  array{count:int,critical:int}  $active
     * @return list<ResourceRow>
     */
    private function equipmentResources(array $beds, array $active): array
    {
        $total = max(1, $beds['total']);
        $census = max($beds['occupied'], $active['count']);

        $monitorsTotal = $total;
        $monitorsInUse = min($monitorsTotal, $census);

        $ventTotal = max(4, (int) ceil($total / 8));
        $ventInUse = min($ventTotal, (int) round($active['critical'] * 0.6));

        $infusionTotal = max(6, (int) ceil($total * 0.75));
        $infusionInUse = min($infusionTotal, (int) round($census * 0.7));

        $xrayTotal = 2;
        $xrayInUse = $census > $total * 0.6 ? 1 : 0;

        return [
            $this->resourceRow('eq-monitors', 'Cardiac Monitors', 'Equipment', 'heroicons:signal', $monitorsTotal, $monitorsInUse),
            $this->resourceRow('eq-ventilators', 'Ventilators', 'Equipment', 'heroicons:bolt', $ventTotal, $ventInUse),
            $this->resourceRow('eq-infusion', 'Infusion Pumps', 'Equipment', 'heroicons:beaker', $infusionTotal, $infusionInUse),
            $this->resourceRow('eq-xray', 'Portable X-Ray', 'Equipment', 'heroicons:camera', $xrayTotal, $xrayInUse),
        ];
    }

    // -----------------------------------------------------------------------
    // Staffing (census-driven demand model; no ED staffing rows are seeded)
    // -----------------------------------------------------------------------

    /**
     * Deterministic staffing model anchored to the active census.
     * Physicians ~1:9, nurses ~1:3, techs ~1:6 (ED rules of thumb). Mirrors
     * EdDashboardService::staffing so the dashboards agree.
     *
     * @param  array{count:int}  $active
     * @return array{
     *     physicians:array{scheduled:int,present:int,required:int},
     *     nurses:array{scheduled:int,present:int,required:int},
     *     techs:array{scheduled:int,present:int,required:int}
     * }
     */
    private function staffing(array $active): array
    {
        $census = max(1, (int) $active['count']);

        $phys = max(2, (int) ceil($census / 9));
        $nurses = max(4, (int) ceil($census / 3));
        $techs = max(2, (int) ceil($census / 6));

        // Present can lag scheduled by at most one (deterministic, never random).
        return [
            'physicians' => ['scheduled' => $phys, 'present' => $phys, 'required' => $phys],
            'nurses' => ['scheduled' => $nurses, 'present' => max(0, $nurses - ($census % 2)), 'required' => $nurses],
            'techs' => ['scheduled' => $techs, 'present' => max(0, $techs - (($census + 1) % 2)), 'required' => $techs],
        ];
    }

    /**
     * Render the staffing model as resource rows. "In use" is the present count
     * working against the required (demand) total, so utilization reads as
     * coverage pressure (>=100% = understaffed against demand).
     *
     * @param  array<string,array{scheduled:int,present:int,required:int}>  $staffing
     * @return list<ResourceRow>
     */
    private function staffingResources(array $staffing): array
    {
        $labels = [
            'physicians' => ['Physicians', 'heroicons:user'],
            'nurses' => ['Nurses', 'heroicons:user-group'],
            'techs' => ['Technicians', 'heroicons:wrench-screwdriver'],
        ];

        $rows = [];
        foreach ($staffing as $key => $role) {
            [$label, $icon] = $labels[$key] ?? [ucfirst($key), 'heroicons:user'];
            // Capacity = present staff; demand = required. Utilization > 100 is
            // clamped by resourceRow for display but flagged via status.
            $rows[] = $this->resourceRow(
                id: 'staff-'.$key,
                name: $label,
                category: 'Staffing',
                icon: $icon,
                total: max($role['present'], $role['required'], 1),
                inUse: $role['required'],
            );
        }

        return $rows;
    }

    // -----------------------------------------------------------------------
    // Summary KPIs
    // -----------------------------------------------------------------------

    /**
     * @param  array{total:int,occupied:int,available:int,cleaning:int,blocked:int}  $beds
     * @param  array{count:int,boarding:int,critical:int}  $active
     * @param  array<string,array{scheduled:int,present:int,required:int}>  $staffing
     * @param  list<ResourceRow>  $equipment
     * @return array<string,mixed>
     */
    private function summary(array $beds, array $active, array $staffing, array $equipment): array
    {
        $total = max(1, $beds['total']);
        $occupied = min($beds['occupied'], $total);
        $occupancy = (int) round($occupied / $total * 100);

        $nursePresent = (int) $staffing['nurses']['present'];
        $nurseRequired = max(1, (int) $staffing['nurses']['required']);
        $nurseCoverage = (int) round($nursePresent / $nurseRequired * 100);

        $eqTotal = array_sum(array_column($equipment, 'total'));
        $eqInUse = array_sum(array_column($equipment, 'inUse'));
        $eqUtil = $eqTotal > 0 ? (int) round($eqInUse / $eqTotal * 100) : 0;

        return [
            'occupancy' => [
                'value' => $occupancy,
                'occupied' => $occupied,
                'total' => $beds['total'],
                'status' => $this->thresholdStatus($occupancy),
            ],
            'availableBeds' => [
                'value' => $beds['available'],
                'cleaning' => $beds['cleaning'],
                'blocked' => $beds['blocked'],
                // Fewer free beds is worse: invert for the threshold read.
                'status' => $beds['available'] <= 2 ? 'critical' : ($beds['available'] <= 5 ? 'warning' : 'success'),
            ],
            'nurseCoverage' => [
                'value' => $nurseCoverage,
                'present' => $nursePresent,
                'required' => $staffing['nurses']['required'],
                // Coverage below demand is worse.
                'status' => $nurseCoverage >= 100 ? 'success' : ($nurseCoverage >= 85 ? 'warning' : 'critical'),
            ],
            'equipmentUtilization' => [
                'value' => $eqUtil,
                'inUse' => $eqInUse,
                'total' => $eqTotal,
                'status' => $this->thresholdStatus($eqUtil),
            ],
            'boarding' => [
                'value' => $active['boarding'],
                'status' => $active['boarding'] >= 3 ? 'critical' : ($active['boarding'] >= 1 ? 'warning' : 'success'),
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Capacity vs demand chart series
    // -----------------------------------------------------------------------

    /**
     * Grouped bar series comparing capacity against current demand per resource
     * class, for @/Components/Dashboard/Charts/BarChart.
     *
     * @param  array{total:int,occupied:int}  $beds
     * @param  array<string,array{scheduled:int,present:int,required:int}>  $staffing
     * @param  list<ResourceRow>  $equipment
     * @return array{labels:list<string>,capacity:list<int>,demand:list<int>}
     */
    private function capacityChart(array $beds, array $staffing, array $equipment): array
    {
        $eqTotal = (int) array_sum(array_column($equipment, 'total'));
        $eqInUse = (int) array_sum(array_column($equipment, 'inUse'));

        $labels = ['Beds', 'Physicians', 'Nurses', 'Techs', 'Equipment'];
        $capacity = [
            $beds['total'],
            (int) $staffing['physicians']['present'],
            (int) $staffing['nurses']['present'],
            (int) $staffing['techs']['present'],
            $eqTotal,
        ];
        $demand = [
            min($beds['occupied'], $beds['total']),
            (int) $staffing['physicians']['required'],
            (int) $staffing['nurses']['required'],
            (int) $staffing['techs']['required'],
            $eqInUse,
        ];

        return [
            'labels' => $labels,
            'capacity' => $capacity,
            'demand' => $demand,
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build one normalized resource row with utilization + status pairing.
     *
     * @return ResourceRow
     */
    private function resourceRow(
        string $id,
        string $name,
        string $category,
        string $icon,
        int $total,
        int $inUse,
    ): array {
        $total = max(0, $total);
        $inUse = max(0, $inUse);
        $available = max(0, $total - $inUse);
        $utilization = $total > 0 ? (int) round($inUse / $total * 100) : 0;

        return [
            'id' => $id,
            'name' => $name,
            'category' => $category,
            'icon' => $icon,
            'total' => $total,
            'inUse' => $inUse,
            'available' => $available,
            'utilization' => $utilization,
            'status' => $this->thresholdStatus($utilization),
        ];
    }

    /**
     * Map a utilization percentage onto a healthcare status token name.
     * (Color is applied client-side and is always paired with a label/icon.)
     */
    private function thresholdStatus(int $utilization): string
    {
        if ($utilization >= self::UTIL_CRITICAL) {
            return 'critical';
        }
        if ($utilization >= self::UTIL_WARNING) {
            return 'warning';
        }

        return 'success';
    }
}
