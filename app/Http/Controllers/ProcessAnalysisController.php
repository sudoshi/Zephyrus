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
            ->where('hospital', request('hospital', 'Virtua Marlton Hospital'))
            ->where('workflow', request('workflow', 'Admissions'))
            ->where('time_range', request('timeRange', '24 Hours'))
            ->first();

        return Inertia::render('Improvement/Process', [
            'savedLayout' => $layout ? $layout->layout_data : null,
        ]);
    }

    /**
     * Get nursing operations data for process visualization.
     */
    public function getNursingOperations(Request $request)
    {
        $hospital = $request->query('hospital', 'Virtua Marlton Hospital');
        $workflow = $request->query('workflow', 'Admissions');
        $timeRange = $request->query('timeRange', '24 Hours');

        // For now, return the same data structure but we'll modify it based on the workflow
        $data = $this->getNursingOperationsData($workflow);

        // Simulate different counts based on hospital and time range
        $multiplier = match ($timeRange) {
            '7 Days' => 7,
            '14 Days' => 14,
            '1 Month' => 30,
            default => 1,
        };

        // Adjust counts based on multiplier
        foreach ($data['nodes'] as &$node) {
            if (isset($node['data']['metrics']['count'])) {
                $node['data']['metrics']['count'] *= $multiplier;
            }
        }

        foreach ($data['edges'] as &$edge) {
            if (isset($edge['data']['patientCount'])) {
                $edge['data']['patientCount'] *= $multiplier;
            }
        }

        return response()->json($data);
    }

    /**
     * Generate sample nursing operations data.
     */
    private function getNursingOperationsData($workflow = 'Admissions')
    {
        if ($workflow === 'Discharges') {
            return [
                'nodes' => [
                    // Clinical Branch
                    ['id' => 'discharge_order', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Discharge Order Entry', 'metrics' => ['count' => 120, 'avgTime' => '5m']]],
                    ['id' => 'med_reconciliation', 'position' => ['x' => 300, 'y' => 0], 'data' => ['label' => 'Medication Reconciliation', 'metrics' => ['count' => 120, 'avgTime' => '20m']]],
                    ['id' => 'discharge_summary', 'position' => ['x' => 600, 'y' => 0], 'data' => ['label' => 'Discharge Summary', 'metrics' => ['count' => 120, 'avgTime' => '25m']]],
                    ['id' => 'care_team_signoff', 'position' => ['x' => 900, 'y' => 0], 'data' => ['label' => 'Care Team Sign-offs', 'metrics' => ['count' => 120, 'avgTime' => '15m']]],
                    ['id' => 'final_assessment', 'position' => ['x' => 1200, 'y' => 0], 'data' => ['label' => 'Final Clinical Assessment', 'metrics' => ['count' => 120, 'avgTime' => '15m']]],
                    ['id' => 'patient_education', 'position' => ['x' => 1500, 'y' => 0], 'data' => ['label' => 'Patient Education', 'metrics' => ['count' => 120, 'avgTime' => '20m']]],
                    
                    // Pharmacy Branch
                    ['id' => 'med_review', 'position' => ['x' => 300, 'y' => 200], 'data' => ['label' => 'Medication Review', 'metrics' => ['count' => 110, 'avgTime' => '15m']]],
                    ['id' => 'rx_processing', 'position' => ['x' => 600, 'y' => 200], 'data' => ['label' => 'Prescription Processing', 'metrics' => ['count' => 110, 'avgTime' => '20m']]],
                    ['id' => 'med_teaching', 'position' => ['x' => 900, 'y' => 200], 'data' => ['label' => 'Medication Teaching', 'metrics' => ['count' => 110, 'avgTime' => '15m']]],
                    ['id' => 'take_home_meds', 'position' => ['x' => 1200, 'y' => 200], 'data' => ['label' => 'Take-Home Medications', 'metrics' => ['count' => 110, 'avgTime' => '10m']]],
                    
                    // Care Coordination Branch
                    ['id' => 'post_discharge_plan', 'position' => ['x' => 300, 'y' => 400], 'data' => ['label' => 'Post-Discharge Planning', 'metrics' => ['count' => 100, 'avgTime' => '25m']]],
                    ['id' => 'home_health', 'position' => ['x' => 600, 'y' => 400], 'data' => ['label' => 'Home Health Coordination', 'metrics' => ['count' => 60, 'avgTime' => '20m']]],
                    ['id' => 'equipment', 'position' => ['x' => 900, 'y' => 400], 'data' => ['label' => 'Equipment Arrangements', 'metrics' => ['count' => 40, 'avgTime' => '15m']]],
                    ['id' => 'followup_appts', 'position' => ['x' => 1200, 'y' => 400], 'data' => ['label' => 'Follow-up Appointments', 'metrics' => ['count' => 100, 'avgTime' => '15m']]],
                    ['id' => 'caregiver_education', 'position' => ['x' => 1500, 'y' => 400], 'data' => ['label' => 'Caregiver Education', 'metrics' => ['count' => 80, 'avgTime' => '20m']]],
                    
                    // Administrative Branch
                    ['id' => 'insurance_auth', 'position' => ['x' => 300, 'y' => 600], 'data' => ['label' => 'Insurance Authorization', 'metrics' => ['count' => 120, 'avgTime' => '30m']]],
                    ['id' => 'billing_process', 'position' => ['x' => 600, 'y' => 600], 'data' => ['label' => 'Billing Processing', 'metrics' => ['count' => 120, 'avgTime' => '15m']]],
                    ['id' => 'discharge_papers', 'position' => ['x' => 900, 'y' => 600], 'data' => ['label' => 'Discharge Paperwork', 'metrics' => ['count' => 120, 'avgTime' => '20m']]],
                    ['id' => 'medical_records', 'position' => ['x' => 1200, 'y' => 600], 'data' => ['label' => 'Medical Records', 'metrics' => ['count' => 120, 'avgTime' => '15m']]],
                    
                    // Final Steps
                    ['id' => 'belongings', 'position' => ['x' => 600, 'y' => 800], 'data' => ['label' => 'Belongings Collection', 'metrics' => ['count' => 120, 'avgTime' => '10m']]],
                    ['id' => 'transport_arrange', 'position' => ['x' => 900, 'y' => 800], 'data' => ['label' => 'Transport Arrangement', 'metrics' => ['count' => 120, 'avgTime' => '15m']]],
                    ['id' => 'escort', 'position' => ['x' => 1200, 'y' => 800], 'data' => ['label' => 'Patient Escort', 'metrics' => ['count' => 120, 'avgTime' => '15m']]],
                    ['id' => 'departure', 'position' => ['x' => 1500, 'y' => 800], 'data' => ['label' => 'Final Departure', 'metrics' => ['count' => 120, 'avgTime' => '5m']]]
                ],
                'edges' => [
                    // Clinical Branch - Main Flow
                    ['id' => 'e1', 'source' => 'discharge_order', 'target' => 'med_reconciliation', 'data' => ['patientCount' => 120, 'avgTime' => '5m', 'isMainFlow' => true]],
                    ['id' => 'e2', 'source' => 'med_reconciliation', 'target' => 'discharge_summary', 'data' => ['patientCount' => 120, 'avgTime' => '10m', 'isMainFlow' => true]],
                    ['id' => 'e3', 'source' => 'discharge_summary', 'target' => 'care_team_signoff', 'data' => ['patientCount' => 120, 'avgTime' => '15m', 'isMainFlow' => true]],
                    ['id' => 'e4', 'source' => 'care_team_signoff', 'target' => 'final_assessment', 'data' => ['patientCount' => 120, 'avgTime' => '10m', 'isMainFlow' => true]],
                    ['id' => 'e5', 'source' => 'final_assessment', 'target' => 'patient_education', 'data' => ['patientCount' => 120, 'avgTime' => '5m', 'isMainFlow' => true]],
                    
                    // Pharmacy Branch
                    ['id' => 'e6', 'source' => 'med_reconciliation', 'target' => 'med_review'],
                    ['id' => 'e7', 'source' => 'med_review', 'target' => 'rx_processing'],
                    ['id' => 'e8', 'source' => 'rx_processing', 'target' => 'med_teaching'],
                    ['id' => 'e9', 'source' => 'med_teaching', 'target' => 'take_home_meds'],
                    ['id' => 'e10', 'source' => 'take_home_meds', 'target' => 'patient_education', 'data' => ['isResultFlow' => true]],
                    
                    // Care Coordination Branch
                    ['id' => 'e11', 'source' => 'discharge_order', 'target' => 'post_discharge_plan'],
                    ['id' => 'e12', 'source' => 'post_discharge_plan', 'target' => 'home_health'],
                    ['id' => 'e13', 'source' => 'post_discharge_plan', 'target' => 'equipment'],
                    ['id' => 'e14', 'source' => 'post_discharge_plan', 'target' => 'followup_appts'],
                    ['id' => 'e15', 'source' => 'post_discharge_plan', 'target' => 'caregiver_education'],
                    ['id' => 'e16', 'source' => 'caregiver_education', 'target' => 'patient_education', 'data' => ['isResultFlow' => true]],
                    
                    // Administrative Branch
                    ['id' => 'e17', 'source' => 'discharge_order', 'target' => 'insurance_auth'],
                    ['id' => 'e18', 'source' => 'insurance_auth', 'target' => 'billing_process'],
                    ['id' => 'e19', 'source' => 'billing_process', 'target' => 'discharge_papers'],
                    ['id' => 'e20', 'source' => 'discharge_papers', 'target' => 'medical_records'],
                    ['id' => 'e21', 'source' => 'medical_records', 'target' => 'patient_education', 'data' => ['isResultFlow' => true]],
                    
                    // Final Steps
                    ['id' => 'e22', 'source' => 'patient_education', 'target' => 'belongings', 'data' => ['patientCount' => 120, 'avgTime' => '5m', 'isMainFlow' => true]],
                    ['id' => 'e23', 'source' => 'belongings', 'target' => 'transport_arrange', 'data' => ['patientCount' => 120, 'avgTime' => '10m', 'isMainFlow' => true]],
                    ['id' => 'e24', 'source' => 'transport_arrange', 'target' => 'escort', 'data' => ['patientCount' => 120, 'avgTime' => '5m', 'isMainFlow' => true]],
                    ['id' => 'e25', 'source' => 'escort', 'target' => 'departure', 'data' => ['patientCount' => 120, 'avgTime' => '5m', 'isMainFlow' => true]]
                ],
            ];
        }

        // Default Admissions workflow
        return [
            'nodes' => [
                ['id' => 'arrival', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Patient Arrival', 'metrics' => ['count' => 150, 'avgTime' => '5m']]],
                ['id' => 'triage', 'position' => ['x' => 300, 'y' => 0], 'data' => ['label' => 'Triage', 'metrics' => ['count' => 150, 'avgTime' => '15m']]],
                ['id' => 'quick_reg', 'position' => ['x' => 600, 'y' => 0], 'data' => ['label' => 'Quick Registration', 'metrics' => ['count' => 150, 'avgTime' => '10m']]],
                ['id' => 'nursing', 'position' => ['x' => 900, 'y' => 0], 'data' => ['label' => 'Nursing Assessment', 'metrics' => ['count' => 150, 'avgTime' => '25m']]],
                ['id' => 'provider', 'position' => ['x' => 1200, 'y' => 0], 'data' => ['label' => 'Provider Exam', 'metrics' => ['count' => 150, 'avgTime' => '30m']]],
                ['id' => 'decision', 'position' => ['x' => 1500, 'y' => 0], 'data' => ['label' => 'Disposition Decision', 'metrics' => ['count' => 150, 'avgTime' => '10m']]],
                ['id' => 'bed_request', 'position' => ['x' => 1800, 'y' => 0], 'data' => ['label' => 'Bed Request', 'metrics' => ['count' => 90, 'avgTime' => '20m']]],
                ['id' => 'transport', 'position' => ['x' => 2100, 'y' => 0], 'data' => ['label' => 'Transport', 'metrics' => ['count' => 90, 'avgTime' => '15m']]],
                ['id' => 'unit', 'position' => ['x' => 2400, 'y' => 0], 'data' => ['label' => 'Unit Arrival', 'metrics' => ['count' => 90, 'avgTime' => '5m']]],
                // Clinical branch
                ['id' => 'lab_orders', 'position' => ['x' => 900, 'y' => 200], 'data' => ['label' => 'Lab Orders', 'metrics' => ['count' => 120, 'avgTime' => '5m']]],
                ['id' => 'lab_results', 'position' => ['x' => 1200, 'y' => 200], 'data' => ['label' => 'Lab Results', 'metrics' => ['count' => 120, 'avgTime' => '45m']]],
                ['id' => 'imaging_orders', 'position' => ['x' => 900, 'y' => 400], 'data' => ['label' => 'Imaging Orders', 'metrics' => ['count' => 80, 'avgTime' => '5m']]],
                ['id' => 'imaging_results', 'position' => ['x' => 1200, 'y' => 400], 'data' => ['label' => 'Imaging Results', 'metrics' => ['count' => 80, 'avgTime' => '35m']]],
                // Administrative branch
                ['id' => 'full_reg', 'position' => ['x' => 600, 'y' => 200], 'data' => ['label' => 'Full Registration', 'metrics' => ['count' => 150, 'avgTime' => '15m']]],
                ['id' => 'insurance', 'position' => ['x' => 600, 'y' => 400], 'data' => ['label' => 'Insurance Verification', 'metrics' => ['count' => 150, 'avgTime' => '20m']]],
                ['id' => 'bed_assign', 'position' => ['x' => 1800, 'y' => 200], 'data' => ['label' => 'Bed Assignment', 'metrics' => ['count' => 90, 'avgTime' => '15m']]],
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
                'hospital' => 'required|string|max:100',
                'workflow' => 'required|string|max:50',
                'time_range' => 'required|string|max:50',
            ]);

            ProcessLayout::updateOrCreate(
                [
                    'user_id' => Auth::id(),
                    'hospital' => $validated['hospital'],
                    'workflow' => $validated['workflow'],
                    'time_range' => $validated['time_range'],
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
            'hospital' => 'required|string|max:100',
            'workflow' => 'required|string|max:50',
            'time_range' => 'required|string|max:50',
        ]);

        $layout = ProcessLayout::where('user_id', Auth::id())
            ->where('hospital', $validated['hospital'])
            ->where('workflow', $validated['workflow'])
            ->where('time_range', $validated['time_range'])
            ->first();

        return response()->json([
            'layout' => $layout ? $layout->layout_data : null,
        ]);
    }
}
