<?php

namespace App\Http\Controllers\Api\Rtdc;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rtdc\UpsertBarrierRequest;
use App\Models\Barrier;
use App\Services\BarrierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BarrierController extends Controller
{
    public function __construct(private readonly BarrierService $barriers) {}

    public function index(Request $request): JsonResponse
    {
        $query = Barrier::open();
        if ($request->filled('unit_id')) {
            $query->where('unit_id', $request->integer('unit_id'));
        }

        return response()->json(['data' => $query->orderBy('opened_at')->get()]);
    }

    public function store(UpsertBarrierRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->barriers->open($request->validated())]);
    }

    public function resolve(int $barrierId): JsonResponse
    {
        return response()->json(['data' => $this->barriers->resolve($barrierId)]);
    }
}
