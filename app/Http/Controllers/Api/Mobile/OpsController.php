<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\ReadsMobileIdempotencyKey;
use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Models\Ops\Approval;
use App\Services\Mobile\MobilePersonaCatalog;
use App\Services\Mobile\OperationalActivityLedger;
use App\Services\Ops\OperationalActionLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Capacity Lead (P6) — the operational approvals inbox.
 *   GET  /api/mobile/v1/ops/inbox                        (mobile:read)
 *   POST /api/mobile/v1/ops/approvals/{uuid}/decision    (mobile:act)
 *
 * Lists ALL pending human-approval actions (not just Eddy-sourced, unlike /eddy/approvals),
 * reshaping the recommendation title/rationale/risk PHI-free. The decision delegates to
 * OperationalActionLifecycleService::decideApproval() — the one governed write path.
 */
class OpsController extends Controller
{
    use ReadsMobileIdempotencyKey;
    use RendersMobileEnvelope;

    public function __construct(
        private readonly OperationalActionLifecycleService $lifecycle,
        private readonly OperationalActivityLedger $ledger,
        private readonly MobilePersonaCatalog $personas,
    ) {}

    public function inbox(Request $request): JsonResponse
    {
        $this->approvalPersona($request);

        $items = Approval::query()
            ->where('status', 'pending')
            ->with('action.recommendation')
            ->orderByDesc('requested_at')
            ->get()
            ->map(function (Approval $a) {
                $rec = $a->action?->recommendation;
                $visualStatus = match ($rec?->risk_level) {
                    'critical' => 'critical',
                    'high' => 'warning',
                    default => 'info',
                };

                return [
                    'approval_uuid' => $a->approval_uuid,
                    'title' => $rec?->title ?? 'Operational action',
                    'rationale' => $rec?->rationale,
                    'type' => $rec?->recommendation_type,
                    'risk' => $rec?->risk_level,
                    'tier' => $visualStatus,
                    'visual_status' => $visualStatus,
                    'owner' => $a->action?->owner_name,
                    'requested_at' => optional($a->requested_at)->toIso8601String(),
                ];
            })
            ->values();

        return $this->envelope($items, meta: ['count' => $items->count()], links: ['web' => url('/ops/agent-inbox')]);
    }

    public function decide(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $roleId = $this->approvalPersona($request);

        $approvalForReplay = Approval::with('action')->where('approval_uuid', $uuid)->firstOrFail();
        $actionForReplay = $approvalForReplay->action;
        $eventType = $validated['decision'] === 'approved' ? 'recommendation.approved' : 'recommendation.rejected';
        $eventAttributes = [
            'idempotency_key' => $this->mobileIdempotencyKey($request),
            'actor_user_id' => $request->user()?->id,
            'actor_role' => $roleId,
            'domain' => 'ops',
            'scope' => [
                'action_uuid' => $actionForReplay?->action_uuid,
                'approval_uuid' => $approvalForReplay->approval_uuid,
            ],
            'idempotency_payload' => [
                'decision' => $validated['decision'],
                'reason' => $validated['reason'] ?? null,
            ],
        ];

        if ($this->ledger->replay($eventType, $eventAttributes) !== null) {
            return $this->envelope([
                'approval_uuid' => $uuid,
                'decision' => $validated['decision'],
                'action_status' => $actionForReplay?->fresh()?->status,
            ], links: ['web' => url('/ops/agent-inbox')]);
        }

        $result = DB::transaction(function () use ($eventAttributes, $eventType, $request, $uuid, $validated): array {
            $approval = Approval::where('approval_uuid', $uuid)->where('status', 'pending')->firstOrFail();
            $action = $this->lifecycle->decideApproval($approval, $validated['decision'], $validated['reason'] ?? null, $request->user()?->id);
            $action->loadMissing('recommendation');

            $this->ledger->record($eventType, [
                ...$eventAttributes,
                'status' => [
                    'previous' => 'pending',
                    'current' => $validated['decision'],
                    'severity' => $validated['decision'] === 'approved' ? 'warning' : 'info',
                ],
                'recommendation' => [
                    'source' => $action->recommendation?->created_by_source,
                    'recommendation_uuid' => $action->recommendation?->recommendation_uuid,
                    'human_decision' => $validated['decision'],
                ],
                'payload' => [
                    'reason' => $validated['reason'] ?? null,
                    'action_type' => $action->action_type,
                ],
            ]);

            return [
                'approval_uuid' => $uuid,
                'decision' => $validated['decision'],
                'action_status' => $action->status,
            ];
        });

        return $this->envelope($result, links: ['web' => url('/ops/agent-inbox')]);
    }

    private function approvalPersona(Request $request): string
    {
        $roleId = $this->personas->fromRequest($request);

        if ($roleId === 'capacity_lead') {
            return $roleId;
        }

        $explicitPersona = $request->query('persona') !== null
            || $request->headers->has('X-Hummingbird-Role')
            || $request->input('persona') !== null;

        if (! $explicitPersona && $this->personas->isBroadAccessUser($request->user())) {
            return 'capacity_lead';
        }

        abort(403, 'Capacity lead persona is required for operational approval decisions.');
    }
}
