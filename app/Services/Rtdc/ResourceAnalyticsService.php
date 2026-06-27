<?php

namespace App\Services\Rtdc;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the RTDC Resource Analytics page payload from the live `prod` schema
 * (DB_SCHEMA=prod).
 *
 * The page (resources/js/Pages/RTDC/Analytics/Resources.jsx) renders a staffing-
 * and bed-allocation resourcing view: KPI tiles, a staffing-by-unit bar chart
 * (required vs present), and a per-unit resource gap table that surfaces staffing
 * shortfalls, RN-ratio breaches, and bed availability.
 *
 * Data sources:
 *   - prod.staffing_plans (today, day shift): per-unit / per-role required,
 *     scheduled, actual headcount + census + ratio_target. Authoritative for
 *     staffing ratios and gaps (covers ~28 units).
 *   - prod.staffing_requests (open): outstanding fill-gap requests.
 *   - prod.beds: physical bed allocation/availability per unit (covers all
 *     units; `dirty` = turnover).
 *   - prod.census_snapshots (latest per unit): blocked-bed count.
 *
 * The "staffing ratio" is patients-per-RN: census / actual RN count, compared to
 * the unit's `ratio_target` (lower is better; a higher actual ratio than target
 * means the unit is stretched). Computation is deterministic and safe on empty
 * tables: every accessor returns zeros / empty collections rather than throwing.
 *
 * Returned shape:
 *   kpis: array<string,int|float>      // headline resourcing metrics
 *   staffingByUnit: list<object>       // bar-chart series + table rows
 *   gaps: list<object>                 // ranked resource-gap table rows
 *   openRequests: list<object>         // outstanding staffing requests
 *   generatedAt: string               // ISO timestamp for the "as of" stamp
 */
class ResourceAnalyticsService
{
    /** Human-readable label per unit `type`. */
    private const TYPE_LABELS = [
        'ed' => 'Emergency',
        'icu' => 'ICU',
        'step_down' => 'Step-down',
        'med_surg' => 'Med/Surg',
        'or' => 'Operating Room',
    ];

    /**
     * Full Resource Analytics payload.
     *
     * @return array{
     *     kpis: array<string,int|float>,
     *     staffingByUnit: list<array<string,mixed>>,
     *     gaps: list<array<string,mixed>>,
     *     openRequests: list<array<string,mixed>>,
     *     generatedAt: string
     * }
     */
    public function build(): array
    {
        $staffingByUnit = $this->staffingByUnit();
        $bedsByUnit = $this->bedAllocationByUnit();
        $blockedByUnit = $this->blockedByUnit();
        $openRequests = $this->openStaffingRequests();

        $units = $this->mergeUnitResources($staffingByUnit, $bedsByUnit, $blockedByUnit, count($openRequests));

        return [
            'kpis' => $this->kpis($units, $openRequests),
            'staffingByUnit' => $units,
            'gaps' => $this->gapRows($units),
            'openRequests' => $openRequests,
            'generatedAt' => Carbon::now()->toIso8601String(),
        ];
    }

    // -----------------------------------------------------------------------
    // Source queries
    // -----------------------------------------------------------------------

    /**
     * Today's day-shift staffing plans aggregated per unit, with per-role
     * counts and the RN row carried separately for ratio computation.
     *
     * @return array<int,array<string,mixed>>
     */
    private function staffingByUnit(): array
    {
        $rows = DB::table('prod.staffing_plans AS sp')
            ->join('prod.units AS u', 'u.unit_id', '=', 'sp.unit_id')
            ->where('sp.is_deleted', false)
            ->where('u.is_deleted', false)
            ->whereDate('sp.shift_date', Carbon::today())
            ->where('sp.shift', 'day')
            ->get([
                'sp.unit_id', 'sp.role', 'sp.required_count', 'sp.scheduled_count',
                'sp.actual_count', 'sp.minimum_safe_count', 'sp.census',
                'sp.ratio_target', 'sp.status',
                'u.name AS unit_name', 'u.abbreviation', 'u.type AS unit_type',
            ]);

        $byUnit = [];
        foreach ($rows as $row) {
            $unitId = (int) $row->unit_id;
            $byUnit[$unitId] ??= [
                'unit_id' => $unitId,
                'unit_name' => (string) $row->unit_name,
                'abbreviation' => (string) ($row->abbreviation ?: $row->unit_name),
                'type' => (string) $row->unit_type,
                'required' => 0,
                'scheduled' => 0,
                'present' => 0,
                'minimum_safe' => 0,
                'census' => (int) $row->census,
                'rn_required' => 0,
                'rn_present' => 0,
                'ratio_target' => 0.0,
                'status' => 'balanced',
            ];

            $byUnit[$unitId]['required'] += (int) $row->required_count;
            $byUnit[$unitId]['scheduled'] += (int) $row->scheduled_count;
            $byUnit[$unitId]['present'] += (int) $row->actual_count;
            $byUnit[$unitId]['minimum_safe'] += (int) $row->minimum_safe_count;
            // Census is recorded per-row identically; keep the max as the unit census.
            $byUnit[$unitId]['census'] = max($byUnit[$unitId]['census'], (int) $row->census);

            if (strtolower((string) $row->role) === 'rn') {
                $byUnit[$unitId]['rn_required'] += (int) $row->required_count;
                $byUnit[$unitId]['rn_present'] += (int) $row->actual_count;
                $byUnit[$unitId]['ratio_target'] = (float) $row->ratio_target;
            }

            if (in_array($row->status, ['critical_gap', 'gap'], true)) {
                // Escalate to the most severe status seen on any role row.
                if ($row->status === 'critical_gap' || $byUnit[$unitId]['status'] !== 'critical_gap') {
                    $byUnit[$unitId]['status'] = (string) $row->status;
                }
            }
        }

        return $byUnit;
    }

    /**
     * Physical bed allocation per non-deleted unit, from prod.beds.
     * `dirty` is surfaced as the turnover (cleaning) bucket.
     *
     * @return array<int,array{total:int,occupied:int,available:int,turnover:int}>
     */
    private function bedAllocationByUnit(): array
    {
        $rows = DB::select(
            "SELECT
                u.unit_id,
                COUNT(b.bed_id) AS total,
                COUNT(b.bed_id) FILTER (WHERE b.status = 'occupied') AS occupied,
                COUNT(b.bed_id) FILTER (WHERE b.status = 'available') AS available,
                COUNT(b.bed_id) FILTER (WHERE b.status = 'dirty') AS turnover
             FROM prod.units u
             JOIN prod.beds b ON b.unit_id = u.unit_id AND b.is_deleted = false
             WHERE u.is_deleted = false
             GROUP BY u.unit_id"
        );

        $byUnit = [];
        foreach ($rows as $row) {
            $byUnit[(int) $row->unit_id] = [
                'total' => (int) $row->total,
                'occupied' => (int) $row->occupied,
                'available' => (int) $row->available,
                'turnover' => (int) $row->turnover,
            ];
        }

        return $byUnit;
    }

    /**
     * Latest-census blocked-bed count per unit (DISTINCT ON captured_at).
     *
     * @return array<int,int>
     */
    private function blockedByUnit(): array
    {
        $rows = DB::select(
            'SELECT DISTINCT ON (cs.unit_id) cs.unit_id, cs.blocked
             FROM prod.census_snapshots cs
             JOIN prod.units u ON u.unit_id = cs.unit_id
             WHERE u.is_deleted = false
             ORDER BY cs.unit_id, cs.captured_at DESC'
        );

        $byUnit = [];
        foreach ($rows as $row) {
            $byUnit[(int) $row->unit_id] = (int) $row->blocked;
        }

        return $byUnit;
    }

    /**
     * Outstanding staffing requests (not yet filled/cancelled), ranked by
     * priority then recency.
     *
     * @return list<array<string,mixed>>
     */
    private function openStaffingRequests(): array
    {
        $rows = DB::table('prod.staffing_requests AS sr')
            ->leftJoin('prod.units AS u', 'u.unit_id', '=', 'sr.unit_id')
            ->where('sr.is_deleted', false)
            ->whereIn('sr.status', ['requested', 'open', 'sourcing', 'escalated', 'unfilled'])
            ->orderByRaw($this->priorityOrderSql())
            ->orderByDesc('sr.created_at')
            ->limit(25)
            ->get([
                'sr.staffing_request_id', 'sr.role', 'sr.status', 'sr.priority',
                'sr.request_type', 'sr.headcount_needed', 'sr.shift', 'sr.shift_date',
                'sr.needed_by', 'sr.owner_name', 'sr.unit_label',
                'u.abbreviation', 'u.name AS unit_name',
            ]);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row->staffing_request_id,
                'unit' => (string) ($row->abbreviation ?: $row->unit_name ?: $row->unit_label ?: 'House'),
                'role' => strtoupper((string) $row->role),
                'priority' => (string) ($row->priority ?? 'routine'),
                'requestType' => (string) ($row->request_type ?? 'fill_gap'),
                'headcount' => (int) $row->headcount_needed,
                'shift' => ucfirst((string) ($row->shift ?? '')),
                'shiftDate' => $row->shift_date ? Carbon::parse($row->shift_date)->format('M j') : '',
                'neededBy' => $row->needed_by ? Carbon::parse($row->needed_by)->format('M j, H:i') : null,
                'owner' => (string) ($row->owner_name ?? 'Staffing office'),
                'status' => (string) $row->status,
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Derived per-unit resource model
    // -----------------------------------------------------------------------

    /**
     * Merge staffing + bed allocation into one per-unit resource record, sorted
     * worst-coverage-first so the chart and table lead with the units at risk.
     *
     * @param  array<int,array<string,mixed>>  $staffingByUnit
     * @param  array<int,array<string,int>>  $bedsByUnit
     * @param  array<int,int>  $blockedByUnit
     * @return list<array<string,mixed>>
     */
    private function mergeUnitResources(
        array $staffingByUnit,
        array $bedsByUnit,
        array $blockedByUnit,
        int $openRequestCount,
    ): array {
        $units = [];

        foreach ($staffingByUnit as $unitId => $plan) {
            $required = (int) $plan['required'];
            $present = (int) $plan['present'];
            $scheduled = (int) $plan['scheduled'];
            $minimumSafe = (int) $plan['minimum_safe'];
            $gap = $present - $required;

            $coverage = $required > 0 ? (int) round($present / $required * 100) : 100;

            $census = (int) $plan['census'];
            $rnPresent = (int) $plan['rn_present'];
            $ratioTarget = (float) $plan['ratio_target'];
            // Patients-per-RN: higher than target = stretched.
            $actualRatio = $rnPresent > 0 ? round($census / $rnPresent, 1) : 0.0;
            $ratioBreach = $ratioTarget > 0 && $actualRatio > $ratioTarget;

            $beds = $bedsByUnit[$unitId] ?? ['total' => 0, 'occupied' => 0, 'available' => 0, 'turnover' => 0];
            $blocked = (int) ($blockedByUnit[$unitId] ?? 0);
            $bedTotal = (int) $beds['total'];
            $bedOccupancy = $bedTotal > 0 ? (int) round((int) $beds['occupied'] / $bedTotal * 100) : 0;

            $type = (string) $plan['type'];

            $units[] = [
                'unitId' => $unitId,
                'unit' => (string) $plan['abbreviation'],
                'unitName' => (string) $plan['unit_name'],
                'type' => self::TYPE_LABELS[$type] ?? ucfirst(str_replace('_', '-', $type)),
                'rawType' => $type,
                // Staffing
                'required' => $required,
                'scheduled' => $scheduled,
                'present' => $present,
                'minimumSafe' => $minimumSafe,
                'gap' => $gap,
                'coverage' => $coverage,
                // RN ratio
                'census' => $census,
                'rnPresent' => $rnPresent,
                'ratioTarget' => $ratioTarget,
                'actualRatio' => $actualRatio,
                'ratioBreach' => $ratioBreach,
                // Beds
                'bedsTotal' => $bedTotal,
                'bedsOccupied' => (int) $beds['occupied'],
                'bedsAvailable' => (int) $beds['available'],
                'bedsTurnover' => (int) $beds['turnover'],
                'bedsBlocked' => $blocked,
                'bedOccupancy' => $bedOccupancy,
                // Status
                'status' => $this->resourceStatus($coverage, (string) $plan['status'], $ratioBreach),
            ];
        }

        // Worst coverage first; ratio breaches bubble up within a coverage tier.
        usort($units, function (array $a, array $b): int {
            $byCoverage = $a['coverage'] <=> $b['coverage'];
            if ($byCoverage !== 0) {
                return $byCoverage;
            }

            return ($b['ratioBreach'] ? 1 : 0) <=> ($a['ratioBreach'] ? 1 : 0);
        });

        return $units;
    }

    // -----------------------------------------------------------------------
    // KPIs
    // -----------------------------------------------------------------------

    /**
     * @param  list<array<string,mixed>>  $units
     * @param  list<array<string,mixed>>  $openRequests
     * @return array<string,int|float>
     */
    private function kpis(array $units, array $openRequests): array
    {
        $required = 0;
        $present = 0;
        $unitsShort = 0;
        $ratioBreaches = 0;
        $bedsAvailable = 0;
        $bedsTotal = 0;
        $bedsOccupied = 0;

        foreach ($units as $u) {
            $required += (int) $u['required'];
            $present += (int) $u['present'];
            if ((int) $u['gap'] < 0) {
                $unitsShort++;
            }
            if ($u['ratioBreach']) {
                $ratioBreaches++;
            }
            $bedsAvailable += (int) $u['bedsAvailable'];
            $bedsTotal += (int) $u['bedsTotal'];
            $bedsOccupied += (int) $u['bedsOccupied'];
        }

        $coverage = $required > 0 ? (int) round($present / $required * 100) : 100;
        $bedOccupancy = $bedsTotal > 0 ? (int) round($bedsOccupied / $bedsTotal * 100) : 0;

        return [
            'staffCoverage' => $coverage,
            'staffRequired' => $required,
            'staffPresent' => $present,
            'staffGap' => $present - $required,
            'unitsShort' => $unitsShort,
            'ratioBreaches' => $ratioBreaches,
            'openRequests' => count($openRequests),
            'bedsAvailable' => $bedsAvailable,
            'bedOccupancy' => $bedOccupancy,
            'unitsTracked' => count($units),
        ];
    }

    // -----------------------------------------------------------------------
    // Gap table
    // -----------------------------------------------------------------------

    /**
     * Units with a resource shortfall (staffing gap, ratio breach, or no
     * available beds), already sorted worst-first by mergeUnitResources().
     *
     * @param  list<array<string,mixed>>  $units
     * @return list<array<string,mixed>>
     */
    private function gapRows(array $units): array
    {
        $gaps = [];
        foreach ($units as $u) {
            $hasStaffingGap = (int) $u['gap'] < 0;
            $ratioBreach = (bool) $u['ratioBreach'];
            $noBeds = (int) $u['bedsTotal'] > 0 && (int) $u['bedsAvailable'] === 0;

            if (! $hasStaffingGap && ! $ratioBreach && ! $noBeds) {
                continue;
            }

            $gaps[] = [
                'unitId' => $u['unitId'],
                'unit' => $u['unit'],
                'unitName' => $u['unitName'],
                'type' => $u['type'],
                'required' => $u['required'],
                'present' => $u['present'],
                'gap' => $u['gap'],
                'coverage' => $u['coverage'],
                'actualRatio' => $u['actualRatio'],
                'ratioTarget' => $u['ratioTarget'],
                'ratioBreach' => $ratioBreach,
                'bedsAvailable' => $u['bedsAvailable'],
                'bedsBlocked' => $u['bedsBlocked'],
                'status' => $u['status'],
                'driver' => $this->gapDriver($hasStaffingGap, $ratioBreach, $noBeds),
            ];
        }

        return $gaps;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function resourceStatus(int $coverage, string $planStatus, bool $ratioBreach): string
    {
        if ($planStatus === 'critical_gap' || $coverage < 85) {
            return 'critical';
        }
        if ($planStatus === 'gap' || $coverage < 95 || $ratioBreach) {
            return 'warning';
        }

        return 'success';
    }

    private function gapDriver(bool $hasStaffingGap, bool $ratioBreach, bool $noBeds): string
    {
        $drivers = [];
        if ($hasStaffingGap) {
            $drivers[] = 'Staffing shortfall';
        }
        if ($ratioBreach) {
            $drivers[] = 'RN ratio breach';
        }
        if ($noBeds) {
            $drivers[] = 'No open beds';
        }

        return $drivers === [] ? 'Balanced' : implode(' · ', $drivers);
    }

    /** Priority ordering for staffing requests (stat → urgent → routine). */
    private function priorityOrderSql(): string
    {
        return "CASE LOWER(sr.priority)
            WHEN 'stat' THEN 0
            WHEN 'critical' THEN 1
            WHEN 'urgent' THEN 2
            WHEN 'high' THEN 3
            ELSE 4 END ASC";
    }
}
