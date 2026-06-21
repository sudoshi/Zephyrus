<?php

namespace App\Http\Controllers\Api\Rtdc;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rtdc\BedPlacementDecisionRequest;
use App\Http\Requests\Rtdc\CreateBedRequestRequest;
use App\Models\BedRequest;
use App\Services\BedPlacementService;
use Illuminate\Http\JsonResponse;

class BedRequestController extends Controller
{
    public function __construct(private readonly BedPlacementService $placement) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => BedRequest::pending()->orderBy('created_at')->get()]);
    }

    public function store(CreateBedRequestRequest $request): JsonResponse
    {
        $bedRequest = BedRequest::create($request->validated());

        return response()->json(['data' => $bedRequest]);
    }

    public function recommendations(int $bedRequestId): JsonResponse
    {
        $request = BedRequest::findOrFail($bedRequestId);

        return response()->json(['data' => $this->placement->recommend($request)->toArray()]);
    }

    public function decision(int $bedRequestId, BedPlacementDecisionRequest $request): JsonResponse
    {
        $bedRequest = BedRequest::findOrFail($bedRequestId);
        $v = $request->validated();
        $decision = $this->placement->decide(
            $bedRequest,
            $v['action'],
            $v['chosen_bed_id'] ?? null,
            $v['reason'] ?? null,
            $request->user()?->id,
        );

        return response()->json(['data' => $decision]);
    }
}
