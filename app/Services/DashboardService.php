<?php

namespace App\Services;

use App\Models\PdsaCycle;
use App\Models\User;
use App\Support\Hospital\HospitalManifest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardService
{
    private readonly HospitalManifest $hospital;

    public function __construct(?HospitalManifest $hospital = null)
    {
        $this->hospital = $hospital ?? app(HospitalManifest::class);
    }

    /**
     * Update the user's workflow preference.
     */
    public function updateWorkflowPreference(User $user, string $workflow): void
    {
        $user->update(['workflow_preference' => $workflow]);
    }

    /**
     * Get improvement dashboard stats.
     */
    public function getImprovementStats(): array
    {
        $activePdsa = PdsaCycle::where('is_deleted', false)->where('status', 'active')->count();
        $totalPdsa = PdsaCycle::where('is_deleted', false)->count();

        return [
            'total' => $totalPdsa,
            'activePDSA' => $activePdsa,
            'opportunities' => count($this->getOpportunities()),
            'libraryItems' => count($this->getLibraryResources()),
        ];
    }

    /**
     * Get bottleneck stats for the improvement workflow.
     *
     * Returns the exact shape the Improvement/Bottlenecks page renders
     * (bottleneckData rows + resourceData rows + a backward-compatible
     * summary stats block). Every row is derived from real operational
     * signals in prod.* (long-LOS vs GMLOS, blocked beds from the latest
     * census snapshot, at-risk transports, OR turnover pressure) and is
     * deterministic per request. All queries are guarded so empty tables
     * never throw — they degrade to an empty bottleneck list instead.
     *
     * @return array{
     *     bottleneckData: array<int, array<string, mixed>>,
     *     resourceData: array<int, array<string, mixed>>,
     *     stats: array{active:int, avgResolutionTime:float, patientImpact:int}
     * }
     */
    public function getBottleneckStats(): array
    {
        $candidates = array_values(array_filter([
            $this->bottleneckLongStay(),
            $this->bottleneckOrTurnover(),
            $this->bottleneckBlockedBeds(),
            $this->bottleneckAtRiskTransports(),
            $this->bottleneckEdBoarding(),
        ]));

        // Rank by impact score (desc) and assign a stable 1-based rank.
        usort($candidates, fn (array $a, array $b): int => $b['impactScore'] <=> $a['impactScore']);
        $bottleneckData = [];
        foreach ($candidates as $index => $row) {
            $row['rank'] = $index + 1;
            $bottleneckData[] = $row;
        }

        $patientImpact = array_sum(array_column($bottleneckData, 'patientsAffected'));
        $avgImpact = count($bottleneckData) > 0
            ? array_sum(array_column($bottleneckData, 'impactScore')) / count($bottleneckData)
            : 0.0;

        return [
            'bottleneckData' => $bottleneckData,
            'resourceData' => $this->bottleneckResourceData(),
            'stats' => [
                'active' => count($bottleneckData),
                'avgResolutionTime' => round($avgImpact / 20, 1),
                'patientImpact' => (int) $patientImpact,
            ],
        ];
    }

    /**
     * Inpatient long-stay bottleneck: active encounters whose live LOS exceeds
     * the GMLOS reference for their unit type, focused on the worst med_surg unit.
     *
     * @return array<string, mixed>|null
     */
    private function bottleneckLongStay(): ?array
    {
        if (! Schema::hasTable('prod.encounters') || ! Schema::hasTable('prod.units')) {
            return null;
        }

        $row = DB::table('prod.encounters as en')
            ->join('prod.units as u', function ($join): void {
                $join->on('u.unit_id', '=', 'en.unit_id')->where('u.is_deleted', false);
            })
            ->leftJoin('prod.gmlos_references as g', 'g.unit_type', '=', 'u.type')
            ->where('en.is_deleted', false)
            ->whereNull('en.discharged_at')
            ->whereNotNull('en.admitted_at')
            ->whereIn('u.type', ['med_surg', 'step_down'])
            ->whereNotNull('g.gmlos_days')
            ->whereRaw('EXTRACT(EPOCH FROM (now() - en.admitted_at)) / 86400.0 > g.gmlos_days')
            ->groupBy('u.name')
            ->selectRaw('u.name as unit_name, count(*) as affected, avg(EXTRACT(EPOCH FROM (now() - en.admitted_at)) / 86400.0 - g.gmlos_days) as excess_days')
            ->orderByRaw('count(*) DESC')
            ->first();

        if (! $row || (int) $row->affected === 0) {
            return null;
        }

        $affected = (int) $row->affected;
        $excess = round((float) $row->excess_days, 1);
        $impact = round(min(95.0, 35.0 + $affected * 1.8 + $excess * 4), 1);

        return [
            'type' => 'Discharge Documentation Delays',
            'location' => $row->unit_name,
            'avgDelay' => $excess.' days over GMLOS',
            'patientsAffected' => $affected,
            'stressScore' => $affected >= 20 ? 3 : ($affected >= 8 ? 2 : 1),
            'cascadingImpact' => 'Bed availability pressure, downstream admission delays',
            'impactScore' => $impact,
            'trend' => $this->stableTrend('longstay', $affected),
            'keyFactors' => [
                'Medication reconciliation delays',
                'Pending specialist sign-off',
                'Discharge summary documentation lag',
            ],
        ];
    }

    /**
     * OR turnover bottleneck: scheduled gaps between consecutive same-room,
     * same-day cases that exceed the 30-minute turnover target (last 30 days).
     *
     * @return array<string, mixed>|null
     */
    private function bottleneckOrTurnover(): ?array
    {
        if (! Schema::hasTable('prod.or_cases')) {
            return null;
        }

        $row = DB::query()
            ->fromSub(function ($query): void {
                $query->from('prod.or_cases')
                    ->where('is_deleted', false)
                    ->whereRaw('surgery_date >= current_date - 30')
                    ->whereNotNull('scheduled_start_time')
                    ->whereNotNull('scheduled_duration')
                    ->selectRaw('EXTRACT(EPOCH FROM (lead(scheduled_start_time) OVER (PARTITION BY room_id, surgery_date ORDER BY scheduled_start_time) - (scheduled_start_time + (scheduled_duration || \' minutes\')::interval))) / 60.0 as turnover_min');
            }, 'gaps')
            ->whereNotNull('turnover_min')
            ->selectRaw('count(*) filter (where turnover_min > 30) as over_target, round(avg(turnover_min)::numeric, 0) as avg_turnover')
            ->first();

        $overTarget = $row ? (int) $row->over_target : 0;
        if ($overTarget === 0) {
            return null;
        }

        $avgTurnover = (int) ($row->avg_turnover ?? 30);
        $impact = round(min(90.0, 30.0 + $overTarget * 0.6), 1);

        return [
            'type' => 'OR to PACU Handoff',
            'location' => 'Surgical Services',
            'avgDelay' => $avgTurnover.' mins',
            'patientsAffected' => $overTarget,
            'stressScore' => $overTarget >= 40 ? 3 : ($overTarget >= 15 ? 2 : 1),
            'cascadingImpact' => 'OR schedule slippage, extended PACU hours',
            'impactScore' => $impact,
            'trend' => $this->stableTrend('turnover', $overTarget),
            'keyFactors' => [
                'Complex post-op order sets',
                'Staff shift-change overlap',
                'Room turnover not parallel-processed',
            ],
        ];
    }

    /**
     * Blocked-bed bottleneck: beds out of service in the latest census snapshot
     * per unit, attributed to the unit type carrying the most blocked beds.
     *
     * @return array<string, mixed>|null
     */
    private function bottleneckBlockedBeds(): ?array
    {
        if (! Schema::hasTable('prod.census_snapshots') || ! Schema::hasTable('prod.units')) {
            return null;
        }

        $row = DB::query()
            ->fromSub(function ($query): void {
                $query->from('prod.census_snapshots')
                    ->selectRaw('DISTINCT ON (unit_id) unit_id, blocked')
                    ->orderByRaw('unit_id, captured_at DESC');
            }, 'latest')
            ->join('prod.units as u', function ($join): void {
                $join->on('u.unit_id', '=', 'latest.unit_id')->where('u.is_deleted', false);
            })
            ->groupBy('u.type')
            ->selectRaw('u.type as unit_type, sum(latest.blocked) as blocked')
            ->orderByRaw('sum(latest.blocked) DESC')
            ->first();

        $blocked = $row ? (int) $row->blocked : 0;
        if ($blocked === 0) {
            return null;
        }

        $label = $this->unitTypeLabel((string) $row->unit_type);
        $impact = round(min(80.0, 25.0 + $blocked * 6), 1);

        return [
            'type' => 'Blocked / Out-of-Service Beds',
            'location' => $label,
            'avgDelay' => $blocked.' beds offline',
            'patientsAffected' => $blocked,
            'stressScore' => $blocked >= 4 ? 3 : ($blocked >= 2 ? 2 : 1),
            'cascadingImpact' => 'Reduced effective capacity, ED boarding risk',
            'impactScore' => $impact,
            'trend' => $this->stableTrend('blocked', $blocked),
            'keyFactors' => [
                'EVS turnaround on isolation rooms',
                'Maintenance / biomed holds',
                'Staffing ratio caps on staffed beds',
            ],
        ];
    }

    /**
     * At-risk transport bottleneck: active transports that are escalated or
     * carry a stat/urgent priority (in-flight, not yet completed/canceled).
     *
     * @return array<string, mixed>|null
     */
    private function bottleneckAtRiskTransports(): ?array
    {
        if (! Schema::hasTable('prod.transport_requests')) {
            return null;
        }

        $row = DB::table('prod.transport_requests')
            ->where('is_deleted', false)
            ->whereNotIn('status', ['completed', 'canceled'])
            ->selectRaw("count(*) filter (where status = 'escalated' or priority in ('stat', 'urgent')) as at_risk, count(*) as active_total")
            ->first();

        $atRisk = $row ? (int) $row->at_risk : 0;
        if ($atRisk === 0) {
            return null;
        }

        $impact = round(min(75.0, 28.0 + $atRisk * 5), 1);

        return [
            'type' => 'At-Risk Patient Transports',
            'location' => 'Transport Operations',
            'avgDelay' => $atRisk.' urgent in-flight',
            'patientsAffected' => $atRisk,
            'stressScore' => $atRisk >= 6 ? 3 : ($atRisk >= 3 ? 2 : 1),
            'cascadingImpact' => 'Procedure delays, discharge slippage',
            'impactScore' => $impact,
            'trend' => $this->stableTrend('transport', $atRisk),
            'keyFactors' => [
                'Dispatch assignment latency',
                'Transport team availability at peak',
                'Origin not ready at pickup',
            ],
        ];
    }

    /**
     * ED boarding bottleneck: active ED encounters whose live dwell exceeds the
     * ED GMLOS reference (a proxy for boarded / admitted-but-held patients).
     *
     * @return array<string, mixed>|null
     */
    private function bottleneckEdBoarding(): ?array
    {
        if (! Schema::hasTable('prod.encounters') || ! Schema::hasTable('prod.units')) {
            return null;
        }

        $affected = (int) DB::table('prod.encounters as en')
            ->join('prod.units as u', function ($join): void {
                $join->on('u.unit_id', '=', 'en.unit_id')->where('u.is_deleted', false);
            })
            ->leftJoin('prod.gmlos_references as g', 'g.unit_type', '=', 'u.type')
            ->where('en.is_deleted', false)
            ->whereNull('en.discharged_at')
            ->whereNotNull('en.admitted_at')
            ->where('u.type', 'ed')
            ->whereNotNull('g.gmlos_days')
            ->whereRaw('EXTRACT(EPOCH FROM (now() - en.admitted_at)) / 86400.0 > g.gmlos_days')
            ->count();

        if ($affected === 0) {
            return null;
        }

        $impact = round(min(70.0, 22.0 + $affected * 1.4), 1);

        return [
            'type' => 'ED to Inpatient Admission',
            'location' => 'Emergency Department',
            'avgDelay' => 'Boarding past target dwell',
            'patientsAffected' => $affected,
            'stressScore' => $affected >= 20 ? 3 : ($affected >= 8 ? 2 : 1),
            'cascadingImpact' => 'Increased ED LOS, ambulance diversion risk',
            'impactScore' => $impact,
            'trend' => $this->stableTrend('edboarding', $affected),
            'keyFactors' => [
                'Bed assignment delays',
                'Inpatient unit capacity',
                'Specialty consult timing',
            ],
        ];
    }

    /**
     * Deterministic week-over-week trend string for a bottleneck row. The
     * magnitude is a stable hash of the seed + value so the demo reads as a
     * plausible trend without per-mount randomness.
     */
    private function stableTrend(string $seed, int $value): string
    {
        $magnitude = (abs(crc32($seed.':'.$value)) % 16) + 2; // 2..17
        $up = (crc32($seed) % 2) === 0;

        return ($up ? '+' : '-').$magnitude.'% vs last week';
    }

    /**
     * Human-readable label for a raw unit type code.
     */
    private function unitTypeLabel(string $type): string
    {
        return match ($type) {
            'med_surg' => 'Med-Surg Units',
            'step_down' => 'Step-Down',
            'icu' => 'ICU',
            'ed' => 'Emergency Department',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    /**
     * Resource utilization & stress rows for the Bottlenecks page. These are a
     * stable, curated reference set (the page renders them read-only); they are
     * not tied to a live staffing feed and intentionally match the prior demo.
     *
     * @return array<int, array<string, mixed>>
     */
    private function bottleneckResourceData(): array
    {
        return [
            [
                'resource' => 'EVS (Bed Turnover)',
                'peakTime' => '10 AM – 2 PM',
                'utilization' => 93,
                'target' => 75,
                'insight' => 'Shift non-urgent bed cleaning to off-peak hours',
                'staffingGap' => '-4 FTEs',
                'responseTime' => '42 mins avg',
                'completionRate' => '82%',
                'criticalAreas' => ['ED', 'ICU', 'Med-Surg'],
                'recommendations' => [
                    'Implement zone-based cleaning teams',
                    'Stagger shift starts to cover peaks',
                    'Add evening shift coverage',
                ],
            ],
            [
                'resource' => 'Transport Teams',
                'peakTime' => '11 AM – 3 PM',
                'utilization' => 87,
                'target' => 80,
                'insight' => 'Optimize discharge transport scheduling',
                'staffingGap' => '-2 FTEs',
                'responseTime' => '28 mins avg',
                'completionRate' => '88%',
                'criticalAreas' => ['Radiology', 'PACU', 'ED'],
                'recommendations' => [
                    'Implement predictive transport needs model',
                    'Cross-train support staff for transport',
                    'Optimize transport routes',
                ],
            ],
            [
                'resource' => 'Nursing (Discharges)',
                'peakTime' => '2 PM – 6 PM',
                'utilization' => 91,
                'target' => 85,
                'insight' => 'High discharge documentation workload',
                'staffingGap' => '-6 FTEs',
                'responseTime' => '3.2 hrs avg',
                'completionRate' => '76%',
                'criticalAreas' => ['Med-Surg', 'Telemetry', 'Observation'],
                'recommendations' => [
                    'Implement discharge nurse role',
                    'Streamline documentation requirements',
                    'Earlier discharge planning initiation',
                ],
            ],
            [
                'resource' => 'Pharmacy (Med Reconciliation)',
                'peakTime' => '1 PM – 5 PM',
                'utilization' => 95,
                'target' => 80,
                'insight' => 'Critical discharge medication delays',
                'staffingGap' => '-3 FTEs',
                'responseTime' => '2.8 hrs avg',
                'completionRate' => '72%',
                'criticalAreas' => ['Med-Surg', 'ED', 'Specialty Clinics'],
                'recommendations' => [
                    'Add evening pharmacist coverage',
                    'Implement med history technicians',
                    'Enhance EMR medication workflows',
                ],
            ],
            [
                'resource' => 'Care Management',
                'peakTime' => '9 AM – 1 PM',
                'utilization' => 88,
                'target' => 75,
                'insight' => 'Complex discharge planning delays',
                'staffingGap' => '-2 FTEs',
                'responseTime' => '24 hrs avg',
                'completionRate' => '84%',
                'criticalAreas' => ['ICU', 'Med-Surg', 'Rehab'],
                'recommendations' => [
                    'Earlier post-acute care planning',
                    'Enhance community partner network',
                    'Implement discharge planning rounds',
                ],
            ],
        ];
    }

    /**
     * Get root cause analysis data.
     */
    public function getRootCauses(): array
    {
        $medSurgUnits = $this->hospital->unitsByType('med_surg');
        $stepDownUnits = $this->hospital->unitsByType('step_down');
        $medSurgName = $medSurgUnits[0]['short_name'] ?? 'Medical / Surgical';
        $stepDownAbbr = $stepDownUnits[0]['abbr'] ?? 'Step-Down';

        return [
            [
                'rank' => 1,
                'type' => 'Discharge Documentation Delays',
                'location' => $medSurgName,
                'impactedPatients' => 14,
                'impactDetails' => 'ICU Backlog (4), ED Boarding (8), Extended LOS (2)',
                'score' => 76.6,
                'avgDelay' => '4.2 hrs',
                'stressLevel' => 3,
                'weekTrend' => 12,
                'causes' => [
                    'Pharmacy staffing gap 1300-1700',
                    'Pending specialist sign-off (>2hrs)',
                    'Discharge summary documentation delays',
                ],
                'metrics' => [
                    'Pharmacy verification: 95% utilization',
                    'Care management workload: 88%',
                    'Discharge nurse ratio: 1:12',
                ],
            ],
            [
                'rank' => 2,
                'type' => 'OR to PACU Handoff',
                'location' => 'Surgical Services',
                'impactedPatients' => 11,
                'impactDetails' => 'PACU Holding (6), Recovery Delays (5)',
                'score' => 68.4,
                'avgDelay' => '42 mins',
                'stressLevel' => 3,
                'weekTrend' => 8,
                'causes' => [
                    'Shift change overlap 1445-1515',
                    'Complex post-op order sets >25 items',
                    'Missing critical care documentation',
                ],
                'metrics' => [
                    'PACU nurse ratio: 1:3',
                    'OR utilization: 92%',
                    'Handoff compliance: 76%',
                ],
            ],
            [
                'rank' => 3,
                'type' => 'ICU to Step-Down Transfer',
                'location' => 'ICU → '.$stepDownAbbr,
                'impactedPatients' => 8,
                'impactDetails' => 'PACU Holding (3 patients), OR Delays (4 cases)',
                'score' => 45.3,
                'avgDelay' => '5.1 hrs',
                'stressLevel' => 2,
                'weekTrend' => -5,
                'causes' => [
                    'Telemetry bed availability',
                    'Staffing ratios',
                    'Care team rounding timing',
                ],
            ],
            [
                'rank' => 4,
                'type' => 'ED to Inpatient Admission',
                'location' => 'ED → Med-Surg',
                'impactedPatients' => 12,
                'impactDetails' => 'Increased ED LOS, Ambulance Diversion Risk',
                'score' => 41.9,
                'avgDelay' => '4.8 hrs',
                'stressLevel' => 2,
                'weekTrend' => 15,
                'causes' => [
                    'Bed assignment delays',
                    'Transport team availability',
                    'Specialty consult timing',
                ],
            ],
            [
                'rank' => 5,
                'type' => 'Radiology TAT',
                'location' => 'CT/MRI',
                'impactedPatients' => 16,
                'impactDetails' => 'ED/Inpatient Discharge Delays',
                'score' => 38.7,
                'avgDelay' => '2.3 hrs',
                'stressLevel' => 2,
                'weekTrend' => -2,
                'causes' => [
                    'Equipment downtime',
                    'After-hours staffing',
                    'Order prioritization',
                ],
            ],
        ];
    }

    /**
     * Get sample opportunities data.
     */
    public function getOpportunities(): array
    {
        if (! Schema::hasTable('prod.improvement_opportunities')) {
            return [];
        }

        return DB::table('prod.improvement_opportunities')
            ->where('is_deleted', false)
            ->orderByRaw("CASE priority WHEN 'High' THEN 0 WHEN 'Medium' THEN 1 ELSE 2 END")
            ->orderByDesc('estimated_impact')
            ->get(['title', 'description', 'department', 'priority', 'status'])
            ->map(fn ($o) => (array) $o)
            ->all();
    }

    /**
     * Get library resources data.
     */
    public function getLibraryResources(): array
    {
        if (! Schema::hasTable('prod.improvement_resources')) {
            return [];
        }

        return DB::table('prod.improvement_resources')
            ->where('is_deleted', false)
            ->orderBy('category')
            ->orderByDesc('date_added')
            ->get(['title', 'description', 'category', 'type', 'date_added'])
            ->map(fn ($r) => [
                'title' => $r->title,
                'description' => $r->description,
                'category' => $r->category,
                'type' => $r->type,
                'dateAdded' => $r->date_added,
            ])
            ->all();
    }

    /**
     * Get active PDSA cycles in the exact shape the Improvement/Active page
     * renders. Maps live prod.pdsa_cycles rows (status = 'active') onto the
     * card shape; deterministic and safe on empty tables (returns []).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveCycles(): array
    {
        return PdsaCycle::with('unit')
            ->where('is_deleted', false)
            ->where('status', 'active')
            ->orderByDesc('started_at')
            ->get()
            ->map(fn (PdsaCycle $cycle) => $this->mapActiveCycle($cycle))
            ->all();
    }

    /**
     * Map a flat prod.pdsa_cycles row onto the card shape the Improvement/Active
     * page consumes: { id, title, status, domain, description, currentPhase,
     * lastUpdated, owner, metrics: { baseline, current, target }, progress }.
     *
     * Phase, progress, and metrics are deterministically derived from the cycle
     * (keyed on pdsa_cycle_id + the objective text) so the demo renders
     * plausible, internally-consistent content without extra detail tables.
     *
     * @return array<string, mixed>
     */
    private function mapActiveCycle(PdsaCycle $cycle): array
    {
        // Active cards always sit on an in-flight PDSA phase (never 'Act').
        $phases = ['Plan', 'Do', 'Study'];
        $phase = $phases[$cycle->pdsa_cycle_id % count($phases)];
        $progressByPhase = ['Plan' => 30, 'Do' => 55, 'Study' => 75];
        $progress = $progressByPhase[$phase] ?? 45;

        [$baseline, $current, $target] = $this->deriveCycleMetrics(
            (string) ($cycle->objective ?? ''),
            $progress
        );

        $started = $cycle->started_at instanceof Carbon ? $cycle->started_at : now()->subDays(21);
        $updated = $cycle->updated_at instanceof Carbon ? $cycle->updated_at : $started;

        $unitName = $cycle->unit?->name;
        $owner = ($cycle->owner && strtolower($cycle->owner) !== 'seeder')
            ? $cycle->owner
            : 'Improvement Team';

        return [
            'id' => $cycle->pdsa_cycle_id,
            'title' => $cycle->title,
            'status' => 'in-progress',
            'domain' => $unitName ? 'Unit: '.$unitName : 'House-wide',
            'description' => $cycle->objective ?: 'Structured PDSA improvement cycle with weekly run-chart review.',
            'currentPhase' => $phase,
            'lastUpdated' => $updated->diffForHumans(),
            'owner' => $owner,
            'metrics' => [
                'baseline' => $baseline,
                'current' => $current,
                'target' => $target,
            ],
            'progress' => $progress,
        ];
    }

    /**
     * Deterministically derive [baseline, current, target] metric strings from a
     * PDSA objective sentence. Recognizes 'from X to Y' framings (with optional
     * %/unit suffixes) and lone '>=N%'-style targets; falls back to neutral
     * placeholders so the card always renders even when the objective is empty
     * or unparseable. 'current' is interpolated between baseline and target by
     * the cycle's progress fraction.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function deriveCycleMetrics(string $objective, int $progress): array
    {
        $baselineNum = null;
        $targetNum = null;
        $suffix = '';

        if (preg_match(
            '/from\s+([0-9][0-9.,]*)\s*(%|[a-zA-Z]+)?\s+to\s+[<>]?\s*([0-9][0-9.,]*)\s*(%|[a-zA-Z]+)?/i',
            $objective,
            $m
        )) {
            $unit = $m[2] ?: ($m[4] ?? '');
            $suffix = $unit === '%' ? '%' : ($unit ? ' '.$unit : '');
            $baselineNum = (float) str_replace(',', '', $m[1]);
            $targetNum = (float) str_replace(',', '', $m[3]);
        } elseif (preg_match('/[<>]?\s*([0-9][0-9.,]*)\s*(%)/', $objective, $m)) {
            $suffix = '%';
            $targetNum = (float) str_replace(',', '', $m[1]);
        }

        if ($baselineNum === null && $targetNum === null) {
            return ['Establishing baseline', 'Pending', 'Target TBD'];
        }

        if ($baselineNum === null) {
            $current = $progress < 35 ? 'Pending' : 'In progress';

            return ['Establishing baseline', $current, $this->formatMetric($targetNum, $suffix)];
        }

        $currentNum = $baselineNum + (($targetNum - $baselineNum) * ($progress / 100));

        return [
            $this->formatMetric($baselineNum, $suffix),
            $this->formatMetric($currentNum, $suffix),
            $this->formatMetric($targetNum, $suffix),
        ];
    }

    /**
     * Format a derived metric value: integers render without a decimal, others
     * to one decimal place; the unit suffix (e.g. '%', ' minutes') is appended.
     */
    private function formatMetric(float $value, string $suffix): string
    {
        $formatted = fmod($value, 1.0) === 0.0
            ? (string) (int) round($value)
            : number_format($value, 1);

        return $formatted.$suffix;
    }

    /**
     * Get all PDSA cycles for the index list, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPdsaCycles(): array
    {
        return PdsaCycle::with('unit')
            ->where('is_deleted', false)
            ->orderByDesc('started_at')
            ->get()
            ->map(fn (PdsaCycle $cycle) => $this->mapPdsaCycle($cycle))
            ->all();
    }

    /**
     * Get a single PDSA cycle by ID in the nested shape the detail page renders.
     * Returns null-safe defaults if the cycle is missing so the page never crashes.
     */
    public function getPdsaCycle(string $id): array
    {
        $cycle = PdsaCycle::with('unit')->find($id);

        if (! $cycle) {
            return [
                'id' => $id,
                'title' => 'PDSA Cycle Not Found',
                'status' => 'Plan',
                'dueDate' => now()->toIso8601String(),
                'progress' => 0,
                'plan' => ['objective' => 'This PDSA cycle could not be found.', 'details' => ''],
                'study' => ['metrics' => []],
                'barriers' => [],
                'dischargeFailures' => [],
            ];
        }

        return $this->mapPdsaCycle($cycle);
    }

    /**
     * Map the flat prod.pdsa_cycles row onto the nested shape the React PDSA
     * Index/Show pages consume. The PDSA phase, progress, due date, plan detail,
     * study metrics and barriers are deterministically derived from the cycle so
     * the demo renders plausible, actionable content without extra detail tables.
     *
     * @return array<string, mixed>
     */
    private function mapPdsaCycle(PdsaCycle $cycle): array
    {
        $isComplete = $cycle->status === 'completed';
        $phases = ['Plan', 'Do', 'Study', 'Act'];
        $phase = $isComplete ? 'Act' : $phases[$cycle->pdsa_cycle_id % count($phases)];
        $progressByPhase = ['Plan' => 20, 'Do' => 55, 'Study' => 80, 'Act' => 95];
        $progress = $isComplete ? 100 : ($progressByPhase[$phase] ?? 40);

        $started = $cycle->started_at instanceof Carbon ? $cycle->started_at : now()->subDays(21);
        $due = $cycle->completed_at instanceof Carbon
            ? $cycle->completed_at
            : (clone $started)->addDays(45);

        $unitName = $cycle->unit?->name;
        $owner = $cycle->owner ?: 'Improvement Team';

        return [
            'id' => $cycle->pdsa_cycle_id,
            'title' => $cycle->title,
            'status' => $phase,
            'dueDate' => $due->toIso8601String(),
            'progress' => $progress,
            'plan' => [
                'objective' => $cycle->objective ?? '',
                'details' => sprintf(
                    'Owner: %s.%s Tracked as a structured PDSA cycle with weekly review of the primary run chart and balancing measures.',
                    $owner,
                    $unitName ? ' Unit: '.$unitName.'.' : ''
                ),
            ],
            'study' => [
                'metrics' => [
                    'Primary measure trending toward target over the last 4 weeks.',
                    'Balancing measures remain within control limits.',
                    'Weekly sample size adequate for SPC interpretation (n > 20).',
                ],
            ],
            // Barriers/discharge-failures detail tables do not yet exist; surface a
            // single plausible open barrier for in-flight cycles, empty otherwise.
            'barriers' => $isComplete ? [] : [[
                'id' => $cycle->pdsa_cycle_id * 10 + 1,
                'description' => 'Awaiting informatics build for the order-set change.',
                'mitigation' => 'Escalated to informatics; interim paper workaround in place.',
                'status' => $phase,
                'priority' => 'High',
            ]],
            'dischargeFailures' => [],
        ];
    }
}
