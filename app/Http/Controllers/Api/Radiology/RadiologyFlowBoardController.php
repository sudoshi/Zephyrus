<?php

namespace App\Http\Controllers\Api\Radiology;

use App\Http\Controllers\Controller;
use App\Http\Requests\Radiology\IrSuiteAnalyticsRequest;
use App\Http\Requests\Radiology\RadiologyFlowBoardRequest;
use App\Http\Requests\Radiology\RadiologyModalityUtilizationRequest;
use App\Http\Requests\Radiology\RadiologyReadsRequest;
use App\Http\Requests\Radiology\RadiologyTatAnalyticsRequest;
use App\Http\Requests\Radiology\RadiologyWorklistRequest;
use App\Http\Requests\Radiology\StoreRadiologyBarrierRequest;
use App\Services\Radiology\IrSuiteAnalyticsService;
use App\Services\Radiology\ModalityUtilizationService;
use App\Services\Radiology\RadiologyBarrierService;
use App\Services\Radiology\RadiologyFlowBoardService;
use App\Services\Radiology\RadiologyReadsService;
use App\Services\Radiology\RadiologyTatAnalyticsService;
use App\Services\Radiology\RadiologyWorklistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class RadiologyFlowBoardController extends Controller
{
    public function show(RadiologyFlowBoardRequest $request, RadiologyFlowBoardService $flowBoard): JsonResponse
    {
        return response()->json($flowBoard->build(
            $request->validated(),
            Gate::allows('manageAncillaryBarriers'),
            Gate::allows('viewAncillaryPatientDetail'),
        ))
            ->withHeaders(['Cache-Control' => 'private, no-cache']);
    }

    public function storeBarrier(StoreRadiologyBarrierRequest $request, RadiologyBarrierService $barriers): JsonResponse
    {
        return response()->json(['data' => $barriers->open($request->validated(), $request)], 201);
    }

    public function worklist(RadiologyWorklistRequest $request, RadiologyWorklistService $worklist): JsonResponse
    {
        return response()->json($worklist->build(
            $request->validated(),
            Gate::allows('viewAncillaryPatientDetail'),
        ))
            ->withHeaders(['Cache-Control' => 'private, no-cache']);
    }

    public function modality(RadiologyModalityUtilizationRequest $request, ModalityUtilizationService $utilization): JsonResponse
    {
        return response()->json($utilization->build($request->validated()))
            ->withHeaders(['Cache-Control' => 'private, no-cache']);
    }

    public function reads(RadiologyReadsRequest $request, RadiologyReadsService $reads): JsonResponse
    {
        return response()->json($reads->build(
            $request->validated(),
            Gate::allows('viewAncillaryPatientDetail'),
        ))
            ->withHeaders(['Cache-Control' => 'private, no-cache']);
    }

    public function tat(RadiologyTatAnalyticsRequest $request, RadiologyTatAnalyticsService $analytics): JsonResponse
    {
        return response()->json($analytics->build($request->validated()))
            ->withHeaders(['Cache-Control' => 'private, no-cache']);
    }

    public function irSuite(IrSuiteAnalyticsRequest $request, IrSuiteAnalyticsService $analytics): JsonResponse
    {
        return response()->json($analytics->build($request->validated()))
            ->withHeaders(['Cache-Control' => 'private, no-cache']);
    }
}
