<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\AuthorizesMobilePersonaActions;
use App\Http\Concerns\ReadsMobileIdempotencyKey;
use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Models\Transport\TransportRequest;
use App\Services\Mobile\MobilePersonaCatalog;
use App\Services\Mobile\OperationalActivityLedger;
use App\Services\Transport\TransportOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Transport — the frontline "claim and run a trip" surface (P1, Wave 1).
 *
 *   GET  /api/mobile/v1/transport/queue                      (mobile:read)
 *   POST /api/mobile/v1/transport/requests/{id}/status       (mobile:act)
 *   POST /api/mobile/v1/transport/requests/{id}/handoff      (mobile:act)
 *
 * Reads are PHI-minimized: no patient_ref, no encounter_ref, no free-text notes — only the
 * operational shape a transporter needs (origin → destination, mode, priority, lifecycle
 * status, SLA). Writes delegate to {@see TransportOperationsService} verbatim (status
 * transitions + structured handoff), so the BFF reshapes but never re-implements the lifecycle.
 */
class TransportController extends Controller
{
    use AuthorizesMobilePersonaActions;
    use ReadsMobileIdempotencyKey;
    use RendersMobileEnvelope;

    public function __construct(
        private readonly TransportOperationsService $transport,
        private readonly OperationalActivityLedger $ledger,
        private readonly MobilePersonaCatalog $personas,
    ) {}

    /** The prioritized active queue + the glanceable metrics the "My Trips" home leads with. */
    public function queue(): JsonResponse
    {
        $active = TransportRequest::active()
            ->orderByRaw("CASE priority WHEN 'stat' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END")
            ->orderByRaw('needed_at NULLS LAST')
            ->orderByDesc('transport_request_id')
            ->get();

        $jobs = $active->map(fn (TransportRequest $r) => $this->shapeJob($r))->values();

        $completedToday = TransportRequest::query()
            ->where('is_deleted', false)
            ->where('status', 'completed')
            ->whereDate('requested_at', Carbon::today())
            ->count();

        $metrics = [
            'active' => $active->count(),
            'stat' => $active->where('priority', 'stat')->count(),
            'at_risk' => $active->filter(fn (TransportRequest $r) => $this->atRisk($r))->count(),
            'completed_today' => $completedToday,
        ];

        return $this->envelope(
            ['metrics' => $metrics, 'jobs' => $jobs],
            meta: ['count' => $jobs->count()],
            links: ['web' => url('/transport/dispatch')],
        );
    }

    /** Advance a job along its lifecycle (Claim → … → Completed). Delegates to the service. */
    public function status(Request $request, int $id): JsonResponse
    {
        $actorRole = $this->authorizeMobilePersonaAction($request, ['transport'], 'update transport jobs');
        $allowed = array_merge(TransportOperationsService::ACTIVE_STATUSES, ['completed', 'canceled', 'failed']);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in($allowed)],
            'assigned_team' => ['sometimes', 'string', 'max:120'],
        ]);

        $req = TransportRequest::where('transport_request_id', $id)->where('is_deleted', false)->firstOrFail();
        $actorId = $request->user()?->id;
        $from = $req->status;
        $eventType = $validated['status'] === 'assigned' ? 'transport.claimed' : 'transport.progressed';
        $eventAttributes = [
            'idempotency_key' => $this->mobileIdempotencyKey($request),
            'actor_user_id' => $actorId,
            'actor_role' => $actorRole,
            'domain' => 'transport',
            'scope' => [
                'patient_ref' => $req->patient_ref,
                'encounter_ref' => $req->encounter_ref,
                'transport_request_id' => $req->transport_request_id,
            ],
            'idempotency_payload' => [
                'status' => $validated['status'],
                'assigned_team' => $validated['assigned_team'] ?? null,
            ],
        ];

        if ($this->ledger->replay($eventType, $eventAttributes) !== null) {
            return $this->envelope($this->shapeJob($req->fresh()), links: ['web' => url('/transport/dispatch')]);
        }

        $updated = DB::transaction(function () use ($actorId, $eventAttributes, $eventType, $from, $req, $request, $validated): TransportRequest {
            $updated = $validated['status'] === 'assigned'
                ? $this->transport->assign($req, ['assigned_team' => $validated['assigned_team'] ?? ($request->user()?->name ?? 'Mobile')], $actorId)
                : $this->transport->transition($req, $validated['status'], [], $actorId);

            $this->ledger->record($eventType, [
                ...$eventAttributes,
                'status' => [
                    'previous' => $from,
                    'current' => $updated->status,
                    'severity' => $this->tier($updated),
                ],
                'payload' => [
                    'origin' => $updated->origin,
                    'destination' => $updated->destination,
                    'mode' => $updated->transport_mode,
                ],
                'entities' => [[
                    'entity_type' => 'transport_request',
                    'entity_ref' => (string) $updated->transport_request_id,
                    'patient_ref' => $updated->patient_ref,
                    'encounter_ref' => $updated->encounter_ref,
                ]],
            ]);

            return $updated;
        });

        return $this->envelope($this->shapeJob($updated), links: ['web' => url('/transport/dispatch')]);
    }

    /** Structured handoff at the destination → marks the job handed off. */
    public function handoff(Request $request, int $id): JsonResponse
    {
        $actorRole = $this->authorizeMobilePersonaAction($request, ['transport'], 'handoff transport jobs');

        $validated = $request->validate([
            'handoff_to' => ['required', 'string', 'max:160'],
            'handoff_summary' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'documents' => ['sometimes', 'array'],
            'outstanding_risks' => ['sometimes', 'array'],
        ]);

        $req = TransportRequest::where('transport_request_id', $id)->where('is_deleted', false)->firstOrFail();
        $from = $req->status;
        $eventAttributes = [
            'idempotency_key' => $this->mobileIdempotencyKey($request),
            'actor_user_id' => $request->user()?->id,
            'actor_role' => $actorRole,
            'domain' => 'transport',
            'scope' => [
                'patient_ref' => $req->patient_ref,
                'encounter_ref' => $req->encounter_ref,
                'transport_request_id' => $req->transport_request_id,
            ],
            'idempotency_payload' => [
                'handoff_to' => $validated['handoff_to'],
                'handoff_summary' => $validated['handoff_summary'] ?? null,
            ],
        ];

        if ($this->ledger->replay('transport.handoff_completed', $eventAttributes) !== null) {
            return $this->envelope($this->shapeJob($req->fresh()), links: ['web' => url('/transport/dispatch')]);
        }

        $updated = DB::transaction(function () use ($eventAttributes, $from, $req, $request, $validated): TransportRequest {
            $updated = $this->transport->completeHandoff($req, $validated, $request->user()?->id);

            $this->ledger->record('transport.handoff_completed', [
                ...$eventAttributes,
                'status' => [
                    'previous' => $from,
                    'current' => $updated->status,
                    'severity' => $this->tier($updated),
                ],
                'payload' => [
                    'handoff_to' => $validated['handoff_to'],
                ],
                'entities' => [[
                    'entity_type' => 'transport_request',
                    'entity_ref' => (string) $updated->transport_request_id,
                    'patient_ref' => $updated->patient_ref,
                    'encounter_ref' => $updated->encounter_ref,
                ]],
            ]);

            return $updated;
        });

        return $this->envelope($this->shapeJob($updated), links: ['web' => url('/transport/dispatch')]);
    }

    // MARK: PHI-minimized shaping (mirrors TransportOperationsService::sla/isAtRisk semantics)

    /** @return array<string, mixed> */
    private function shapeJob(TransportRequest $r): array
    {
        $visualStatus = $this->tier($r);

        return [
            'id' => $r->transport_request_id,
            'uuid' => $r->request_uuid,
            'type' => $r->request_type,
            'priority' => $r->priority,
            'status' => $r->status,
            'tier' => $visualStatus,
            'visual_status' => $visualStatus,
            'origin' => $r->origin,
            'destination' => $r->destination,
            'mode' => $r->transport_mode,
            'needed_at' => $r->needed_at?->toIso8601String(),
            'sla' => $this->sla($r),
        ];
    }

    /** Rationed status vocabulary: stat → critical, urgent/at-risk → warning, else info. */
    private function tier(TransportRequest $r): string
    {
        if ($r->priority === 'stat') {
            return 'critical';
        }

        return ($r->priority === 'urgent' || $this->atRisk($r)) ? 'warning' : 'info';
    }

    private function atRisk(TransportRequest $r): bool
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
    private function sla(TransportRequest $r): array
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
