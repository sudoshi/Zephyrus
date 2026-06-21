<?php

namespace App\Http\Controllers\Api\Rtdc;

use App\Events\Rtdc\HuddleUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rtdc\UpsertCapacityRequest;
use App\Http\Requests\Rtdc\UpsertDemandRequest;
use App\Models\RtdcPrediction;
use App\Services\RtdcService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PredictionController extends Controller
{
    public function __construct(private readonly RtdcService $rtdc) {}

    public function show(int $unitId, Request $request): JsonResponse
    {
        $pred = RtdcPrediction::where('unit_id', $unitId)
            ->whereDate('service_date', $request->query('service_date', today()->toDateString()))
            ->where('horizon', $request->query('horizon', 'by_2pm'))
            ->first();

        return response()->json(['data' => $pred]);
    }

    public function capacity(int $unitId, UpsertCapacityRequest $request): JsonResponse
    {
        $v = $request->validated();
        $pred = $this->rtdc->upsertCapacity($unitId, $v['service_date'], $v['horizon'], $v['definite'], $v['probable'], $v['possible']);
        broadcast(new HuddleUpdated($unitId, $pred->toArray()));

        return response()->json(['data' => $pred]);
    }

    public function demand(int $unitId, UpsertDemandRequest $request): JsonResponse
    {
        $v = $request->validated();
        $pred = $this->rtdc->upsertDemand($unitId, $v['service_date'], $v['horizon'], $v['ed'], $v['or'], $v['transfer'], $v['direct']);
        broadcast(new HuddleUpdated($unitId, $pred->toArray()));

        return response()->json(['data' => $pred]);
    }

    public function plan(int $unitId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_date' => 'required|date',
            'horizon' => 'required|in:by_2pm,by_midnight',
        ]);
        $pred = $this->rtdc->developPlan($unitId, $validated['service_date'], $validated['horizon']);
        broadcast(new HuddleUpdated($unitId, $pred->toArray()));

        return response()->json(['data' => $pred]);
    }
}
