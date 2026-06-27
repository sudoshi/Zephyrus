<?php

namespace App\Services;

use App\Models\PdsaCycle;
use App\Models\User;
use Illuminate\Support\Carbon;

class DashboardService
{
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
     */
    public function getBottleneckStats(): array
    {
        return [
            'stats' => [
                'active' => 12,
                'avgResolutionTime' => 4.2,
                'patientImpact' => 86,
            ],
        ];
    }

    /**
     * Get root cause analysis data.
     */
    public function getRootCauses(): array
    {
        return [
            [
                'rank' => 1,
                'type' => 'Discharge Documentation Delays',
                'location' => 'Med-Surg 3W',
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
                'location' => 'ICU → 4E',
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
        return [
            [
                'title' => 'Example Opportunity',
                'description' => 'This is an example improvement opportunity',
                'department' => 'Surgery',
                'priority' => 'High',
                'status' => 'Open',
            ],
        ];
    }

    /**
     * Get library resources data.
     */
    public function getLibraryResources(): array
    {
        return [
            [
                'title' => 'PDSA Template',
                'description' => 'Standard template for PDSA cycle documentation',
                'category' => 'Templates',
                'type' => 'Document',
                'dateAdded' => '2024-02-10',
            ],
        ];
    }

    /**
     * Get active PDSA cycles data.
     */
    public function getActiveCycles(): array
    {
        return [
            [
                'id' => 1,
                'title' => 'Example PDSA Cycle',
                'objective' => 'Improve patient turnover time',
                'status' => 'in-progress',
                'currentPhase' => 'Do',
                'startDate' => '2024-02-01',
                'targetDate' => '2024-03-01',
                'progress' => 45,
            ],
        ];
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
