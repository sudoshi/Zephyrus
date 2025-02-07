<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $request->session()->put('workflow', 'perioperative');
        return Inertia::render('Dashboard/Perioperative');
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
        return Inertia::render('Dashboard/Improvement');
    }

    public function opportunities(Request $request)
    {
        return Inertia::render('Improvement/Opportunities');
    }

    public function library(Request $request)
    {
        return Inertia::render('Improvement/Library');
    }

    public function active(Request $request)
    {
        return Inertia::render('Improvement/Active');
    }
}
