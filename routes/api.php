<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\BlockScheduleController;
use App\Http\Controllers\Api\ORCaseController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\ServiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// OR Cases
Route::prefix('cases')->group(function () {
    Route::get('/', [ORCaseController::class, 'index']);
    Route::post('/', [ORCaseController::class, 'store']);
    Route::put('/{id}', [ORCaseController::class, 'update']);
    Route::get('/today', [ORCaseController::class, 'todaysCases']);
    Route::get('/metrics', [ORCaseController::class, 'metrics']);
    Route::get('/room-status', [ORCaseController::class, 'roomStatus']);
});

// Block Schedule
Route::prefix('blocks')->group(function () {
    Route::get('/', [BlockScheduleController::class, 'index']);
    Route::post('/', [BlockScheduleController::class, 'store']);
    Route::get('/utilization', [BlockScheduleController::class, 'utilization']);
    Route::get('/service-utilization', [BlockScheduleController::class, 'serviceUtilization']);
    Route::get('/room-utilization', [BlockScheduleController::class, 'roomUtilization']);
});

// Analytics
Route::prefix('analytics')->group(function () {
    Route::get('/service-performance', [AnalyticsController::class, 'servicePerformance']);
    Route::get('/provider-performance', [AnalyticsController::class, 'providerPerformance']);
    Route::get('/historical-trends', [AnalyticsController::class, 'historicalTrends']);
});

// Reference Data
Route::get('/services', [ServiceController::class, 'index']);
Route::get('/rooms', [RoomController::class, 'index']);
Route::get('/providers', [ProviderController::class, 'index']);

// Improvement Process Maps
Route::prefix('improvement')->group(function () {
    Route::get('/api/nursing-operations', function () {
        $workflow = request('workflow', 'Bed Placement');
        $format = request('format', 'mock_data');
        
        // Log the requested workflow for debugging
        error_log('API request for workflow: ' . $workflow . ', format: ' . $format);
        
        // ALWAYS use the bed_placement_process_map.json file regardless of the requested workflow
        // This ensures we always return our custom Bed Placement process map
        $jsonPath = base_path('sample-pages/OCEL/bed_placement_process_map.json');
        error_log('ALWAYS loading Bed Placement data from: ' . $jsonPath . ' regardless of requested workflow: ' . $workflow);
        
        if (file_exists($jsonPath)) {
            try {
                $jsonData = file_get_contents($jsonPath);
                $data = json_decode($jsonData, true);
                
                if (json_last_error() === JSON_ERROR_NONE && isset($data['nodes']) && isset($data['edges'])) {
                    error_log('Successfully loaded Bed Placement data with ' . count($data['nodes']) . ' nodes and ' . count($data['edges']) . ' edges');
                    return response()->json($data);
                } else {
                    error_log('JSON parsing error: ' . json_last_error_msg() . ' in: ' . $jsonPath);
                }
            } catch (\Exception $e) {
                error_log('Exception reading JSON: ' . $e->getMessage());
            }
        } else {
            error_log('Bed Placement JSON file not found at: ' . $jsonPath);
        }
        
        // If we get here, create a minimal mock data structure to return
        error_log('Creating fallback mock data structure for workflow: ' . $workflow);
        $fallbackData = [
            'nodes' => [
                [
                    'id' => 'node_0',
                    'label' => 'Emergency Department (ED)',
                    'type' => 'start',
                    'count' => 26,
                    'avgDuration' => 0.2,
                    'description' => 'Patient coming from emergency department'
                ],
                [
                    'id' => 'node_1',
                    'label' => 'Bed request initiated',
                    'type' => 'activity',
                    'count' => 50,
                    'avgDuration' => 11.0,
                    'description' => 'Request for inpatient bed is initiated in the system'
                ],
                [
                    'id' => 'node_2',
                    'label' => 'Patient arrived at bed',
                    'type' => 'end',
                    'count' => 50,
                    'avgDuration' => 10.7,
                    'description' => 'Patient has arrived at the assigned bed'
                ]
            ],
            'edges' => [
                [
                    'id' => 'edge_0',
                    'source' => 'node_0',
                    'target' => 'node_1',
                    'count' => 26,
                    'label' => '26 cases'
                ],
                [
                    'id' => 'edge_1',
                    'source' => 'node_1',
                    'target' => 'node_2',
                    'count' => 50,
                    'label' => '50 cases'
                ]
            ],
            'metrics' => [
                'totalCases' => 50,
                'avgDuration' => '21.9m',
                'bottleneckCount' => 1,
                'reworkPercentage' => '0%',
                'throughput' => '7/day',
                'complianceRate' => '100%',
                'variantCount' => 1
            ]
        ];
        
        return response()->json($fallbackData);
    });
});
