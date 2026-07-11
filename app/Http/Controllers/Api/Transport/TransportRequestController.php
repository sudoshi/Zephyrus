<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Concerns\RequiresIdempotencyKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transport\AssignTransportRequestRequest;
use App\Http\Requests\Transport\CancelTransportRequestRequest;
use App\Http\Requests\Transport\CompleteHandoffRequest;
use App\Http\Requests\Transport\CreateTransportRequestRequest;
use App\Http\Requests\Transport\TransportStatusUpdateRequest;
use App\Models\Transport\TransportRequest;
use App\Services\Transport\TransportOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransportRequestController extends Controller
{
    use RequiresIdempotencyKey;

    public function __construct(private readonly TransportOperationsService $transport) {}

    public function overview(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->transport->overview($request->user())]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'request_type' => ['nullable', Rule::in(['inpatient', 'transfer', 'discharge', 'ems', 'care_transition'])],
            'status' => ['nullable', Rule::in(array_keys(\App\Services\Transport\TransportLifecycleService::TRANSITIONS))],
            'priority' => ['nullable', Rule::in(['routine', 'urgent', 'stat'])],
            'scope' => ['nullable', Rule::in(['active', 'dispatch', 'history'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:1000'],
        ]);
        $page = $this->transport->list($filters);

        return response()->json([
            'data' => collect($page->items())
                ->map(fn (TransportRequest $transportRequest) => $this->transport->serializeRequest($transportRequest, $request->user()))
                ->values(),
            'meta' => [
                'per_page' => $page->perPage(),
                'count' => $page->count(),
                'has_more' => $page->hasMorePages(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'previous_cursor' => $page->previousCursor()?->encode(),
            ],
            'links' => [
                'next' => $page->nextPageUrl(),
                'previous' => $page->previousPageUrl(),
            ],
        ]);
    }

    public function store(CreateTransportRequestRequest $request): JsonResponse
    {
        $transportRequest = $this->transport->create(
            $request->validated(),
            $request->user(),
            $this->requireIdempotencyKey($request),
        );

        return response()->json(['data' => $this->transport->serializeRequest($transportRequest, $request->user())], 201);
    }

    public function show(int $transportRequestId, Request $request): JsonResponse
    {
        $transportRequest = TransportRequest::with('events')
            ->where('is_deleted', false)
            ->findOrFail($transportRequestId);

        return response()->json([
            'data' => array_merge($this->transport->serializeRequest($transportRequest, $request->user()), [
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
        $updated = $this->transport->assign(
            $transportRequest,
            $request->validated(),
            $request->user(),
            $this->requireIdempotencyKey($request),
        );

        return response()->json(['data' => $this->transport->serializeRequest($updated, $request->user())]);
    }

    public function status(int $transportRequestId, TransportStatusUpdateRequest $request): JsonResponse
    {
        $transportRequest = TransportRequest::where('is_deleted', false)->findOrFail($transportRequestId);
        $validated = $request->validated();
        $payload = array_merge($validated['payload'] ?? [], [
            'note' => $validated['note'] ?? null,
            'reason' => $validated['reason'] ?? null,
        ]);
        $updated = $this->transport->transition(
            $transportRequest,
            $validated['status'],
            $payload,
            $request->user(),
            $this->requireIdempotencyKey($request),
        );

        return response()->json(['data' => $this->transport->serializeRequest($updated, $request->user())]);
    }

    public function cancel(int $transportRequestId, CancelTransportRequestRequest $request): JsonResponse
    {
        $transportRequest = TransportRequest::where('is_deleted', false)->findOrFail($transportRequestId);
        $updated = $this->transport->transition($transportRequest, 'canceled', [
            'reason' => $request->validated('reason'),
        ], $request->user(), $this->requireIdempotencyKey($request));

        return response()->json(['data' => $this->transport->serializeRequest($updated, $request->user())]);
    }

    public function handoff(int $transportRequestId, CompleteHandoffRequest $request): JsonResponse
    {
        $transportRequest = TransportRequest::where('is_deleted', false)->findOrFail($transportRequestId);
        $updated = $this->transport->completeHandoff(
            $transportRequest,
            $request->validated(),
            $request->user(),
            $this->requireIdempotencyKey($request),
        );

        return response()->json(['data' => $this->transport->serializeRequest($updated, $request->user())]);
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
