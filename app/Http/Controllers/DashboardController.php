<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
public function index(Request $request)
{
    $request->session()->put('workflow', 'or');
    return Inertia::render('Dashboard/OR');
}

    public function changeWorkflow(Request $request)
    {
        $request->validate([
            'workflow' => 'required|in:rtdc,or,ed',
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
}
