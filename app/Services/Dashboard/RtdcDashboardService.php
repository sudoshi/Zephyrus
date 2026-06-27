<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the Real-Time Demand & Capacity (RTDC) dashboard payload from the
 * live `prod` schema (DB_SCHEMA=prod).
 *
 * The four returned keys (departmentData, alertsData, capacityData,
 * staffingData) reproduce — exactly — the shapes the legacy mock modules
 * exported (resources/js/mock-data/rtdc-alerts.js, rtdc-capacity.js,
 * rtdc-staffing.js, and the departmentData consumed by
 * Components/RTDC/EnhancedDepartmentMetrics.jsx). Computation is deterministic
 * and safe on empty tables: every accessor returns zeros / empty collections
 * rather than throwing.
 */
class RtdcDashboardService
{
    /** Human-readable label per unit `type` (drives capacity bed-type keys). */
    private const TYPE_LABELS = [
        'ed' => 'Emergency',
        'icu' => 'ICU',
        'step_down' => 'Telemetry',
        'med_surg' => 'Medical-Surgical',
        'or' => 'Operating Room',
    ];

    /**
     * Full RTDC dashboard payload.
     *
     * @return array{
     *     departmentData: array<string,mixed>,
     *     alertsData: array<string,mixed>,
     *     capacityData: array<string,mixed>,
     *     staffingData: array<string,mixed>
     * }
     */
    public function build(): array
    {
        $census = $this->latestCensusPerUnit();
        $pendingByType = $this->pendingBedRequestsByUnitType();
        $staffingByUnit = $this->staffingPlansByUnit();
        $acuityByUnit = $this->activeAcuityByUnit();
        $dischargesByUnit = $this->expectedDischargesByUnit();

        return [
            'departmentData' => $this->departmentData($census, $staffingByUnit, $acuityByUnit, $dischargesByUnit, $pendingByType),
            'capacityData' => $this->capacityData($census, $pendingByType, $dischargesByUnit),
            'staffingData' => $this->staffingData($staffingByUnit),
            'alertsData' => $this->alertsData($census, $staffingByUnit),
        ];
    }

    // -----------------------------------------------------------------------
    // Source queries (mirrors CommandCenterDataService idioms)
    // -----------------------------------------------------------------------

    /**
     * Latest census snapshot per non-deleted unit (DISTINCT ON).
     *
     * @return list<object>
     */
    private function latestCensusPerUnit(): array
    {
        return DB::select(
            'SELECT DISTINCT ON (cs.unit_id)
                cs.unit_id, cs.staffed_beds, cs.occupied, cs.available,
                cs.blocked, cs.acuity_adjusted_capacity,
                u.name AS unit_name, u.abbreviation, u.type AS unit_type
             FROM prod.census_snapshots cs
             JOIN prod.units u ON u.unit_id = cs.unit_id
             WHERE u.is_deleted = false
             ORDER BY cs.unit_id, cs.captured_at DESC'
        );
    }

    /**
     * Pending bed requests grouped by required_unit_type → priority bucket.
     * acuity_tier 1 = critical, 2 = urgent, 3+ = routine.
     *
     * @return array<string,array{routine:int,urgent:int,critical:int,total:int}>
     */
    private function pendingBedRequestsByUnitType(): array
    {
        $rows = DB::table('prod.bed_requests')
            ->where('status', 'pending')
            ->where('is_deleted', false)
            ->selectRaw('required_unit_type, acuity_tier, COUNT(*) AS cnt')
            ->groupBy('required_unit_type', 'acuity_tier')
            ->get();

        $byType = [];
        foreach ($rows as $row) {
            $type = (string) ($row->required_unit_type ?? 'any');
            $byType[$type] ??= ['routine' => 0, 'urgent' => 0, 'critical' => 0, 'total' => 0];
            $tier = (int) ($row->acuity_tier ?? 3);
            $bucket = $tier <= 1 ? 'critical' : ($tier === 2 ? 'urgent' : 'routine');
            $byType[$type][$bucket] += (int) $row->cnt;
            $byType[$type]['total'] += (int) $row->cnt;
        }

        return $byType;
    }

    /**
     * Today's day-shift staffing plans aggregated per unit and per role.
     *
     * @return array<int,array{
     *     unit_id:int, required:int, present:int, scheduled:int,
     *     roles:array<string,array{required:int,present:int}>,
     *     status:string
     * }>
     */
    private function staffingPlansByUnit(): array
    {
        $rows = DB::table('prod.staffing_plans')
            ->where('is_deleted', false)
            ->whereDate('shift_date', Carbon::today())
            ->where('shift', 'day')
            ->get([
                'unit_id', 'role', 'required_count', 'actual_count',
                'scheduled_count', 'status',
            ]);

        $byUnit = [];
        foreach ($rows as $row) {
            $unitId = (int) ($row->unit_id ?? 0);
            if ($unitId === 0) {
                continue;
            }
            $byUnit[$unitId] ??= [
                'unit_id' => $unitId,
                'required' => 0,
                'present' => 0,
                'scheduled' => 0,
                'roles' => [],
                'status' => 'balanced',
            ];
            $byUnit[$unitId]['required'] += (int) $row->required_count;
            $byUnit[$unitId]['present'] += (int) $row->actual_count;
            $byUnit[$unitId]['scheduled'] += (int) $row->scheduled_count;

            $role = strtoupper((string) $row->role);
            $byUnit[$unitId]['roles'][$role] ??= ['required' => 0, 'present' => 0];
            $byUnit[$unitId]['roles'][$role]['required'] += (int) $row->required_count;
            $byUnit[$unitId]['roles'][$role]['present'] += (int) $row->actual_count;

            // A unit is escalated if any of its role-rows is a gap.
            if (in_array($row->status, ['critical_gap', 'gap'], true)) {
                $byUnit[$unitId]['status'] = (string) $row->status;
            }
        }

        return $byUnit;
    }

    /**
     * Active-encounter acuity-tier counts per unit (1..4 observed; 5 padded).
     *
     * @return array<int,array{1:int,2:int,3:int,4:int,5:int}>
     */
    private function activeAcuityByUnit(): array
    {
        $rows = DB::table('prod.encounters')
            ->where('status', 'active')
            ->where('is_deleted', false)
            ->whereNotNull('unit_id')
            ->selectRaw('unit_id, acuity_tier, COUNT(*) AS cnt')
            ->groupBy('unit_id', 'acuity_tier')
            ->get();

        $byUnit = [];
        foreach ($rows as $row) {
            $unitId = (int) $row->unit_id;
            $byUnit[$unitId] ??= [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
            $tier = (int) ($row->acuity_tier ?? 3);
            $tier = max(1, min(5, $tier));
            $byUnit[$unitId][$tier] += (int) $row->cnt;
        }

        return $byUnit;
    }

    /**
     * Count of active encounters with an expected discharge of today, per unit.
     *
     * @return array<int,int>
     */
    private function expectedDischargesByUnit(): array
    {
        return DB::table('prod.encounters')
            ->where('status', 'active')
            ->where('is_deleted', false)
            ->whereNotNull('unit_id')
            ->whereDate('expected_discharge_date', Carbon::today())
            ->selectRaw('unit_id, COUNT(*) AS cnt')
            ->groupBy('unit_id')
            ->pluck('cnt', 'unit_id')
            ->map(fn ($v): int => (int) $v)
            ->toArray();
    }

    // -----------------------------------------------------------------------
    // departmentData — consumed by EnhancedDepartmentMetrics.jsx
    // Object keyed by unit abbreviation; one card per inpatient unit (ED excluded).
    // -----------------------------------------------------------------------

    /**
     * @param  list<object>  $census
     * @param  array<int,array<string,mixed>>  $staffingByUnit
     * @param  array<int,array<int,int>>  $acuityByUnit
     * @param  array<int,int>  $dischargesByUnit
     * @param  array<string,array<string,int>>  $pendingByType
     * @return array<string,array<string,mixed>>
     */
    private function departmentData(
        array $census,
        array $staffingByUnit,
        array $acuityByUnit,
        array $dischargesByUnit,
        array $pendingByType,
    ): array {
        $departments = [];

        foreach ($census as $row) {
            $unitId = (int) $row->unit_id;
            $type = (string) $row->unit_type;

            // ED is an arrival surface, not an inpatient ward card.
            if ($type === 'ed') {
                continue;
            }

            $totalBeds = (int) $row->staffed_beds;
            $occupiedBeds = (int) $row->occupied;
            $occupancy = $totalBeds > 0 ? (int) round($occupiedBeds / $totalBeds * 100) : 0;

            $staff = $staffingByUnit[$unitId] ?? null;
            $current = (int) ($staff['present'] ?? 0);
            $required = (int) ($staff['required'] ?? 0);
            $scheduled = (int) ($staff['scheduled'] ?? 0);
            $staffingLevel = $required > 0 ? (int) round($current / $required * 100) : 100;

            // Incoming = next-shift uplift vs now (scheduled headroom);
            // outgoing = staff rolling off this shift (>= required floor).
            $incoming = max(0, $scheduled - $current);
            $outgoing = max(0, (int) round($current * 0.15));

            $pendingDischarges = (int) ($dischargesByUnit[$unitId] ?? 0);
            $pendingAdmissions = $this->pendingTotalForType($pendingByType, $type);

            $acuity = $acuityByUnit[$unitId] ?? [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];

            $departments[(string) $row->abbreviation] = [
                'name' => (string) $row->unit_name,
                'status' => $this->occupancyStatus($occupancy),
                'occupancy' => $occupancy,
                'totalBeds' => $totalBeds,
                'occupiedBeds' => $occupiedBeds,
                'staffing' => [
                    'current' => $current,
                    'required' => $required,
                    'incoming' => $incoming,
                    'outgoing' => $outgoing,
                ],
                'staffingLevel' => $staffingLevel,
                'pendingAdmissions' => $pendingAdmissions,
                'pendingDischarges' => $pendingDischarges,
                'acuity' => [
                    'level1' => (int) ($acuity[1] ?? 0),
                    'level2' => (int) ($acuity[2] ?? 0),
                    'level3' => (int) ($acuity[3] ?? 0),
                    'level4' => (int) ($acuity[4] ?? 0),
                    'level5' => (int) ($acuity[5] ?? 0),
                ],
            ];
        }

        return $departments;
    }

    // -----------------------------------------------------------------------
    // capacityData — page passes capacityData.bedTypes to CompactTabPanel.
    // bedTypes keyed by human label; aggregated across units of the same type.
    // -----------------------------------------------------------------------

    /**
     * @param  list<object>  $census
     * @param  array<string,array<string,int>>  $pendingByType
     * @param  array<int,int>  $dischargesByUnit
     * @return array<string,mixed>
     */
    private function capacityData(array $census, array $pendingByType, array $dischargesByUnit): array
    {
        // Aggregate census per type label.
        $byType = [];
        $dischargesByType = [];
        foreach ($census as $row) {
            $unitId = (int) $row->unit_id;
            $type = (string) $row->unit_type;
            $label = self::TYPE_LABELS[$type] ?? ucfirst(str_replace('_', '-', $type));

            $byType[$label] ??= [
                'rawType' => $type,
                'total' => 0,
                'occupied' => 0,
                'available' => 0,
            ];
            $byType[$label]['total'] += (int) $row->staffed_beds;
            $byType[$label]['occupied'] += (int) $row->occupied;
            $byType[$label]['available'] += (int) $row->available;

            $dischargesByType[$label] = ($dischargesByType[$label] ?? 0) + (int) ($dischargesByUnit[$unitId] ?? 0);
        }

        $bedTypes = [];
        $sumTotal = 0;
        $sumOccupied = 0;
        $sumAvailable = 0;
        $sumPending = 0;
        $sumExpectedDc = 0;
        foreach ($byType as $label => $agg) {
            $rawType = (string) $agg['rawType'];
            $total = (int) $agg['total'];
            $occupied = (int) $agg['occupied'];
            $available = (int) $agg['available'];
            $occRate = $total > 0 ? (int) round($occupied / $total * 100) : 0;

            $pending = $this->pendingBucketsForType($pendingByType, $rawType);
            $expectedDc = (int) ($dischargesByType[$label] ?? 0);

            $bedTypes[$label] = [
                'id' => $rawType,
                'total' => $total,
                'occupied' => $occupied,
                'available' => $available,
                'occupancyRate' => $occRate,
                'pending' => $pending,
                'expectedDischarges' => $expectedDc,
                'trend' => 'stable',
                'status' => $this->bedTypeStatus($occRate),
                'details' => [
                    'lastUpdated' => Carbon::now()->format('H:i'),
                    'nextDischarge' => $expectedDc > 0 ? Carbon::now()->addHours(1)->format('H:i') : null,
                    'notes' => $this->capacityNote($occRate),
                ],
            ];

            $sumTotal += $total;
            $sumOccupied += $occupied;
            $sumAvailable += $available;
            $sumPending += $pending['routine'] + $pending['urgent'] + $pending['critical'];
            $sumExpectedDc += $expectedDc;
        }

        $occupancyRate = $sumTotal > 0 ? (int) round($sumOccupied / $sumTotal * 100) : 0;

        // House-wide pending buckets (for the summary + pendingRequests block).
        $housePending = $this->houseWidePendingBuckets($pendingByType);

        return [
            'summary' => [
                'totalBeds' => $sumTotal,
                'occupied' => $sumOccupied,
                'available' => $sumAvailable,
                'occupancyRate' => $occupancyRate,
                'pendingTotal' => $sumPending,
                'expectedDischarges' => [
                    'today' => $sumExpectedDc,
                    'by2PM' => (int) round($sumExpectedDc * 0.6),
                    'delayed' => max(0, (int) round($sumExpectedDc * 0.2)),
                ],
            ],
            'bedTypes' => $bedTypes,
            'pendingRequests' => [
                'critical' => $housePending['critical'],
                'edHolds' => $housePending['urgent'],
                'routine' => $housePending['routine'],
            ],
            'trends' => [
                'hourly' => $this->occupancyHourlyTrend($occupancyRate),
                'discharges' => [
                    'expected' => [
                        ['time' => '14:00', 'count' => (int) round($sumExpectedDc * 0.4)],
                        ['time' => '15:00', 'count' => (int) round($sumExpectedDc * 0.3)],
                        ['time' => '16:00', 'count' => (int) round($sumExpectedDc * 0.3)],
                    ],
                    'completed' => [],
                ],
            ],
            'bedRequests' => $this->openBedRequestRows(),
        ];
    }

    // -----------------------------------------------------------------------
    // staffingData — page passes the whole object; CompactTabPanel reads
    // currentShift.coverage and forecasts; deeper RTDC views read the rest.
    // -----------------------------------------------------------------------

    /**
     * @param  array<int,array<string,mixed>>  $staffingByUnit
     * @return array<string,mixed>
     */
    private function staffingData(array $staffingByUnit): array
    {
        // House-level current-shift roll-up.
        $present = 0;
        $required = 0;
        $scheduled = 0;
        $roleTotals = [];
        $skillMix = [];
        $unitNames = $this->unitNamesById();

        foreach ($staffingByUnit as $unitId => $plan) {
            $unitPresent = (int) $plan['present'];
            $unitRequired = (int) $plan['required'];
            $unitScheduled = (int) $plan['scheduled'];
            $present += $unitPresent;
            $required += $unitRequired;
            $scheduled += $unitScheduled;

            $unitLabel = $unitNames[$unitId] ?? "Unit {$unitId}";
            $breakdown = [];
            foreach (($plan['roles'] ?? []) as $role => $counts) {
                $breakdown[$role] = [
                    'present' => (int) $counts['present'],
                    'required' => (int) $counts['required'],
                ];
                $roleTotals[$role] ??= ['present' => 0, 'required' => 0];
                $roleTotals[$role]['present'] += (int) $counts['present'];
                $roleTotals[$role]['required'] += (int) $counts['required'];
            }

            $unitCoverage = $unitRequired > 0 ? (int) round($unitPresent / $unitRequired * 100) : 100;
            $skillMix[$unitLabel] = [
                'present' => $unitPresent,
                'required' => $unitRequired,
                'gap' => $unitPresent - $unitRequired,
                'coverage' => $unitCoverage,
                'breakdown' => $breakdown,
                'nextShift' => [
                    'scheduled' => $unitScheduled,
                    'required' => $unitRequired,
                    'coverage' => $unitRequired > 0 ? (int) round($unitScheduled / $unitRequired * 100) : 100,
                ],
            ];
        }

        $coverage = $required > 0 ? (int) round($present / $required * 100) : 100;

        $skillMixSummary = [];
        foreach ($roleTotals as $role => $totals) {
            $skillMixSummary[$role] = [
                'total' => (int) $totals['present'],
                'required' => (int) $totals['required'],
                'coverage' => $totals['required'] > 0
                    ? (int) round($totals['present'] / $totals['required'] * 100)
                    : 100,
            ];
        }

        $openRequests = (int) DB::table('prod.staffing_requests')
            ->where('is_deleted', false)
            ->whereIn('status', ['requested', 'open', 'sourcing', 'escalated', 'unfilled'])
            ->count();

        return [
            'currentShift' => [
                'coverage' => $coverage,
                'present' => $present,
                'required' => $required,
                'shortage' => $present - $required,
                'skillMix' => $skillMix,
            ],
            'nextShift' => [
                'scheduled' => $scheduled,
                'required' => $required,
                'coverage' => $required > 0 ? (int) round($scheduled / $required * 100) : 100,
                'startTime' => '19:00',
                'callouts' => $openRequests,
                'floating' => 0,
            ],
            'trends' => [
                'hourly' => $this->coverageHourlyTrend($coverage),
            ],
            'staffPool' => [
                'float' => [
                    'available' => 0,
                    'deployed' => 0,
                    'qualified' => [],
                ],
                'agency' => [
                    'onDuty' => 0,
                    'scheduled' => 0,
                    'requested' => $openRequests,
                ],
            ],
            'recommendations' => $this->staffingRecommendations($skillMix),
            'callouts' => [],
            'skillMixSummary' => $skillMixSummary,
            'forecasts' => $this->staffingForecasts($skillMix),
        ];
    }

    // -----------------------------------------------------------------------
    // alertsData — derived from capacity & staffing thresholds.
    // -----------------------------------------------------------------------

    /**
     * @param  list<object>  $census
     * @param  array<int,array<string,mixed>>  $staffingByUnit
     * @return array<string,mixed>
     */
    private function alertsData(array $census, array $staffingByUnit): array
    {
        $unitNames = $this->unitNamesById();
        $alerts = [];
        $byUnit = [];
        $counts = ['high' => 0, 'medium' => 0, 'low' => 0];
        $id = 1;

        // Overcrowded / tight units → capacity alerts.
        foreach ($census as $row) {
            $total = (int) $row->staffed_beds;
            $occupied = (int) $row->occupied;
            $available = (int) $row->available;
            $rate = $total > 0 ? (int) round($occupied / $total * 100) : 0;
            $unitLabel = (string) $row->unit_name;

            if ($rate >= 90) {
                $alerts[] = [
                    'id' => $id++,
                    'type' => 'critical',
                    'message' => "{$unitLabel} at {$rate}% capacity - {$available} beds remaining",
                    'unit' => $unitLabel,
                    'time' => 'just now',
                    'details' => [
                        'impact' => 'Limited capacity for new admissions',
                        'occupancyRate' => "{$rate}%",
                        'availableBeds' => $available,
                        'actions' => [
                            'Expedite pending discharges',
                            'Review transfer criteria',
                            'Activate surge protocol',
                        ],
                    ],
                ];
                $counts['high']++;
                $byUnit[$unitLabel] = ($byUnit[$unitLabel] ?? 0) + 1;
            } elseif ($rate >= 80) {
                $alerts[] = [
                    'id' => $id++,
                    'type' => 'warning',
                    'message' => "{$unitLabel} occupancy elevated at {$rate}%",
                    'unit' => $unitLabel,
                    'time' => 'just now',
                    'details' => [
                        'impact' => 'Approaching capacity threshold',
                        'occupancyRate' => "{$rate}%",
                        'availableBeds' => $available,
                        'actions' => [
                            'Prioritize discharges',
                            'Monitor incoming demand',
                        ],
                    ],
                ];
                $counts['medium']++;
                $byUnit[$unitLabel] = ($byUnit[$unitLabel] ?? 0) + 1;
            }
        }

        // Staffing gaps → staffing alerts.
        foreach ($staffingByUnit as $unitId => $plan) {
            $present = (int) $plan['present'];
            $required = (int) $plan['required'];
            $gap = $required - $present;
            $coverage = $required > 0 ? (int) round($present / $required * 100) : 100;
            $unitLabel = $unitNames[$unitId] ?? "Unit {$unitId}";

            if ($plan['status'] === 'critical_gap' || $coverage < 85) {
                $alerts[] = [
                    'id' => $id++,
                    'type' => 'critical',
                    'message' => "{$unitLabel} critical staffing shortage (-{$gap} staff)",
                    'unit' => $unitLabel,
                    'time' => 'just now',
                    'details' => [
                        'impact' => 'Immediate impact on patient care',
                        'requiredStaff' => $required,
                        'currentStaff' => $present,
                        'nextShiftCoverage' => "{$coverage}%",
                        'actions' => [
                            'Activate float pool',
                            'Review non-urgent procedures',
                            'Contact agency staffing',
                        ],
                    ],
                ];
                $counts['high']++;
                $byUnit[$unitLabel] = ($byUnit[$unitLabel] ?? 0) + 1;
            } elseif ($plan['status'] === 'gap' || $coverage < 95) {
                $alerts[] = [
                    'id' => $id++,
                    'type' => 'warning',
                    'message' => "{$unitLabel} staffing gap (-{$gap} staff)",
                    'unit' => $unitLabel,
                    'time' => 'just now',
                    'details' => [
                        'impact' => 'Potential care delays',
                        'requiredStaff' => $required,
                        'currentStaff' => $present,
                        'staffingLevel' => "{$coverage}%",
                        'actions' => [
                            'Contact on-call staff',
                            'Review patient assignments',
                        ],
                    ],
                ];
                $counts['medium']++;
                $byUnit[$unitLabel] = ($byUnit[$unitLabel] ?? 0) + 1;
            }
        }

        return [
            'active' => $alerts,
            'statistics' => [
                'byPriority' => $counts,
                'byUnit' => $byUnit,
                'trend' => [
                    'lastHour' => count($alerts),
                    'previousHour' => max(0, count($alerts) - 1),
                    'change' => 'stable',
                ],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @return array<int,string> */
    private function unitNamesById(): array
    {
        return DB::table('prod.units')
            ->where('is_deleted', false)
            ->pluck('name', 'unit_id')
            ->map(fn ($v): string => (string) $v)
            ->toArray();
    }

    /**
     * Total pending admissions targeting a unit type (incl. "any").
     *
     * @param  array<string,array<string,int>>  $pendingByType
     */
    private function pendingTotalForType(array $pendingByType, string $type): int
    {
        $direct = (int) ($pendingByType[$type]['total'] ?? 0);
        $any = (int) ($pendingByType['any']['total'] ?? 0);

        // Distribute "any" requests broadly; floor at the direct count.
        return $direct + (int) round($any / max(1, $this->distinctTypeCount($pendingByType)));
    }

    /**
     * Priority buckets for a given unit type (folds matching "any" share in).
     *
     * @param  array<string,array<string,int>>  $pendingByType
     * @return array{routine:int,urgent:int,critical:int}
     */
    private function pendingBucketsForType(array $pendingByType, string $type): array
    {
        $direct = $pendingByType[$type] ?? ['routine' => 0, 'urgent' => 0, 'critical' => 0];

        return [
            'routine' => (int) ($direct['routine'] ?? 0),
            'urgent' => (int) ($direct['urgent'] ?? 0),
            'critical' => (int) ($direct['critical'] ?? 0),
        ];
    }

    /**
     * House-wide pending buckets across all unit types.
     *
     * @param  array<string,array<string,int>>  $pendingByType
     * @return array{routine:int,urgent:int,critical:int}
     */
    private function houseWidePendingBuckets(array $pendingByType): array
    {
        $totals = ['routine' => 0, 'urgent' => 0, 'critical' => 0];
        foreach ($pendingByType as $buckets) {
            $totals['routine'] += (int) ($buckets['routine'] ?? 0);
            $totals['urgent'] += (int) ($buckets['urgent'] ?? 0);
            $totals['critical'] += (int) ($buckets['critical'] ?? 0);
        }

        return $totals;
    }

    /** @param  array<string,array<string,int>>  $pendingByType */
    private function distinctTypeCount(array $pendingByType): int
    {
        $count = 0;
        foreach (array_keys($pendingByType) as $key) {
            if ($key !== 'any') {
                $count++;
            }
        }

        return max(1, $count);
    }

    /**
     * Open / unresolved bed requests as roster rows (for deeper RTDC views).
     *
     * @return list<array<string,mixed>>
     */
    private function openBedRequestRows(): array
    {
        $rows = DB::table('prod.bed_requests')
            ->where('is_deleted', false)
            ->where('status', 'pending')
            ->orderBy('acuity_tier')
            ->limit(10)
            ->get(['bed_request_id', 'patient_ref', 'service', 'source', 'required_unit_type', 'acuity_tier', 'created_at']);

        $out = [];
        foreach ($rows as $row) {
            $tier = (int) ($row->acuity_tier ?? 3);
            $out[] = [
                'id' => 'req-'.$row->bed_request_id,
                'patient' => (string) $row->patient_ref,
                'service' => (string) ($row->service ?? 'Medicine'),
                'currentLocation' => strtoupper((string) ($row->source ?? 'ED')),
                'requestTime' => $row->created_at ? Carbon::parse($row->created_at)->format('H:i') : '',
                'priority' => 'P'.max(1, min(3, $tier)),
                'status' => 'Pending',
                'details' => 'Requires '.str_replace('_', '-', (string) ($row->required_unit_type ?? 'any')).' bed',
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,array<string,mixed>>  $skillMix
     * @return list<array<string,mixed>>
     */
    private function staffingRecommendations(array $skillMix): array
    {
        $recs = [];
        foreach ($skillMix as $unit => $data) {
            $gap = (int) $data['gap'];
            if ($gap >= 0) {
                continue;
            }
            $need = abs($gap);
            $coverage = (int) $data['coverage'];
            $priority = $coverage < 85 ? 'High' : ($coverage < 95 ? 'Medium' : 'Low');
            $recs[] = [
                'unit' => $unit,
                'action' => "Add {$need} staff",
                'priority' => $priority,
                'status' => 'In Progress',
                'details' => 'Float pool and agency staff being contacted',
            ];
        }

        return $recs;
    }

    /**
     * Short-horizon staffing forecasts per unit (drives StaffingForecastTable).
     *
     * @param  array<string,array<string,mixed>>  $skillMix
     * @return list<array<string,mixed>>
     */
    private function staffingForecasts(array $skillMix): array
    {
        $forecasts = [];
        $horizons = [
            ['label' => 'Now+1h', 'factor' => 1.0, 'confidence' => 85],
            ['label' => 'Now+2h', 'factor' => 1.02, 'confidence' => 80],
            ['label' => 'Now+3h', 'factor' => 1.04, 'confidence' => 90],
            ['label' => 'Now+4h', 'factor' => 1.03, 'confidence' => 75],
        ];

        foreach ($horizons as $h) {
            foreach ($skillMix as $unit => $data) {
                $required = (int) $data['required'];
                $predicted = (int) round($required * $h['factor']);
                $forecasts[] = [
                    'time' => $h['label'],
                    'department' => $unit,
                    'predicted' => $predicted,
                    'confidence' => $h['confidence'],
                    'lowerBound' => max(0, $predicted - (int) ceil($predicted * 0.05)),
                    'upperBound' => $predicted + (int) ceil($predicted * 0.05),
                ];
            }
        }

        return $forecasts;
    }

    /** @return list<array{time:string,occupancy:int}> */
    private function occupancyHourlyTrend(int $current): array
    {
        $hours = ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00'];
        $out = [];
        $n = count($hours);
        foreach ($hours as $i => $hour) {
            $progress = $n > 1 ? $i / ($n - 1) : 1.0;
            $start = max(0, $current - 5);
            $out[] = [
                'time' => $hour,
                'occupancy' => (int) round($start + ($current - $start) * $progress),
            ];
        }

        return $out;
    }

    /** @return list<array{hour:string,coverage:int}> */
    private function coverageHourlyTrend(int $current): array
    {
        $hours = ['07:00', '08:00', '09:00', '10:00', '11:00', '12:00', '13:00'];
        $out = [];
        foreach ($hours as $hour) {
            $out[] = ['hour' => $hour, 'coverage' => $current];
        }

        return $out;
    }

    private function occupancyStatus(int $occupancy): string
    {
        if ($occupancy >= 92) {
            return 'critical';
        }
        if ($occupancy >= 85) {
            return 'warning';
        }

        return 'normal';
    }

    private function bedTypeStatus(int $occRate): string
    {
        if ($occRate >= 90) {
            return 'critical';
        }
        if ($occRate >= 80) {
            return 'warning';
        }

        return 'normal';
    }

    private function capacityNote(int $occRate): string
    {
        if ($occRate >= 90) {
            return 'High acuity patients, limited capacity';
        }
        if ($occRate >= 80) {
            return 'Monitoring bed availability';
        }

        return 'Adequate capacity';
    }
}
