<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\AuthorizesMobilePersonaActions;
use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Concerns\RequiresIdempotencyKey;
use App\Http\Controllers\Controller;
use App\Models\Transport\TransportRequest;
use App\Services\Mobile\MobilePersonaCatalog;
use App\Services\Mobile\OperationalActivityLedger;
use App\Services\Transport\TransportOperationsService;
use App\Support\Operations\DurationFormatter;
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
    use RendersMobileEnvelope;
    use RequiresIdempotencyKey;

    public function __construct(
        private readonly TransportOperationsService $transport,
        private readonly OperationalActivityLedger $ledger,
        private readonly MobilePersonaCatalog $personas,
    ) {}

    /** The prioritized active queue + the glanceable metrics the "My Trips" home leads with. */
    public function queue(Request $request): JsonResponse
    {
        $this->authorizeMobilePersonaAction($request, ['transport'], 'view transport jobs');
        $validated = $request->validate([
            'cursor' => ['nullable', 'string', 'max:1000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'priority' => ['nullable', Rule::in(['routine', 'urgent', 'stat'])],
            'status' => ['nullable', Rule::in(array_keys(\App\Services\Transport\TransportLifecycleService::TRANSITIONS))],
        ]);
        $actor = $request->user();
        $page = $this->transport->list(array_merge($validated, [
            'scope' => 'active',
            'mobile_actor_user_id' => $actor->id,
        ]));
        $jobs = collect($page->items())
            ->map(fn (TransportRequest $transportRequest) => $this->shapeJob($transportRequest, $actor))
            ->values();
        $visible = TransportRequest::active()
            ->where(function ($scope) use ($actor): void {
                $scope->whereDoesntHave('activeAssignment')
                    ->orWhereHas('activeAssignment.resource', fn ($resource) => $resource
                        ->where('actor_user_id', $actor->id));
            })
            ->get();

        $completedToday = \App\Models\Transport\TransportEvent::query()
            ->where('event_type', 'transport.completed')
            ->whereDate('occurred_at', Carbon::today())
            ->distinct('transport_request_id')
            ->count('transport_request_id');

        $metrics = [
            'active' => $visible->count(),
            'stat' => $visible->where('priority', 'stat')->count(),
            'at_risk' => $visible->filter(fn (TransportRequest $transportRequest) => $this->atRisk($transportRequest))->count(),
            'completed_today' => $completedToday,
        ];

        return $this->envelope(
            ['metrics' => $metrics, 'jobs' => $jobs],
            meta: [
                'count' => $jobs->count(),
                'per_page' => $page->perPage(),
                'has_more' => $page->hasMorePages(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'previous_cursor' => $page->previousCursor()?->encode(),
            ],
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
            'reason' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $req = TransportRequest::with('activeAssignment.resource', 'handoffEvidence')
            ->where('transport_request_id', $id)
            ->where('is_deleted', false)
            ->firstOrFail();
        $actor = $request->user();
        $actorId = $actor->id;
        $from = $req->status;
        $eventType = $validated['status'] === 'assigned' ? 'transport.claimed' : 'transport.progressed';
        $idempotencyKey = $this->requireIdempotencyKey($request);
        $eventAttributes = [
            'idempotency_key' => $idempotencyKey,
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
                'reason' => $validated['reason'] ?? null,
                'note' => $validated['note'] ?? null,
            ],
        ];

        $updated = DB::transaction(function () use ($actor, $eventAttributes, $eventType, $from, $idempotencyKey, $req, $validated): TransportRequest {
            $updated = $validated['status'] === 'assigned'
                ? $this->transport->assign($req, [], $actor, $idempotencyKey, 'mobile', claim: true)
                : $this->transport->transition($req, $validated['status'], [
                    'reason' => $validated['reason'] ?? null,
                    'note' => $validated['note'] ?? null,
                ], $actor, $idempotencyKey, 'mobile');

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

        return $this->envelope($this->shapeJob($updated, $actor), links: ['web' => url('/transport/dispatch')]);
    }

    /** Structured handoff at the destination → marks the job handed off. */
    public function handoff(Request $request, int $id): JsonResponse
    {
        $actorRole = $this->authorizeMobilePersonaAction($request, ['transport'], 'handoff transport jobs');

        $validated = $request->validate([
            'handoff_to' => ['required', 'string', 'max:160'],
            'receiver_role' => ['required', 'string', 'max:120'],
            'acceptance_status' => ['required', Rule::in(['accepted', 'accepted_with_risks'])],
            'accepted_at' => ['nullable', 'date', 'before_or_equal:now'],
            'handoff_summary' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'documents' => ['sometimes', 'array', 'max:20'],
            'documents.*.type' => ['required_with:documents', 'string', 'max:80'],
            'documents.*.reference' => ['required_with:documents', 'string', 'max:200'],
            'outstanding_risks' => ['required_if:acceptance_status,accepted_with_risks', 'array', 'min:1', 'max:20'],
            'outstanding_risks.*' => ['string', 'max:300'],
        ]);

        $req = TransportRequest::with('activeAssignment.resource', 'handoffEvidence')
            ->where('transport_request_id', $id)
            ->where('is_deleted', false)
            ->firstOrFail();
        $actor = $request->user();
        $from = $req->status;
        $idempotencyKey = $this->requireIdempotencyKey($request);
        $eventAttributes = [
            'idempotency_key' => $idempotencyKey,
            'actor_user_id' => $actor->id,
            'actor_role' => $actorRole,
            'domain' => 'transport',
            'scope' => [
                'patient_ref' => $req->patient_ref,
                'encounter_ref' => $req->encounter_ref,
                'transport_request_id' => $req->transport_request_id,
            ],
            'idempotency_payload' => [
                'handoff_to' => $validated['handoff_to'],
                'receiver_role' => $validated['receiver_role'],
                'acceptance_status' => $validated['acceptance_status'],
                'handoff_summary' => $validated['handoff_summary'] ?? null,
            ],
        ];

        $updated = DB::transaction(function () use ($actor, $eventAttributes, $from, $idempotencyKey, $req, $validated): TransportRequest {
            $updated = $this->transport->completeHandoff($req, $validated, $actor, $idempotencyKey, 'mobile');

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

        return $this->envelope($this->shapeJob($updated, $actor), links: ['web' => url('/transport/dispatch')]);
    }

    // MARK: PHI-minimized shaping (mirrors TransportOperationsService::sla/isAtRisk semantics)

    /** @return array<string, mixed> */
    private function shapeJob(TransportRequest $r, \App\Models\User $actor): array
    {
        $visualStatus = $this->tier($r);
        $serialized = $this->transport->serializeRequest($r, $actor);
        $activeAssignment = $r->activeAssignment;

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
            'claimed_by_me' => (int) data_get($activeAssignment, 'resource.actor_user_id') === (int) $actor->id,
            'available_to_claim' => $activeAssignment === null && in_array('assigned', $serialized['allowed_transitions'], true),
            'resource_name' => data_get($activeAssignment, 'resource.display_name'),
            'handoff_required' => (bool) $r->handoff_required,
            'allowed_transitions' => $serialized['allowed_transitions'],
            'can_handoff' => (bool) $serialized['permissions']['can_handoff'],
            'lifecycle_version' => (int) $r->lifecycle_version,
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
        $secondsUntilDue = $r->needed_at ? (int) round(now()->diffInSeconds($r->needed_at, false)) : null;
        $minutesUntilDue = $secondsUntilDue !== null ? (int) round($secondsUntilDue / 60) : null;

        return [
            'minutes_until_due' => $minutesUntilDue,
            'at_risk' => $this->atRisk($r),
            'label' => DurationFormatter::relativeSeconds($secondsUntilDue),
        ];
    }
}
