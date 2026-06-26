<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Models\Transport\TransportRequest;
use App\Services\Transport\RegionalTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RegionalTransferController extends Controller
{
    public function __construct(private readonly RegionalTransferService $regionalTransfers) {}

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->regionalTransfers->summary()]);
    }

    public function decide(int $transportRequestId, Request $request): JsonResponse
    {
        $transportRequest = TransportRequest::where('is_deleted', false)->findOrFail($transportRequestId);
        $validated = $request->validate([
            'selected_facility_code' => ['required', 'string', 'max:120'],
            'decision_status' => ['required', Rule::in(['draft', 'accepted', 'redirected', 'deferred'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json([
            'data' => $this->regionalTransfers->decide($transportRequest, $validated, $request->user()?->id),
        ]);
    }

    public function simulate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model_version_key' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'data' => $this->regionalTransfers->runRouteSimulation($validated['model_version_key'] ?? null, $request->user()?->id),
        ]);
    }

    public function agentDraft(int $transportRequestId, Request $request): JsonResponse
    {
        $transportRequest = TransportRequest::where('is_deleted', false)->findOrFail($transportRequestId);

        return response()->json([
            'data' => $this->regionalTransfers->draftWithAgent($transportRequest, $request->user()?->id),
        ]);
    }
}
