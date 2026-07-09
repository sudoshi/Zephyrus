<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\AuthorizesMobilePersonaActions;
use App\Http\Concerns\ReadsMobileIdempotencyKey;
use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Models\Evs\EvsRequest;
use App\Services\Evs\EvsOperationsService;
use App\Services\Mobile\MobilePersonaCatalog;
use App\Services\Mobile\OperationalActivityLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * EVS / bed-turns — the frontline "next dirty bed" surface (P2, Wave 1).
 *
 *   GET  /api/mobile/v1/evs/queue                       (mobile:read)
 *   POST /api/mobile/v1/evs/requests/{id}/status        (mobile:act)
 *
 * Reads are PHI-minimized (no patient_ref / encounter_ref): just the bed location, turn type,
 * isolation flag, priority, lifecycle status, and SLA. Writes delegate to
 * {@see EvsOperationsService} verbatim — Claim (→assigned) → Start (→in_progress, stamps
 * started_at) → Complete (→completed). Completing a turn unblocks the bed for placement.
 */
class EvsController extends Controller
{
    use AuthorizesMobilePersonaActions;
    use ReadsMobileIdempotencyKey;
    use RendersMobileEnvelope;

    public function __construct(
        private readonly EvsOperationsService $evs,
        private readonly OperationalActivityLedger $ledger,
        private readonly MobilePersonaCatalog $personas,
    ) {}

    /** The SLA-ordered active turn queue + the metrics the "Bed Turns" home leads with. */
    public function queue(): JsonResponse
    {
        $active = EvsRequest::active()
            ->orderByRaw("CASE priority WHEN 'stat' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END")
            ->orderByRaw('needed_at NULLS LAST')
            ->orderByDesc('evs_request_id')
            ->get();

        $turns = $active->map(fn (EvsRequest $r) => $this->shapeTurn($r))->values();

        $completedToday = EvsRequest::query()
            ->where('is_deleted', false)
            ->where('status', 'completed')
            ->whereDate('requested_at', Carbon::today())
            ->count();

        $metrics = [
            'pending' => $active->count(),
            'overdue' => $active->filter(fn (EvsRequest $r) => $this->atRisk($r))->count(),
            'isolation' => $active->where('isolation_required', true)->count(),
            'completed_today' => $completedToday,
        ];

        return $this->envelope(
            ['metrics' => $metrics, 'turns' => $turns],
            meta: ['count' => $turns->count()],
            links: ['web' => url('/rtdc/bed-tracking')],
        );
    }

    /** Advance a turn (Claim → Start → Complete). Delegates to the service. */
    public function status(Request $request, int $id): JsonResponse
    {
        $actorRole = $this->authorizeMobilePersonaAction($request, ['evs'], 'update EVS turns');
        $allowed = array_merge(EvsOperationsService::ACTIVE_STATUSES, ['completed', 'canceled', 'failed']);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in($allowed)],
            'assigned_team' => ['sometimes', 'string', 'max:120'],
        ]);

        $req = EvsRequest::where('evs_request_id', $id)->where('is_deleted', false)->firstOrFail();
        $actorId = $request->user()?->id;
        $from = $req->status;
        $eventType = match ($validated['status']) {
            'assigned' => 'evs.claimed',
            'in_progress' => 'evs.started',
            'completed' => 'evs.completed',
            default => 'evs.progressed',
        };
        $eventAttributes = [
            'idempotency_key' => $this->mobileIdempotencyKey($request),
            'actor_user_id' => $actorId,
            'actor_role' => $actorRole,
            'domain' => 'evs',
            'scope' => [
                'patient_ref' => $req->patient_ref,
                'encounter_ref' => $req->encounter_ref,
                'bed_id' => $req->bed_id,
                'unit_id' => $req->unit_id,
                'evs_request_id' => $req->evs_request_id,
            ],
            'idempotency_payload' => [
                'status' => $validated['status'],
                'assigned_team' => $validated['assigned_team'] ?? null,
            ],
        ];

        if ($this->ledger->replay($eventType, $eventAttributes) !== null) {
            return $this->envelope($this->shapeTurn($req->fresh()), links: ['web' => url('/rtdc/bed-tracking')]);
        }

        $updated = DB::transaction(function () use ($actorId, $eventAttributes, $eventType, $from, $req, $request, $validated): EvsRequest {
            $updated = $validated['status'] === 'assigned'
                ? $this->evs->assign($req, ['assigned_team' => $validated['assigned_team'] ?? ($request->user()?->name ?? 'Mobile')], $actorId)
                : $this->evs->transition($req, $validated['status'], [], $actorId);

            $this->ledger->record($eventType, [
                ...$eventAttributes,
                'status' => [
                    'previous' => $from,
                    'current' => $updated->status,
                    'severity' => $this->tier($updated),
                ],
                'payload' => [
                    'location_label' => $updated->location_label,
                    'turn_type' => $updated->turn_type,
                    'isolation_required' => (bool) $updated->isolation_required,
                ],
                'entities' => [[
                    'entity_type' => 'evs_request',
                    'entity_ref' => (string) $updated->evs_request_id,
                    'patient_ref' => $updated->patient_ref,
                    'encounter_ref' => $updated->encounter_ref,
                ]],
            ]);

            return $updated;
        });

        return $this->envelope($this->shapeTurn($updated), links: ['web' => url('/rtdc/bed-tracking')]);
    }

    // MARK: PHI-minimized shaping (mirrors EvsOperationsService::sla/isAtRisk semantics)

    /** @return array<string, mixed> */
    private function shapeTurn(EvsRequest $r): array
    {
        $visualStatus = $this->tier($r);

        return [
            'id' => $r->evs_request_id,
            'uuid' => $r->request_uuid,
            'request_type' => $r->request_type,
            'priority' => $r->priority,
            'status' => $r->status,
            'tier' => $visualStatus,
            'visual_status' => $visualStatus,
            'location_label' => $r->location_label,
            'unit_id' => $r->unit_id,
            'turn_type' => $r->turn_type,
            'isolation_required' => (bool) $r->isolation_required,
            'needed_at' => $r->needed_at?->toIso8601String(),
            'sla' => $this->sla($r),
        ];
    }

    /** Rationed status vocabulary: stat → critical, urgent/at-risk → warning, else info. */
    private function tier(EvsRequest $r): string
    {
        if ($r->priority === 'stat') {
            return 'critical';
        }

        return ($r->priority === 'urgent' || $this->atRisk($r)) ? 'warning' : 'info';
    }

    private function atRisk(EvsRequest $r): bool
    {
        if ($r->priority === 'stat') {
            return true;
        }

        if ($r->needed_at === null) {
            return false;
        }

        return $r->needed_at->isPast() && ! in_array($r->status, ['completed', 'canceled', 'failed'], true);
    }

    /** @return array{minutes_until_due: int|null, at_risk: bool, label: string} */
    private function sla(EvsRequest $r): array
    {
        $minutesUntilDue = $r->needed_at ? (int) round(now()->diffInMinutes($r->needed_at, false)) : null;

        return [
            'minutes_until_due' => $minutesUntilDue,
            'at_risk' => $this->atRisk($r),
            'label' => $minutesUntilDue === null
                ? 'No target'
                : ($minutesUntilDue < 0 ? abs($minutesUntilDue).'m overdue' : $minutesUntilDue.'m remaining'),
        ];
    }
}
