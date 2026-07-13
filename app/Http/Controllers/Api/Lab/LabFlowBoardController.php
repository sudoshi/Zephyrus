<?php

namespace App\Http\Controllers\Api\Lab;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lab\BloodBankReadinessRequest;
use App\Http\Requests\Lab\LabDecisionPendingRequest;
use App\Http\Requests\Lab\LabFlowBoardRequest;
use App\Http\Requests\Lab\LabSpecimenRequest;
use App\Http\Requests\Lab\StoreLabBarrierRequest;
use App\Services\Lab\BloodBankReadinessService;
use App\Services\Lab\LabBarrierService;
use App\Services\Lab\LabDecisionPendingService;
use App\Services\Lab\LabFlowBoardService;
use App\Services\Lab\LabSpecimenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class LabFlowBoardController extends Controller
{
    public function show(LabFlowBoardRequest $request, LabFlowBoardService $flowBoard): JsonResponse
    {
        return response()->json($flowBoard->build($request->validated(), Gate::allows('manageAncillaryBarriers'), Gate::allows('viewAncillaryPatientDetail')))
            ->withHeaders(['Cache-Control' => 'private, no-cache']);
    }

    public function storeBarrier(StoreLabBarrierRequest $request, LabBarrierService $barriers): JsonResponse
    {
        return response()->json(['data' => $barriers->open($request->validated(), $request)], 201);
    }

    public function specimens(LabSpecimenRequest $request, LabSpecimenService $specimens): JsonResponse
    {
        return response()->json($specimens->build($request->validated(), Gate::allows('viewAncillaryPatientDetail')))
            ->withHeaders(['Cache-Control' => 'private, no-cache']);
    }

    public function pendingDecisions(LabDecisionPendingRequest $request, LabDecisionPendingService $pending): JsonResponse
    {
        return response()->json($pending->build(
            $request->validated(),
            Gate::allows('manageAncillaryBarriers'),
            Gate::allows('viewAncillaryPatientDetail'),
        ))->withHeaders(['Cache-Control' => 'private, no-cache']);
    }

    public function bloodBank(BloodBankReadinessRequest $request, BloodBankReadinessService $bloodBank): JsonResponse
    {
        return response()->json($bloodBank->build($request->validated()))
            ->withHeaders(['Cache-Control' => 'private, no-cache']);
    }
}
