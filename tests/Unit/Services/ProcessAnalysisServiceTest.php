<?php

namespace Tests\Unit\Services;

use App\Services\ProcessAnalysisService;
use Tests\TestCase;

class ProcessAnalysisServiceTest extends TestCase
{
    private ProcessAnalysisService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ProcessAnalysisService;
    }

    public function test_returns_admissions_workflow_data_by_default(): void
    {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');

        $this->assertIsArray($data);
        $this->assertArrayHasKey('nodes', $data);
        $this->assertArrayHasKey('edges', $data);
        $this->assertArrayHasKey('metrics', $data);
    }

    public function test_returns_discharge_workflow_data_when_requested(): void
    {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Discharges', '24 Hours');

        $this->assertIsArray($data);
        $this->assertArrayHasKey('nodes', $data);
        $this->assertArrayHasKey('edges', $data);
        $this->assertArrayHasKey('metrics', $data);
    }

    public function test_returns_nodes_with_expected_structure(): void
    {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');

        $this->assertIsArray($data['nodes']);
        $this->assertNotEmpty($data['nodes']);

        $firstNode = $data['nodes'][0];
        $this->assertArrayHasKey('id', $firstNode);
        $this->assertArrayHasKey('position', $firstNode);
        $this->assertArrayHasKey('data', $firstNode);
        $this->assertArrayHasKey('label', $firstNode['data']);
        $this->assertArrayHasKey('metrics', $firstNode['data']);
    }

    public function test_returns_edges_with_expected_structure(): void
    {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');

        $this->assertIsArray($data['edges']);
        $this->assertNotEmpty($data['edges']);

        $firstEdge = $data['edges'][0];
        $this->assertArrayHasKey('id', $firstEdge);
        $this->assertArrayHasKey('source', $firstEdge);
        $this->assertArrayHasKey('target', $firstEdge);
    }

    public function test_returns_metrics_with_staffing_data(): void
    {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');

        $this->assertArrayHasKey('staffing', $data['metrics']);
        $this->assertArrayHasKey('nurses', $data['metrics']['staffing']);
        $this->assertArrayHasKey('physicians', $data['metrics']['staffing']);
        $this->assertArrayHasKey('assigned', $data['metrics']['staffing']['nurses']);
        $this->assertArrayHasKey('required', $data['metrics']['staffing']['nurses']);
    }

    public function test_returns_metrics_with_space_data(): void
    {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');

        $this->assertArrayHasKey('rooms', $data['metrics']['space']);
        $this->assertArrayHasKey('occupied', $data['metrics']['space']['rooms']);
        $this->assertArrayHasKey('capacity', $data['metrics']['space']['rooms']);
    }

    public function test_returns_metrics_with_cascade_analysis(): void
    {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');

        $this->assertArrayHasKey('cascade', $data['metrics']);
        $this->assertArrayHasKey('primaryProcess', $data['metrics']['cascade']);
        $this->assertArrayHasKey('affectedProcesses', $data['metrics']['cascade']);
    }

    public function test_returns_metrics_with_wait_time_data(): void
    {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');

        $this->assertArrayHasKey('waitTime', $data['metrics']);
        $this->assertArrayHasKey('current', $data['metrics']['waitTime']);
        $this->assertArrayHasKey('benchmark', $data['metrics']['waitTime']);
        $this->assertArrayHasKey('peakMultipliers', $data['metrics']['waitTime']);
    }

    public function test_returns_metrics_with_predictions(): void
    {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');

        $this->assertArrayHasKey('predictions', $data['metrics']);
        $this->assertArrayHasKey('resourceUtilization', $data['metrics']['predictions']);
        $this->assertArrayHasKey('patternAnalysis', $data['metrics']['predictions']);
        $this->assertArrayHasKey('correlations', $data['metrics']['predictions']);
        $this->assertArrayHasKey('optimizationSuggestions', $data['metrics']['predictions']);
    }

    public function test_applies_seven_day_multiplier_to_node_counts(): void
    {
        $dayData = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');
        $weekData = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '7 Days');

        $dayCount = $dayData['nodes'][0]['data']['metrics']['count'];
        $weekCount = $weekData['nodes'][0]['data']['metrics']['count'];

        $this->assertSame($dayCount * 7, $weekCount);
    }

    public function test_applies_fourteen_day_multiplier_to_node_counts(): void
    {
        $dayData = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');
        $twoWeekData = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '14 Days');

        $dayCount = $dayData['nodes'][0]['data']['metrics']['count'];
        $twoWeekCount = $twoWeekData['nodes'][0]['data']['metrics']['count'];

        $this->assertSame($dayCount * 14, $twoWeekCount);
    }

    public function test_applies_thirty_day_multiplier_to_node_counts(): void
    {
        $dayData = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');
        $monthData = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '1 Month');

        $dayCount = $dayData['nodes'][0]['data']['metrics']['count'];
        $monthCount = $monthData['nodes'][0]['data']['metrics']['count'];

        $this->assertSame($dayCount * 30, $monthCount);
    }

    public function test_applies_multiplier_to_edge_patient_counts(): void
    {
        $dayData = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');
        $weekData = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '7 Days');

        $dayEdge = collect($dayData['edges'])->firstWhere('id', 'e1');
        $weekEdge = collect($weekData['edges'])->firstWhere('id', 'e1');

        $this->assertSame($dayEdge['data']['patientCount'] * 7, $weekEdge['data']['patientCount']);
    }

    public function test_discharge_workflow_includes_clinical_branch_nodes(): void
    {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Discharges', '24 Hours');

        $nodeIds = collect($data['nodes'])->pluck('id')->all();

        $this->assertContains('discharge_order', $nodeIds);
        $this->assertContains('med_reconciliation', $nodeIds);
        $this->assertContains('discharge_summary', $nodeIds);
        $this->assertContains('patient_education', $nodeIds);
    }

    public function test_discharge_workflow_includes_pharmacy_branch_nodes(): void
    {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Discharges', '24 Hours');

        $nodeIds = collect($data['nodes'])->pluck('id')->all();

        $this->assertContains('med_review', $nodeIds);
        $this->assertContains('rx_processing', $nodeIds);
        $this->assertContains('take_home_meds', $nodeIds);
    }

    public function test_discharge_workflow_includes_final_departure_step(): void
    {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Discharges', '24 Hours');

        $nodeIds = collect($data['nodes'])->pluck('id')->all();

        $this->assertContains('departure', $nodeIds);
    }
}
