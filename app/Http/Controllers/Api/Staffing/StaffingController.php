<?php

namespace App\Http\Controllers\Api\Staffing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staffing\AssignStaffingRequestRequest;
use App\Http\Requests\Staffing\CreateStaffingRequestRequest;
use App\Http\Requests\Staffing\StaffingStatusUpdateRequest;
use App\Models\Staffing\StaffingPlan;
use App\Models\Staffing\StaffingRequest;
use App\Services\Staffing\StaffingOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffingController extends Controller
{
    public function __construct(private readonly StaffingOperationsService $staffing) {}

    public function overview(): JsonResponse
    {
        return response()->json(['data' => $this->staffing->overview()]);
    }

    public function plans(): JsonResponse
    {
        $plans = $this->staffing->todaysPlans();

        return response()->json([
            'data' => [
                'coverage' => $this->staffing->coverageSummary($plans),
                'units_at_risk' => $this->staffing->unitsAtRisk($plans),
                'plans' => $plans->map(fn (StaffingPlan $plan) => $this->staffing->serializePlan($plan))->values(),
            ],
        ]);
    }

    public function workforce(Request $request): JsonResponse
    {
        return response()->json($this->staffing->workforceDirectory($request->only([
            'q', 'role', 'shift', 'status', 'page', 'per_page',
        ])));
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->staffing->list($request->only(['role', 'status', 'priority', 'unit_id']));

        return response()->json([
            'data' => collect($page->items())->map(fn (StaffingRequest $req) => $this->staffing->serializeRequest($req))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(CreateStaffingRequestRequest $request): JsonResponse
    {
        $staffingRequest = $this->staffing->create($request->validated(), $request->user()?->id);

        return response()->json(['data' => $this->staffing->serializeRequest($staffingRequest)], 201);
    }

    public function show(int $staffingRequestId): JsonResponse
    {
        $staffingRequest = StaffingRequest::with('events')
            ->where('is_deleted', false)
            ->findOrFail($staffingRequestId);

        return response()->json([
            'data' => array_merge($this->staffing->serializeRequest($staffingRequest), [
                'events' => $staffingRequest->events()
                    ->orderByDesc('occurred_at')
                    ->get()
                    ->map(fn ($event) => [
                        'staffing_event_id' => $event->staffing_event_id,
                        'event_type' => $event->event_type,
                        'from_status' => $event->from_status,
                        'to_status' => $event->to_status,
                        'payload' => $event->payload ?? [],
                        'source' => $event->source,
                        'occurred_at' => $event->occurred_at?->toISOString(),
                    ]),
            ]),
        ]);
    }

    public function assign(int $staffingRequestId, AssignStaffingRequestRequest $request): JsonResponse
    {
        $staffingRequest = StaffingRequest::where('is_deleted', false)->findOrFail($staffingRequestId);
        $updated = $this->staffing->assign($staffingRequest, $request->validated(), $request->user()?->id);

        return response()->json(['data' => $this->staffing->serializeRequest($updated)]);
    }

    public function status(int $staffingRequestId, StaffingStatusUpdateRequest $request): JsonResponse
    {
        $staffingRequest = StaffingRequest::where('is_deleted', false)->findOrFail($staffingRequestId);
        $validated = $request->validated();
        $payload = array_merge($validated['payload'] ?? [], ['note' => $validated['note'] ?? null]);
        $updated = $this->staffing->transition($staffingRequest, $validated['status'], $payload, $request->user()?->id);

        return response()->json(['data' => $this->staffing->serializeRequest($updated)]);
    }

    public function cancel(int $staffingRequestId, Request $request): JsonResponse
    {
        $staffingRequest = StaffingRequest::where('is_deleted', false)->findOrFail($staffingRequestId);
        $updated = $this->staffing->transition($staffingRequest, 'canceled', [
            'reason' => $request->input('reason'),
        ], $request->user()?->id);

        return response()->json(['data' => $this->staffing->serializeRequest($updated)]);
    }

    public function resources(): JsonResponse
    {
        return response()->json(['data' => $this->staffing->resourceOptions()]);
    }
}
