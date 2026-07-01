<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Models\Staffing\StaffingRequest;
use App\Services\Staffing\StaffingOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staffing Coordinator (P10) — GET /api/mobile/v1/staffing/overview (read),
 * POST /api/mobile/v1/staffing/requests/{id}/fill (mobile:act).
 * Reads reuse StaffingOperationsService::overview() (already mobile-shaped: metrics, coverage,
 * units_at_risk, queue). The fill action assigns a source then marks the request filled.
 */
class StaffingController extends Controller
{
    use RendersMobileEnvelope;

    public function __construct(private readonly StaffingOperationsService $staffing) {}

    public function overview(): JsonResponse
    {
        return $this->envelope($this->staffing->overview(), links: ['web' => url('/staffing')]);
    }

    public function fill(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'assigned_source' => ['required', 'string', 'max:120'],
            'owner_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $req = StaffingRequest::where('staffing_request_id', $id)->where('is_deleted', false)->firstOrFail();
        $actorId = $request->user()?->id;

        $assigned = $this->staffing->assign($req, [
            'assigned_source' => $validated['assigned_source'],
            'owner_name' => $validated['owner_name'] ?? ($request->user()?->name),
        ], $actorId);

        $filled = $this->staffing->transition($assigned, 'filled', [
            'assigned_source' => $validated['assigned_source'],
        ], $actorId);

        return $this->envelope($this->staffing->serializeRequest($filled), links: ['web' => url('/staffing')]);
    }
}
