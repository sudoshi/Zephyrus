<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\AuthorizesMobilePersonaActions;
use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Models\Staffing\StaffingRequest;
use App\Services\Mobile\MobilePersonaCatalog;
use App\Services\Mobile\OperationalActivityLedger;
use App\Services\Staffing\CanonicalStaffingService;
use App\Services\Staffing\StaffingOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staffing Coordinator (P10) — GET /api/mobile/v1/staffing/overview (read),
 * POST /api/mobile/v1/staffing/requests/{id}/fill (mobile:act).
 * Reads reuse StaffingOperationsService::overview() (already mobile-shaped: metrics, coverage,
 * units_at_risk, queue). The fill action uses the canonical qualified/available fulfillment path.
 */
class StaffingController extends Controller
{
    use AuthorizesMobilePersonaActions;
    use RendersMobileEnvelope;

    public function __construct(
        private readonly StaffingOperationsService $staffing,
        private readonly CanonicalStaffingService $canonicalStaffing,
        private readonly OperationalActivityLedger $ledger,
        private readonly MobilePersonaCatalog $personas,
    ) {}

    public function overview(): JsonResponse
    {
        return $this->envelope($this->staffing->overview(), links: ['web' => url('/staffing')]);
    }

    public function candidates(Request $request, int $id): JsonResponse
    {
        $this->authorizeMobilePersonaAction($request, ['staffing_coordinator'], 'view staffing candidates');
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'eligible_only' => ['sometimes', 'boolean'],
        ]);
        $staffingRequest = StaffingRequest::query()
            ->where('staffing_request_id', $id)
            ->where('is_deleted', false)
            ->firstOrFail();

        return $this->envelope(
            $this->canonicalStaffing->candidates($staffingRequest, $validated),
            links: ['web' => url('/staffing')],
        );
    }

    public function fill(Request $request, int $id): JsonResponse
    {
        $actorRole = $this->authorizeMobilePersonaAction($request, ['staffing_coordinator'], 'fill staffing requests');

        $validated = $request->validate([
            'staff_member_id' => ['required', 'integer', 'min:1'],
            'assigned_source' => ['required', 'in:float_pool,overtime,agency,on_call'],
            'owner_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $req = StaffingRequest::where('staffing_request_id', $id)->where('is_deleted', false)->firstOrFail();
        $actorId = $request->user()?->id;
        $from = $req->status;
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 200 || preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey) !== 1) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A 1-200 character Idempotency-Key header using letters, numbers, dot, underscore, colon, or hyphen is required.',
            ]);
        }
        $eventAttributes = [
            'idempotency_key' => $idempotencyKey,
            'actor_user_id' => $actorId,
            'actor_role' => $actorRole,
            'domain' => 'staffing',
            'scope' => [
                'unit_id' => $req->unit_id,
                'staffing_request_id' => $req->staffing_request_id,
            ],
            'idempotency_payload' => [
                'staff_member_id' => (int) $validated['staff_member_id'],
                'assigned_source' => $validated['assigned_source'],
                'owner_name' => $validated['owner_name'] ?? null,
            ],
        ];

        [$filled, $fulfillment] = DB::transaction(function () use ($actorId, $eventAttributes, $from, $idempotencyKey, $req, $validated): array {
            $fulfillment = $this->canonicalStaffing->directFill(
                $req,
                (int) $validated['staff_member_id'],
                (string) $validated['assigned_source'],
                $actorId,
                $idempotencyKey,
            );
            $filled = $req->fresh();
            if (array_key_exists('owner_name', $validated)) {
                $filled->update([
                    'owner_name' => $validated['owner_name'],
                    'updated_by_user_id' => $actorId,
                ]);
                $filled->refresh();
            }
            $this->ledger->record('staffing.request_filled', [
                ...$eventAttributes,
                'status' => [
                    'previous' => $from,
                    'current' => $filled->status,
                    'severity' => 'info',
                ],
                'payload' => [
                    'unit_label' => $filled->unit_label,
                    'role' => $filled->role,
                    'staff_member_id' => (int) $validated['staff_member_id'],
                    'assigned_source' => $validated['assigned_source'],
                    'fulfillment_uuid' => $fulfillment['fulfillment_uuid'],
                ],
                'entities' => [[
                    'entity_type' => 'staffing_request',
                    'entity_ref' => (string) $filled->staffing_request_id,
                ]],
            ]);

            return [$filled, $fulfillment];
        });

        return $this->envelope(array_merge(
            $this->staffing->serializeRequest($filled),
            ['canonical_fulfillment' => $fulfillment],
        ), links: ['web' => url('/staffing')]);
    }
}
