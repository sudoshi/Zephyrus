<?php

namespace App\Http\Controllers;

use App\Models\ProcessLayout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ProcessAnalysisController extends Controller
{
    /**
     * Display the process analysis page.
     */
    public function index()
    {
        $layout = ProcessLayout::where('user_id', Auth::id())
            ->where('process_type', 'admission')
            ->first();

        return Inertia::render('Improvement/Process', [
            'savedLayout' => $layout ? $layout->layout_data : null,
        ]);
    }

    /**
     * Get nursing operations data for process visualization.
     */
    public function getNursingOperations()
    {
        return response()->json($this->getNursingOperationsData());
    }

    /**
     * Generate sample nursing operations data.
     */
    private function getNursingOperationsData()
    {
        return [
            'nodes' => [
                ['id' => 'arrival', 'data' => ['label' => 'Patient Arrival', 'metrics' => ['count' => 150, 'avgTime' => '5m']]],
                ['id' => 'triage', 'data' => ['label' => 'Triage', 'metrics' => ['count' => 150, 'avgTime' => '15m']]],
                ['id' => 'quick_reg', 'data' => ['label' => 'Quick Registration', 'metrics' => ['count' => 150, 'avgTime' => '10m']]],
                ['id' => 'nursing', 'data' => ['label' => 'Nursing Assessment', 'metrics' => ['count' => 150, 'avgTime' => '25m']]],
                ['id' => 'provider', 'data' => ['label' => 'Provider Exam', 'metrics' => ['count' => 150, 'avgTime' => '30m']]],
                ['id' => 'decision', 'data' => ['label' => 'Disposition Decision', 'metrics' => ['count' => 150, 'avgTime' => '10m']]],
                ['id' => 'bed_request', 'data' => ['label' => 'Bed Request', 'metrics' => ['count' => 90, 'avgTime' => '20m']]],
                ['id' => 'transport', 'data' => ['label' => 'Transport', 'metrics' => ['count' => 90, 'avgTime' => '15m']]],
                ['id' => 'unit', 'data' => ['label' => 'Unit Arrival', 'metrics' => ['count' => 90, 'avgTime' => '5m']]],
                // Clinical branch
                ['id' => 'lab_orders', 'data' => ['label' => 'Lab Orders', 'metrics' => ['count' => 120, 'avgTime' => '5m']]],
                ['id' => 'lab_results', 'data' => ['label' => 'Lab Results', 'metrics' => ['count' => 120, 'avgTime' => '45m']]],
                ['id' => 'imaging_orders', 'data' => ['label' => 'Imaging Orders', 'metrics' => ['count' => 80, 'avgTime' => '5m']]],
                ['id' => 'imaging_results', 'data' => ['label' => 'Imaging Results', 'metrics' => ['count' => 80, 'avgTime' => '35m']]],
                // Administrative branch
                ['id' => 'full_reg', 'data' => ['label' => 'Full Registration', 'metrics' => ['count' => 150, 'avgTime' => '15m']]],
                ['id' => 'insurance', 'data' => ['label' => 'Insurance Verification', 'metrics' => ['count' => 150, 'avgTime' => '20m']]],
                ['id' => 'bed_assign', 'data' => ['label' => 'Bed Assignment', 'metrics' => ['count' => 90, 'avgTime' => '15m']]],
            ],
            'edges' => [
                // Main flow
                ['id' => 'e1', 'source' => 'arrival', 'target' => 'triage', 'data' => ['patientCount' => 150, 'avgTime' => '2m', 'isMainFlow' => true]],
                ['id' => 'e2', 'source' => 'triage', 'target' => 'quick_reg', 'data' => ['patientCount' => 150, 'avgTime' => '3m', 'isMainFlow' => true]],
                ['id' => 'e3', 'source' => 'quick_reg', 'target' => 'nursing', 'data' => ['patientCount' => 150, 'avgTime' => '5m', 'isMainFlow' => true]],
                ['id' => 'e4', 'source' => 'nursing', 'target' => 'provider', 'data' => ['patientCount' => 150, 'avgTime' => '10m', 'isMainFlow' => true]],
                ['id' => 'e5', 'source' => 'provider', 'target' => 'decision', 'data' => ['patientCount' => 150, 'avgTime' => '5m', 'isMainFlow' => true]],
                ['id' => 'e6', 'source' => 'decision', 'target' => 'bed_request', 'data' => ['patientCount' => 90, 'avgTime' => '5m', 'isMainFlow' => true]],
                ['id' => 'e7', 'source' => 'bed_request', 'target' => 'transport', 'data' => ['patientCount' => 90, 'avgTime' => '10m', 'isMainFlow' => true]],
                ['id' => 'e8', 'source' => 'transport', 'target' => 'unit', 'data' => ['patientCount' => 90, 'avgTime' => '15m', 'isMainFlow' => true]],
                // Clinical branch
                ['id' => 'e9', 'source' => 'nursing', 'target' => 'lab_orders'],
                ['id' => 'e10', 'source' => 'lab_orders', 'target' => 'lab_results'],
                ['id' => 'e11', 'source' => 'nursing', 'target' => 'imaging_orders'],
                ['id' => 'e12', 'source' => 'imaging_orders', 'target' => 'imaging_results'],
                ['id' => 'e13', 'source' => 'lab_results', 'target' => 'provider', 'data' => ['isResultFlow' => true]],
                ['id' => 'e14', 'source' => 'imaging_results', 'target' => 'provider', 'data' => ['isResultFlow' => true]],
                // Administrative branch
                ['id' => 'e15', 'source' => 'quick_reg', 'target' => 'full_reg'],
                ['id' => 'e16', 'source' => 'full_reg', 'target' => 'insurance'],
                ['id' => 'e17', 'source' => 'bed_request', 'target' => 'bed_assign'],
                ['id' => 'e18', 'source' => 'bed_assign', 'target' => 'transport'],
            ],
        ];
    }

    /**
     * Save the process layout.
     */
    public function saveLayout(Request $request)
    {
        try {
            $validated = $request->validate([
                'process_type' => 'required|string|max:50',
                'layout_data' => 'required|array',
            ]);

            ProcessLayout::updateOrCreate(
                [
                    'user_id' => Auth::id(),
                    'process_type' => $validated['process_type'],
                ],
                [
                    'layout_data' => $validated['layout_data'],
                ]
            );

            return response()->noContent();
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get the saved process layout.
     */
    public function getLayout(Request $request)
    {
        $validated = $request->validate([
            'process_type' => 'required|string|max:50',
        ]);

        $layout = ProcessLayout::where('user_id', Auth::id())
            ->where('process_type', $validated['process_type'])
            ->first();

        return response()->json([
            'layout' => $layout ? $layout->layout_data : null,
        ]);
    }
}
