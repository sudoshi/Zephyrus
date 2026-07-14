<?php

namespace App\Http\Controllers\Api\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\PharmacyFlowBoardRequest;
use App\Http\Requests\Pharmacy\PharmacyTatAnalyticsRequest;
use App\Http\Requests\Pharmacy\StorePharmacyBarrierRequest;
use App\Services\Pharmacy\PharmacyBarrierService;
use App\Services\Pharmacy\PharmacyFlowBoardService;
use App\Services\Pharmacy\PharmacyTatAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class PharmacyFlowBoardController extends Controller
{
    public function show(PharmacyFlowBoardRequest $request, PharmacyFlowBoardService $flowBoard): JsonResponse
    {
        return response()->json($flowBoard->build($request->validated(), Gate::allows('manageAncillaryBarriers'), Gate::allows('viewAncillaryPatientDetail')))
            ->withHeaders(['Cache-Control' => 'private, no-cache']);
    }

    public function tat(PharmacyTatAnalyticsRequest $request, PharmacyTatAnalyticsService $analytics): JsonResponse
    {
        return response()->json($analytics->build($request->validated()))
            ->withHeaders(['Cache-Control' => 'private, no-cache']);
    }

    public function storeBarrier(StorePharmacyBarrierRequest $request, PharmacyBarrierService $barriers): JsonResponse
    {
        return response()->json(['data' => $barriers->open($request->validated(), $request)], 201);
    }
}
