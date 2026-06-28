<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\ProxiesEddyChatStream;
use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Eddy\EddyChatRequest;
use App\Models\Eddy\EddyConversation;
use App\Models\Ops\Approval;
use App\Services\Eddy\EddyActionService;
use App\Services\Eddy\EddyChatService;
use App\Services\Ops\OperationalActionLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hummingbird mobile BFF for Eddy — the same stateless chat + governance surface
 * the web dock uses, reshaped into the mobile envelope and scoped to the native
 * apps' Sanctum tokens. Reads need `mobile:read`; the approval decision needs
 * `mobile:act` (route middleware). Conversations are tagged origin=hummingbird.
 *
 * The native Compose/SwiftUI Eddy screens (separate hummingbird/ repo) consume
 * these endpoints via the shared Ktor BFF client — see
 * docs/hummingbird/api-contract/hummingbird-bff.v1.yaml and
 * docs/hummingbird/reference/08-eddy-mobile.md.
 */
class EddyController extends Controller
{
    use ProxiesEddyChatStream;
    use RendersMobileEnvelope;

    public function __construct(
        private readonly EddyChatService $chat,
        private readonly EddyActionService $actions,
        private readonly OperationalActionLifecycleService $lifecycle,
    ) {}

    /** Send a turn; mobile envelope wrapping the assistant reply + conversation id. */
    public function chat(EddyChatRequest $request): JsonResponse
    {
        $result = $this->chat->chat($request->user(), $this->mobileInput($request));

        $hardFailure = is_string($result['message'] ?? null);

        return $this->envelope(
            $result,
            meta: ['stale' => $hardFailure],
            links: ['web' => url('/dashboard')],
            status: $hardFailure ? 503 : 200,
        );
    }

    /** SSE token stream (Ktor consumes it natively). Origin tagged hummingbird. */
    public function stream(EddyChatRequest $request): StreamedResponse
    {
        return $this->streamEddyChat($this->chat, $request->user(), $this->mobileInput($request));
    }

    /** The user's conversations, most recent first. */
    public function conversations(Request $request): JsonResponse
    {
        $conversations = EddyConversation::forUser($request->user()->id)
            ->whereNull('archived_at')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get(['eddy_conversation_uuid', 'title', 'surface', 'origin', 'created_at', 'updated_at'])
            ->map(fn (EddyConversation $c): array => [
                'id' => $c->eddy_conversation_uuid,
                'title' => $c->title,
                'surface' => $c->surface,
                'origin' => $c->origin,
                'updated_at' => optional($c->updated_at)->toISOString(),
            ]);

        return $this->envelope($conversations, links: ['web' => url('/dashboard')]);
    }

    /** One conversation with its messages (user-scoped). */
    public function conversation(Request $request, string $uuid): JsonResponse
    {
        $conversation = EddyConversation::forUser($request->user()->id)
            ->where('eddy_conversation_uuid', $uuid)
            ->firstOrFail();

        $messages = $conversation->messages()
            ->orderBy('eddy_message_id')
            ->get(['role', 'content', 'metadata', 'created_at'])
            ->map(fn ($m): array => [
                'role' => $m->role,
                'content' => $m->content,
                'provider' => $m->metadata['provider'] ?? null,
                'proposed_action' => $m->metadata['proposed_action'] ?? null,
                'created_at' => optional($m->created_at)->toISOString(),
            ]);

        return $this->envelope([
            'id' => $conversation->eddy_conversation_uuid,
            'title' => $conversation->title,
            'surface' => $conversation->surface,
            'messages' => $messages,
        ]);
    }

    /**
     * The pending Eddy-proposed approvals this user may act on (the inbox the
     * PHI-free doorbell deep-links into). Admins see every pending Eddy approval;
     * everyone else sees the ones they requested. PHI-minimized: ids + labels only.
     */
    public function approvals(Request $request): JsonResponse
    {
        $user = $request->user();

        $pending = Approval::query()
            ->where('status', 'pending')
            ->whereHas('action.recommendation', fn ($q) => $q->where('created_by_source', 'eddy'))
            ->when(
                ! $user->hasRole(['super-admin', 'admin']),
                fn ($q) => $q->where('requested_by_user_id', $user->id),
            )
            ->with('action.recommendation')
            ->orderByDesc('approval_id')
            ->limit(50)
            ->get()
            ->map(fn (Approval $a): array => $this->approvalSummary($a))
            ->values();

        return $this->envelope($pending, links: ['web' => url('/dashboard')]);
    }

    /** Fetch-on-open: the dry-run preview for one pending approval (PHI-minimized). */
    public function approval(Request $request, string $uuid): JsonResponse
    {
        $approval = $this->findActionableApproval($request, $uuid);

        $action = $approval->action;
        $recommendation = $action?->recommendation;
        $params = is_array($action->payload ?? null) ? $action->payload : [];

        return $this->envelope([
            ...$this->approvalSummary($approval),
            'rationale' => $recommendation->rationale ?? null,
            'runner_up' => $recommendation->evidence['runner_up'] ?? null,
            'params' => $params,                                  // operational (unit codes/counts), not patient PHI
            'preview' => $this->previewDescriptor((string) $action->action_type, $params),
        ]);
    }

    /**
     * Approve or reject a pending Eddy approval from mobile. Requires mobile:act
     * (route middleware) — a real human decision, never the agent. Connectivity is
     * required for safety-critical writes, so the native app blocks this offline.
     */
    public function decide(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:approved,rejected'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $approval = $this->findActionableApproval($request, $uuid);

        try {
            $action = $this->lifecycle->decideApproval(
                $approval,
                $validated['decision'],
                $validated['reason'] ?? null,
                $request->user()->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['error' => ['code' => 'invalid_decision', 'message' => $e->getMessage()]], 422);
        }

        return $this->envelope([
            'approval_uuid' => $approval->approval_uuid,
            'action_uuid' => $action->action_uuid,
            'decision' => $validated['decision'],
            'action_status' => $action->status,
        ]);
    }

    /**
     * Merge the validated chat body with the mobile origin tag.
     *
     * @return array<string, mixed>
     */
    private function mobileInput(EddyChatRequest $request): array
    {
        return [...$request->validated(), 'origin' => 'hummingbird'];
    }

    /**
     * Resolve a pending Eddy approval this user may act on, or 404. Admins may act
     * on any pending Eddy approval; others only on ones they requested.
     */
    private function findActionableApproval(Request $request, string $uuid): Approval
    {
        $user = $request->user();

        return Approval::query()
            ->where('approval_uuid', $uuid)
            ->where('status', 'pending')
            ->whereHas('action.recommendation', fn ($q) => $q->where('created_by_source', 'eddy'))
            ->when(
                ! $user->hasRole(['super-admin', 'admin']),
                fn ($q) => $q->where('requested_by_user_id', $user->id),
            )
            ->with('action.recommendation')
            ->firstOrFail();
    }

    /**
     * PHI-free summary of an approval: ids + catalog labels + server-derived tier.
     *
     * @return array<string, mixed>
     */
    private function approvalSummary(Approval $approval): array
    {
        $action = $approval->action;
        $recommendation = $action?->recommendation;
        $actionType = (string) ($action->action_type ?? '');
        $spec = EddyActionService::CATALOG[$actionType] ?? null;

        return [
            'approval_uuid' => $approval->approval_uuid,
            'action_uuid' => $action->action_uuid ?? null,
            'action_type' => $actionType,
            'title' => $recommendation->title ?? ($spec['label'] ?? $actionType),
            'surface' => $recommendation->scope_type ?? 'house',
            'tier' => $spec['tier'] ?? ($recommendation->evidence['tier'] ?? 'T1'),
            'risk' => $spec['risk'] ?? ($recommendation->risk_level ?? 'low'),
            'requested_at' => optional($approval->requested_at ?? $approval->created_at)->toISOString(),
        ];
    }

    /**
     * A human-readable "would do X" descriptor for the dry-run preview, synthesized
     * from the action type + operational params (no patient detail).
     *
     * @param  array<string, mixed>  $params
     */
    private function previewDescriptor(string $actionType, array $params): string
    {
        $unit = isset($params['unit']) ? " on {$params['unit']}" : '';

        return match ($actionType) {
            'flag_barrier' => "Would flag a throughput/discharge barrier{$unit} for the next huddle.",
            'propose_huddle_action' => 'Would add a huddle action item for review.',
            'propose_transport_dispatch' => 'Would draft a transport dispatch for dispatcher confirmation.',
            'propose_bed_placement' => "Would draft a bed placement{$unit} for charge-nurse confirmation.",
            'propose_surge_plan' => 'Would draft a surge / red-stretch plan for command-center review.',
            default => 'Would create a draft action for human review.',
        };
    }
}
