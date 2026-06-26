<?php

namespace App\Http\Controllers\Api\Evs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Evs\AssignEvsRequestRequest;
use App\Http\Requests\Evs\CreateEvsRequestRequest;
use App\Http\Requests\Evs\EvsStatusUpdateRequest;
use App\Models\Evs\EvsRequest;
use App\Services\Evs\EvsOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvsRequestController extends Controller
{
    public function __construct(private readonly EvsOperationsService $evs) {}

    public function overview(): JsonResponse
    {
        return response()->json(['data' => $this->evs->overview()]);
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->evs->list($request->only(['request_type', 'status', 'priority']));

        return response()->json([
            'data' => collect($page->items())->map(fn (EvsRequest $evsRequest) => $this->evs->serializeRequest($evsRequest))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(CreateEvsRequestRequest $request): JsonResponse
    {
        $evsRequest = $this->evs->create($request->validated(), $request->user()?->id);

        return response()->json(['data' => $this->evs->serializeRequest($evsRequest)], 201);
    }

    public function show(int $evsRequestId): JsonResponse
    {
        $evsRequest = EvsRequest::with('events')
            ->where('is_deleted', false)
            ->findOrFail($evsRequestId);

        return response()->json([
            'data' => array_merge($this->evs->serializeRequest($evsRequest), [
                'events' => $evsRequest->events()
                    ->orderByDesc('occurred_at')
                    ->get()
                    ->map(fn ($event) => [
                        'evs_event_id' => $event->evs_event_id,
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

    public function assign(int $evsRequestId, AssignEvsRequestRequest $request): JsonResponse
    {
        $evsRequest = EvsRequest::where('is_deleted', false)->findOrFail($evsRequestId);
        $updated = $this->evs->assign($evsRequest, $request->validated(), $request->user()?->id);

        return response()->json(['data' => $this->evs->serializeRequest($updated)]);
    }

    public function status(int $evsRequestId, EvsStatusUpdateRequest $request): JsonResponse
    {
        $evsRequest = EvsRequest::where('is_deleted', false)->findOrFail($evsRequestId);
        $validated = $request->validated();
        $payload = array_merge($validated['payload'] ?? [], ['note' => $validated['note'] ?? null]);
        $updated = $this->evs->transition($evsRequest, $validated['status'], $payload, $request->user()?->id);

        return response()->json(['data' => $this->evs->serializeRequest($updated)]);
    }

    public function cancel(int $evsRequestId, Request $request): JsonResponse
    {
        $evsRequest = EvsRequest::where('is_deleted', false)->findOrFail($evsRequestId);
        $updated = $this->evs->transition($evsRequest, 'canceled', [
            'reason' => $request->input('reason'),
        ], $request->user()?->id);

        return response()->json(['data' => $this->evs->serializeRequest($updated)]);
    }

    public function resources(): JsonResponse
    {
        return response()->json(['data' => $this->evs->resourceOptions()]);
    }
}
