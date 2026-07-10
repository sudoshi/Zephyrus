<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transport\AssignTransportRequestRequest;
use App\Http\Requests\Transport\CompleteHandoffRequest;
use App\Http\Requests\Transport\CreateTransportRequestRequest;
use App\Http\Requests\Transport\TransportStatusUpdateRequest;
use App\Models\Transport\TransportRequest;
use App\Services\Transport\TransportOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransportRequestController extends Controller
{
    public function __construct(private readonly TransportOperationsService $transport) {}

    public function overview(): JsonResponse
    {
        return response()->json(['data' => $this->transport->overview()]);
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->transport->list($request->only(['request_type', 'status', 'priority', 'scope']));

        return response()->json([
            'data' => collect($page->items())->map(fn (TransportRequest $transportRequest) => $this->transport->serializeRequest($transportRequest))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(CreateTransportRequestRequest $request): JsonResponse
    {
        $transportRequest = $this->transport->create($request->validated(), $request->user()?->id);

        return response()->json(['data' => $this->transport->serializeRequest($transportRequest)], 201);
    }

    public function show(int $transportRequestId): JsonResponse
    {
        $transportRequest = TransportRequest::with('events')
            ->where('is_deleted', false)
            ->findOrFail($transportRequestId);

        return response()->json([
            'data' => array_merge($this->transport->serializeRequest($transportRequest), [
                'events' => $transportRequest->events()
                    ->orderByDesc('occurred_at')
                    ->get()
                    ->map(fn ($event) => [
                        'transport_event_id' => $event->transport_event_id,
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

    public function assign(int $transportRequestId, AssignTransportRequestRequest $request): JsonResponse
    {
        $transportRequest = TransportRequest::where('is_deleted', false)->findOrFail($transportRequestId);
        $updated = $this->transport->assign($transportRequest, $request->validated(), $request->user()?->id);

        return response()->json(['data' => $this->transport->serializeRequest($updated)]);
    }

    public function status(int $transportRequestId, TransportStatusUpdateRequest $request): JsonResponse
    {
        $transportRequest = TransportRequest::where('is_deleted', false)->findOrFail($transportRequestId);
        $validated = $request->validated();
        $payload = array_merge($validated['payload'] ?? [], ['note' => $validated['note'] ?? null]);
        $updated = $this->transport->transition($transportRequest, $validated['status'], $payload, $request->user()?->id);

        return response()->json(['data' => $this->transport->serializeRequest($updated)]);
    }

    public function cancel(int $transportRequestId, Request $request): JsonResponse
    {
        $transportRequest = TransportRequest::where('is_deleted', false)->findOrFail($transportRequestId);
        $updated = $this->transport->transition($transportRequest, 'canceled', [
            'reason' => $request->input('reason'),
        ], $request->user()?->id);

        return response()->json(['data' => $this->transport->serializeRequest($updated)]);
    }

    public function handoff(int $transportRequestId, CompleteHandoffRequest $request): JsonResponse
    {
        $transportRequest = TransportRequest::where('is_deleted', false)->findOrFail($transportRequestId);
        $updated = $this->transport->completeHandoff($transportRequest, $request->validated(), $request->user()?->id);

        return response()->json(['data' => $this->transport->serializeRequest($updated)]);
    }

    public function resources(): JsonResponse
    {
        return response()->json(['data' => $this->transport->resourceOptions()]);
    }

    public function vendors(): JsonResponse
    {
        return response()->json(['data' => $this->transport->vendorOptions()]);
    }
}
