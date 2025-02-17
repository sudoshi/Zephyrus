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
