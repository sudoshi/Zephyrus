<?php

namespace App\Http\Controllers;

use App\Http\Helpers\ApiResponse;
use App\Http\Requests\GetProcessLayoutRequest;
use App\Http\Requests\SaveProcessLayoutRequest;
use App\Http\Requests\SaveViewportRequest;
use App\Services\ProcessAnalysisService;
use App\Services\ProcessLayoutService;
use App\Support\Hospital\HospitalManifest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ProcessAnalysisController extends Controller
{
    public function __construct(
        private readonly ProcessAnalysisService $processAnalysisService,
        private readonly ProcessLayoutService $processLayoutService,
    ) {}

    /**
     * Display the process analysis page.
     */
    public function index(): InertiaResponse
    {
        $savedLayout = $this->processAnalysisService->getSavedLayout(
            Auth::id(),
            request('hospital', app(HospitalManifest::class)->primaryNetworkFacilityName()),
            request('workflow', 'Admissions'),
            request('timeRange', '24 Hours'),
        );

        return Inertia::render('Improvement/Process', [
            'savedLayout' => $savedLayout,
        ]);
    }

    /**
     * Get nursing operations data for process visualization.
     */
    public function getNursingOperations(Request $request): JsonResponse
    {
        $hospital = $request->query('hospital', app(HospitalManifest::class)->primaryNetworkFacilityName());
        $workflow = $request->query('workflow', 'Admissions');
        $timeRange = $request->query('timeRange', '24 Hours');

        $data = $this->processAnalysisService->getNursingOperations($hospital, $workflow, $timeRange);

        return response()->json($data);
    }

    /**
     * Save the process layout.
     */
    public function saveLayout(SaveProcessLayoutRequest $request): Response|JsonResponse
    {
        try {
            $this->processLayoutService->saveLayout(Auth::id(), $request->validated());

            return response()->noContent();
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * Get the saved process layout.
     */
    public function getLayout(GetProcessLayoutRequest $request): JsonResponse
    {
        $result = $this->processLayoutService->getLayout(Auth::id(), $request->validated());

        return response()->json($result);
    }

    /**
     * Save the viewport state for a process.
     */
    public function saveViewport(SaveViewportRequest $request): Response|JsonResponse
    {
        try {
            $this->processLayoutService->saveViewport(Auth::id(), $request->validated());

            return response()->noContent();
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 500);
        }
    }
}
