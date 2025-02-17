<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;

class ProcessAnalysisController extends Controller
{
    public function getNursingOperations()
    {
        try {
            // Generate sample nursing operations data
            $rawData = $this->generateNursingData();
            
            // Process the data into a format suitable for the flow diagram
            $processedData = $this->processDataForFlowDiagram($rawData);
            
            return response()->json($processedData);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function processDataForFlowDiagram($rawData)
    {
        // Map nursing activities to flow diagram nodes
        $activityNodeMap = [
            'Admission' => 'arrival',
            'Initial Assessment' => 'registration',
            'Vital Signs' => 'assortment2',
            'Doctor Round' => 'diagnosis2',
            'Nursing Assessment' => 'examination2',
            'Blood Draw' => 'blood_test',
            'IV Change' => 'additional_test',
            'Physical Therapy' => 'enzyme_test',
            'Imaging' => 'blood_result',
            'Specialist Consultation' => 'clinic',
            'Pain Assessment' => 'enzyme_result',
            'Family Meeting' => 'additional_result',
            'Medication Administration' => 'prescription',
            'Care Plan Update' => 'exit'
        ];

        // Define default metrics for nodes without direct activity mapping
        $defaultMetrics = [
            'count' => 0,
            'avgTime' => '0m',
            'cohorts' => [
                'standard' => ['count' => 0, 'avgTime' => '0m']
            ]
        ];

        // Define vertical layout grid with larger spacing
        $verticalSpacing = 150;
        $horizontalSpacing = 300;
        $nodePositions = [
            // Main flow
            'arrival' => ['x' => $horizontalSpacing * 2, 'y' => 0],
            'registration' => ['x' => $horizontalSpacing * 2, 'y' => $verticalSpacing],
            'assortment2' => ['x' => $horizontalSpacing * 2, 'y' => $verticalSpacing * 2],
            'diagnosis2' => ['x' => $horizontalSpacing * 2, 'y' => $verticalSpacing * 3],
            'examination2' => ['x' => $horizontalSpacing * 2, 'y' => $verticalSpacing * 4],
            'laboratory' => ['x' => $horizontalSpacing * 2, 'y' => $verticalSpacing * 5],
            'clinic' => ['x' => $horizontalSpacing * 2, 'y' => $verticalSpacing * 6],
            'prescription_decision' => ['x' => $horizontalSpacing * 2, 'y' => $verticalSpacing * 7],
            'prescription' => ['x' => $horizontalSpacing * 2, 'y' => $verticalSpacing * 8],
            'exit' => ['x' => $horizontalSpacing * 2, 'y' => $verticalSpacing * 9],
            
            // Side nodes - left
            'blood_test' => ['x' => $horizontalSpacing, 'y' => $verticalSpacing * 4],
            'enzyme_test' => ['x' => $horizontalSpacing, 'y' => $verticalSpacing * 5],
            'additional_test' => ['x' => $horizontalSpacing, 'y' => $verticalSpacing * 6],
            
            // Side nodes - right
            'blood_result' => ['x' => $horizontalSpacing * 3, 'y' => $verticalSpacing * 4],
            'enzyme_result' => ['x' => $horizontalSpacing * 3, 'y' => $verticalSpacing * 5],
            'additional_result' => ['x' => $horizontalSpacing * 3, 'y' => $verticalSpacing * 6],
        ];

        // Calculate metrics for each node and edge
        $nodeMetrics = $this->calculateNodeMetrics($rawData, $activityNodeMap);
        
        // Add default metrics for nodes without data
        foreach ($nodePositions as $nodeId => $_) {
            if (!isset($nodeMetrics[$nodeId])) {
                $nodeMetrics[$nodeId] = $defaultMetrics;
            }
        }

        $edgeMetrics = $this->calculateEdgeMetrics($rawData, $activityNodeMap);
        
        // Add default metrics for edges without data
        $defaultEdgeMetrics = [
            'patientCount' => 0,
            'avgTime' => '0m',
            'cohortMetrics' => []
        ];

        // Define nodes based on positions
        $nodes = [];
        foreach ($nodePositions as $id => $position) {
            $type = in_array($id, ['assortment2', 'prescription_decision']) ? 'decision' : 'process';
            $nodes[] = [
                'id' => $id,
                'type' => $type,
                'position' => $position,
                'data' => [
                    'label' => ucwords(str_replace('_', ' ', $id)),
                    'metrics' => $nodeMetrics[$id] ?? $defaultMetrics
                ]
            ];
        }

        // Define edges with flow types
        $edges = [
            // Main flow path
            ['id' => 'arrival-registration', 'source' => 'arrival', 'target' => 'registration', 'data' => array_merge($edgeMetrics['arrival-registration'] ?? $defaultEdgeMetrics, ['isMainFlow' => true])],
            ['id' => 'registration-assortment2', 'source' => 'registration', 'target' => 'assortment2', 'data' => array_merge($edgeMetrics['registration-assortment2'] ?? $defaultEdgeMetrics, ['isMainFlow' => true])],
            ['id' => 'assortment2-diagnosis2', 'source' => 'assortment2', 'target' => 'diagnosis2', 'data' => array_merge($edgeMetrics['assortment2-diagnosis2'] ?? $defaultEdgeMetrics, ['isMainFlow' => true])],
            ['id' => 'diagnosis2-examination2', 'source' => 'diagnosis2', 'target' => 'examination2', 'data' => array_merge($edgeMetrics['diagnosis2-examination2'] ?? $defaultEdgeMetrics, ['isMainFlow' => true])],
            ['id' => 'examination2-laboratory', 'source' => 'examination2', 'target' => 'laboratory', 'data' => array_merge($edgeMetrics['examination2-laboratory'] ?? $defaultEdgeMetrics, ['isMainFlow' => true])],
            ['id' => 'laboratory-clinic', 'source' => 'laboratory', 'target' => 'clinic', 'data' => array_merge($edgeMetrics['laboratory-clinic'] ?? $defaultEdgeMetrics, ['isMainFlow' => true])],
            ['id' => 'clinic-prescription_decision', 'source' => 'clinic', 'target' => 'prescription_decision', 'data' => array_merge($edgeMetrics['clinic-prescription_decision'] ?? $defaultEdgeMetrics, ['isMainFlow' => true])],
            ['id' => 'prescription_decision-prescription', 'source' => 'prescription_decision', 'target' => 'prescription', 'data' => array_merge($edgeMetrics['prescription_decision-prescription'] ?? $defaultEdgeMetrics, ['isMainFlow' => true])],
            ['id' => 'prescription-exit', 'source' => 'prescription', 'target' => 'exit', 'data' => array_merge($edgeMetrics['prescription-exit'] ?? $defaultEdgeMetrics, ['isMainFlow' => true])],
            
            // Test to result paths
            ['id' => 'examination2-blood_test', 'source' => 'examination2', 'target' => 'blood_test', 'data' => array_merge($edgeMetrics['examination2-blood_test'] ?? $defaultEdgeMetrics, ['isResultFlow' => true])],
            ['id' => 'blood_test-blood_result', 'source' => 'blood_test', 'target' => 'blood_result', 'data' => array_merge($edgeMetrics['blood_test-blood_result'] ?? $defaultEdgeMetrics, ['isResultFlow' => true])],
            ['id' => 'blood_result-laboratory', 'source' => 'blood_result', 'target' => 'laboratory', 'data' => array_merge($edgeMetrics['blood_result-laboratory'] ?? $defaultEdgeMetrics, ['isResultFlow' => true])],
            
            ['id' => 'laboratory-enzyme_test', 'source' => 'laboratory', 'target' => 'enzyme_test', 'data' => array_merge($edgeMetrics['laboratory-enzyme_test'] ?? $defaultEdgeMetrics, ['isResultFlow' => true])],
            ['id' => 'enzyme_test-enzyme_result', 'source' => 'enzyme_test', 'target' => 'enzyme_result', 'data' => array_merge($edgeMetrics['enzyme_test-enzyme_result'] ?? $defaultEdgeMetrics, ['isResultFlow' => true])],
            ['id' => 'enzyme_result-clinic', 'source' => 'enzyme_result', 'target' => 'clinic', 'data' => array_merge($edgeMetrics['enzyme_result-clinic'] ?? $defaultEdgeMetrics, ['isResultFlow' => true])],
            
            ['id' => 'clinic-additional_test', 'source' => 'clinic', 'target' => 'additional_test', 'data' => array_merge($edgeMetrics['clinic-additional_test'] ?? $defaultEdgeMetrics, ['isResultFlow' => true])],
            ['id' => 'additional_test-additional_result', 'source' => 'additional_test', 'target' => 'additional_result', 'data' => array_merge($edgeMetrics['additional_test-additional_result'] ?? $defaultEdgeMetrics, ['isResultFlow' => true])],
            ['id' => 'additional_result-prescription_decision', 'source' => 'additional_result', 'target' => 'prescription_decision', 'data' => array_merge($edgeMetrics['additional_result-prescription_decision'] ?? $defaultEdgeMetrics, ['isResultFlow' => true])],
        ];

        // Calculate overall metrics
        $overallMetrics = [
            'totalPatients' => count(array_unique(array_column($rawData, 'case_id'))),
            'avgTotalTime' => $this->calculateAverageTotalTime($rawData),
            'activeCases' => count(array_filter($rawData, fn($event) => $event['activity'] !== 'Exit')),
            'completedToday' => count(array_filter($rawData, fn($event) => $event['activity'] === 'Exit')),
        ];

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'metrics' => $overallMetrics,
        ];
    }

    private function calculateNodeMetrics($rawData, $activityNodeMap)
    {
        $metrics = [];
        $activities = array_column($rawData, 'activity');
        $uniqueActivities = array_unique($activities);

        foreach ($uniqueActivities as $activity) {
            $activityEvents = array_filter($rawData, fn($event) => $event['activity'] === $activity);
            $nodeId = $activityNodeMap[trim($activity)] ?? strtolower(str_replace(' ', '_', $activity));
            $metrics[$nodeId] = [
                'count' => count($activityEvents),
                'avgTime' => $this->calculateAverageTime($activityEvents),
                'cohorts' => $this->calculateCohortMetrics($activityEvents),
            ];
        }

        return $metrics;
    }

    private function calculateEdgeMetrics($rawData, $activityNodeMap)
    {
        $metrics = [];
        $cases = array_unique(array_column($rawData, 'case_id'));

        foreach ($cases as $caseId) {
            $caseEvents = array_filter($rawData, fn($event) => $event['case_id'] === $caseId);
            usort($caseEvents, fn($a, $b) => strcmp($a['timestamp'], $b['timestamp']));

            for ($i = 0; $i < count($caseEvents) - 1; $i++) {
                $source = $activityNodeMap[trim($caseEvents[$i]['activity'])] ?? 
                         strtolower(str_replace(' ', '_', $caseEvents[$i]['activity']));
                $target = $activityNodeMap[trim($caseEvents[$i + 1]['activity'])] ?? 
                         strtolower(str_replace(' ', '_', $caseEvents[$i + 1]['activity']));
                $key = "$source-$target";

                if (!isset($metrics[$key])) {
                    $metrics[$key] = [
                        'patientCount' => 0,
                        'totalTime' => 0,
                        'cohortMetrics' => [],
                    ];
                }

                $metrics[$key]['patientCount']++;
                $metrics[$key]['totalTime'] += strtotime($caseEvents[$i + 1]['timestamp']) - strtotime($caseEvents[$i]['timestamp']);
            }
        }

        // Calculate averages and format times
        foreach ($metrics as &$metric) {
            $metric['avgTime'] = $this->formatDuration($metric['totalTime'] / $metric['patientCount']);
            unset($metric['totalTime']);
        }

        return $metrics;
    }

    private function calculateCohortMetrics($events)
    {
        $cohorts = [
            'urgent' => array_filter($events, fn($event) => $event['duration_mins'] <= 15),
            'standard' => array_filter($events, fn($event) => $event['duration_mins'] > 15 && $event['duration_mins'] <= 30),
            'delayed' => array_filter($events, fn($event) => $event['duration_mins'] > 30),
        ];

        $metrics = [];
        foreach ($cohorts as $name => $cohortEvents) {
            if (count($cohortEvents) > 0) {
                $metrics[$name] = [
                    'count' => count($cohortEvents),
                    'avgTime' => $this->calculateAverageTime($cohortEvents),
                ];
            }
        }

        return $metrics;
    }

    private function calculateAverageTime($events)
    {
        if (empty($events)) return '0m';
        $total = array_sum(array_column($events, 'duration_mins'));
        return $this->formatDuration($total / count($events) * 60); // Convert minutes to seconds
    }

    private function calculateAverageTotalTime($rawData)
    {
        $cases = array_unique(array_column($rawData, 'case_id'));
        $totalTime = 0;
        $count = 0;

        foreach ($cases as $caseId) {
            $caseEvents = array_filter($rawData, fn($event) => $event['case_id'] === $caseId);
            if (count($caseEvents) > 1) {
                $timestamps = array_column($caseEvents, 'timestamp');
                $totalTime += strtotime(max($timestamps)) - strtotime(min($timestamps));
                $count++;
            }
        }

        return $this->formatDuration($totalTime / max(1, $count));
    }

    private function formatDuration($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }
        return "{$minutes}m";
    }

    private function generateNursingData()
    {
        $baseDate = Carbon::now()->startOfDay();
        $numPatients = 28;
        
        // Define possible activities
        $coreActivities = [
            'Admission',
            'Initial Assessment',
            'Vital Signs',
            'Doctor Round',
            'Medication Administration',
            'Nursing Assessment',
            'Care Plan Update'
        ];
        
        $additionalActivities = [
            'Blood Draw',
            'IV Change',
            'Physical Therapy',
            'Imaging',
            'Specialist Consultation',
            'Pain Assessment',
            'Family Meeting'
        ];
        
        $units = ['Medical', 'Surgical', 'Telemetry', 'ICU'];
        
        $allEvents = [];
        
        // Generate data for each patient
        for ($patientId = 1; $patientId <= $numPatients; $patientId++) {
            $unit = $units[array_rand($units)];
            
            // Random admission time
            $admissionTime = $baseDate->copy()->addHours(rand(0, 23))->addMinutes(rand(0, 59));
            $currentTime = $admissionTime->copy();
            
            // Generate core activities
            foreach ($coreActivities as $activity) {
                $currentTime = $currentTime->addMinutes(rand(15, 45));
                
                $allEvents[] = [
                    'case_id' => sprintf('P%03d', $patientId),
                    'activity' => $activity,
                    'timestamp' => $currentTime->format('Y-m-d H:i:s'),
                    'unit' => $unit,
                    'resource' => sprintf('Nurse_%02d', rand(1, 10)),
                    'duration_mins' => rand(10, 30)
                ];
            }
            
            // Add random additional activities
            $numAdditional = rand(2, 5);
            $selectedActivities = array_rand(array_flip($additionalActivities), $numAdditional);
            if (!is_array($selectedActivities)) {
                $selectedActivities = [$selectedActivities];
            }
            
            foreach ($selectedActivities as $activity) {
                $activityTime = $admissionTime->copy()->addMinutes(rand(60, 1380));
                
                $allEvents[] = [
                    'case_id' => sprintf('P%03d', $patientId),
                    'activity' => $activity,
                    'timestamp' => $activityTime->format('Y-m-d H:i:s'),
                    'unit' => $unit,
                    'resource' => sprintf('Nurse_%02d', rand(1, 10)),
                    'duration_mins' => rand(15, 45)
                ];
            }
        }
        
        // Sort events by timestamp
        usort($allEvents, function($a, $b) {
            return strcmp($a['timestamp'], $b['timestamp']);
        });
        
        // Add process variants
        // Urgent cases (shorter durations)
        $urgentCases = array_rand(array_unique(array_column($allEvents, 'case_id')), 3);
        foreach ($allEvents as &$event) {
            if (in_array(array_search($event['case_id'], array_unique(array_column($allEvents, 'case_id'))), $urgentCases)) {
                $event['duration_mins'] = max(5, intval($event['duration_mins'] * 0.6));
            }
        }
        
        // Delayed cases (longer durations)
        $nonUrgentCases = array_diff(
            range(0, count(array_unique(array_column($allEvents, 'case_id'))) - 1),
            $urgentCases
        );
        $delayedCases = array_rand(array_flip($nonUrgentCases), 4);
        foreach ($allEvents as &$event) {
            if (in_array(array_search($event['case_id'], array_unique(array_column($allEvents, 'case_id'))), $delayedCases)) {
                $event['duration_mins'] = intval($event['duration_mins'] * 1.5);
            }
        }
        
        return $allEvents;
    }
}
