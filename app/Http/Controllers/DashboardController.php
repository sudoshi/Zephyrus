<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $request->session()->put('workflow', 'perioperative');
        return Inertia::render('Dashboard/Perioperative', [
            'workflow' => 'perioperative'
        ]);
    }

    public function changeWorkflow(Request $request)
    {
        $request->validate([
            'workflow' => 'required|in:rtdc,perioperative,emergency,improvement',
        ]);

        // Update the workflow in the session
        $workflow = $request->input('workflow');
        $request->session()->put('workflow', $workflow);

        // Return JSON response with the new workflow
        return response()->json([
            'success' => true,
            'workflow' => $workflow,
        ]);
    }

    public function improvement(Request $request)
    {
        $request->session()->put('workflow', 'improvement');
        return Inertia::render('Dashboard/Improvement', [
            'workflow' => 'improvement',
            'stats' => [
                'total' => 0,
                'activePDSA' => 0,
                'opportunities' => 0,
                'libraryItems' => 0,
            ],
            'cycles' => []
        ]);
    }

    public function bottlenecks(Request $request)
    {
        $request->session()->put('workflow', 'improvement');
        return Inertia::render('Improvement/Bottlenecks', [
            'workflow' => 'improvement',
            'bottlenecks' => [
                'stats' => [
                    'active' => 12,
                    'avgResolutionTime' => 4.2,
                    'patientImpact' => 86
                ]
            ]
        ]);
    }

    public function rootCause(Request $request)
    {
        $request->session()->put('workflow', 'improvement');

        // Production data would come from ML models analyzing:
        // - EMR timestamps
        // - Bed management system
        // - Staff scheduling data
        // - Resource utilization metrics
        $rootCauses = [
            [
                'rank' => 1,
                'type' => 'Discharge Documentation Delays',
                'location' => 'Med-Surg 3W',
                'impactedPatients' => 14,
                'impactDetails' => 'ICU Backlog (4), ED Boarding (8), Extended LOS (2)',
                'score' => 76.6,
                'avgDelay' => '4.2 hrs',  // More realistic timeframe
                'stressLevel' => 3,
                'weekTrend' => 12,
                'causes' => [
                    'Pharmacy staffing gap 1300-1700',
                    'Pending specialist sign-off (>2hrs)',
                    'Discharge summary documentation delays'
                ],
                'metrics' => [
                    'Pharmacy verification: 95% utilization',
                    'Care management workload: 88%',
                    'Discharge nurse ratio: 1:12'
                ]
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
                    'Missing critical care documentation'
                ],
                'metrics' => [
                    'PACU nurse ratio: 1:3',
                    'OR utilization: 92%',
                    'Handoff compliance: 76%'
                ]
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
                    'Care team rounding timing'
                ]
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
                    'Specialty consult timing'
                ]
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
                    'Order prioritization'
                ]
            ]
        ];

        return Inertia::render('Improvement/RootCause', [
            'workflow' => 'improvement',
            'rootCauses' => $rootCauses
        ]);
    }

    public function overview(Request $request)
    {
        return redirect()->route('dashboard.improvement');
    }

    public function opportunities(Request $request)
    {
        return Inertia::render('Improvement/Opportunities', [
            'opportunities' => [
                // Example data structure
                [
                    'title' => 'Example Opportunity',
                    'description' => 'This is an example improvement opportunity',
                    'department' => 'Surgery',
                    'priority' => 'High',
                    'status' => 'Open'
                ]
            ]
        ]);
    }

    public function library(Request $request)
    {
        return Inertia::render('Improvement/Library', [
            'resources' => [
                // Example data structure
                [
                    'title' => 'PDSA Template',
                    'description' => 'Standard template for PDSA cycle documentation',
                    'category' => 'Templates',
                    'type' => 'Document',
                    'dateAdded' => '2024-02-10'
                ]
            ]
        ]);
    }

    public function active(Request $request)
    {
        return Inertia::render('Improvement/Active', [
            'cycles' => [
                // Example data structure
                [
                    'id' => 1,
                    'title' => 'Example PDSA Cycle',
                    'objective' => 'Improve patient turnover time',
                    'status' => 'in-progress',
                    'currentPhase' => 'Do',
                    'startDate' => '2024-02-01',
                    'targetDate' => '2024-03-01',
                    'progress' => 45
                ]
            ]
        ]);
    }

    public function process(Request $request)
    {
        return Inertia::render('Improvement/Process');
    }

    public function pdsaIndex(Request $request)
    {
        return Inertia::render('Improvement/PDSA/Index', [
            'cycles' => []
        ]);
    }

    public function pdsaShow(Request $request, $id)
    {
        return Inertia::render('Improvement/PDSA/Show', [
            'cycle' => [
                'id' => $id,
                'title' => '',
                'objective' => '',
                'status' => '',
                'phases' => [
                    'plan' => [],
                    'do' => [],
                    'study' => [],
                    'act' => []
                ]
            ]
        ]);
    }
}
