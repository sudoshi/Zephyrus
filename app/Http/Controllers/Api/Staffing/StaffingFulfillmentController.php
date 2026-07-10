<?php

namespace App\Http\Controllers\Api\Staffing;

use App\Http\Controllers\Controller;
use App\Models\Staffing\StaffingRequest;
use App\Services\Staffing\CanonicalStaffingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StaffingFulfillmentController extends Controller
{
    public function __construct(private readonly CanonicalStaffingService $staffing) {}

    public function candidates(Request $request, int $staffingRequestId): JsonResponse
    {
        $staffingRequest = $this->request($staffingRequestId);
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'eligible_only' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->staffing->candidates($staffingRequest, $validated));
    }

    public function index(int $staffingRequestId): JsonResponse
    {
        $staffingRequest = $this->request($staffingRequestId);

        return response()->json(['data' => $this->staffing->fulfillments($staffingRequest)]);
    }

    public function store(Request $request, int $staffingRequestId): JsonResponse
    {
        $validated = $request->validate([
            'staff_member_id' => ['required', 'integer', 'min:1'],
            'source' => ['required', 'in:float_pool,overtime,agency,on_call'],
        ]);
        $result = $this->staffing->offer(
            $this->request($staffingRequestId),
            (int) $validated['staff_member_id'],
            (string) $validated['source'],
            $request->user()?->id,
            $this->idempotencyKey($request),
        );

        return response()->json(['data' => $result], 201);
    }

    public function transition(Request $request, string $fulfillmentUuid): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:accepted,filled,released,canceled'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $result = $this->staffing->transition(
            $fulfillmentUuid,
            (string) $validated['status'],
            $validated['note'] ?? null,
            $request->user()?->id,
            $this->idempotencyKey($request),
        );

        return response()->json(['data' => $result]);
    }

    private function request(int $id): StaffingRequest
    {
        return StaffingRequest::query()->where('is_deleted', false)->findOrFail($id);
    }

    private function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '' || mb_strlen($key) > 200 || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A 1-200 character Idempotency-Key header using letters, numbers, dot, underscore, colon, or hyphen is required.',
            ]);
        }

        return $key;
    }
}
