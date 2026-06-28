<?php

namespace App\Services\Eddy;

use App\Models\Eddy\EddyCloudUsage;
use App\Models\Eddy\EddyConversation;
use App\Models\Eddy\EddyMessage;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Subsystem A proxy — builds the chat envelope, resolves the surface's provider
 * policy, calls the stateless Eddy service, and OWNS all persistence:
 * conversation + messages + the cloud-usage ledger. Eddy holds no DB creds.
 */
class EddyChatService
{
    public function __construct(
        private readonly EddyProviderPolicyService $policy,
        private readonly EddyContextService $context,
        private readonly EddyKnowledgeService $knowledge,
    ) {}

    /**
     * @param  array{message:string, surface?:?string, page_context?:?string, page_component?:?string, page_data?:?array, conversation_id?:?string}  $input
     * @return array<string, mixed>
     */
    public function chat(User $user, array $input): array
    {
        $surface = $this->normalizeSurface($input['surface'] ?? 'chat');
        $message = (string) $input['message'];

        $conversation = $this->resolveConversation($user, $input['conversation_id'] ?? null, $surface, $message);
        $providerPolicy = $this->policy->payloadForSurface($surface) ?? $this->localFallbackPolicy();

        // History = prior turns only (the user's new message is sent separately).
        $history = $this->history($conversation);

        EddyMessage::create([
            'eddy_conversation_id' => $conversation->eddy_conversation_id,
            'role' => 'user',
            'content' => $message,
            'metadata' => ['surface' => $surface],
        ]);

        $result = $this->callEddy($user, $message, $surface, $input, $history, $conversation, $providerPolicy);

        if ($result === null) {
            return [
                'conversation_id' => $conversation->eddy_conversation_uuid,
                'status' => 'error',
                'message' => 'Eddy is unavailable right now. Please try again shortly.',
            ];
        }

        $assistant = EddyMessage::create([
            'eddy_conversation_id' => $conversation->eddy_conversation_id,
            'role' => 'assistant',
            'content' => (string) ($result['reply'] ?? ''),
            'metadata' => [
                'provider' => $result['provider'] ?? null,
                'model' => $result['model'] ?? null,
                'route_reason' => $result['route_reason'] ?? null,
                'fallback_reason' => $result['fallback_reason'] ?? null,
                'tokens_in' => $result['tokens_in'] ?? 0,
                'tokens_out' => $result['tokens_out'] ?? 0,
                'cost_usd' => $result['cost_usd'] ?? 0,
                'redaction_count' => $result['sanitizer_redaction_count'] ?? 0,
                'status' => $result['status'] ?? 'success',
                'proposed_action' => $result['proposed_action'] ?? null,
            ],
        ]);

        $this->recordCloudUsageIfApplicable($user, $surface, $providerPolicy, $result);

        // Surface a proposed action (a DRAFT for human approval — Eddy never executes).
        // Only pass through allowlisted action types; the propose endpoint re-validates.
        $proposedAction = $this->sanitizeProposedAction($result['proposed_action'] ?? null);

        return [
            'conversation_id' => $conversation->eddy_conversation_uuid,
            'status' => $result['status'] ?? 'success',
            'message' => [
                'id' => $assistant->eddy_message_id,
                'role' => 'assistant',
                'content' => $assistant->content,
                'provider' => $result['provider'] ?? null,
                'model' => $result['model'] ?? null,
                'route_reason' => $result['route_reason'] ?? null,
                'fallback_reason' => $result['fallback_reason'] ?? null,
                'proposed_action' => $proposedAction,
            ],
        ];
    }

    private function resolveConversation(User $user, ?string $conversationId, string $surface, string $message): EddyConversation
    {
        if ($conversationId) {
            $existing = EddyConversation::forUser($user->id)
                ->where('eddy_conversation_uuid', $conversationId)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        return EddyConversation::create([
            'eddy_conversation_uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'surface' => $surface,
            'title' => Str::limit($message, 60),
            'origin' => 'web',
        ]);
    }

    /**
     * @return array<int, array{role:string, content:string}>
     */
    private function history(EddyConversation $conversation): array
    {
        return $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderByDesc('eddy_message_id')
            ->limit(10)
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn (EddyMessage $m): array => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, array{role:string, content:string}>  $history
     * @param  array<string, mixed>  $providerPolicy
     * @return array<string, mixed>|null
     */
    private function callEddy(User $user, string $message, string $surface, array $input, array $history, EddyConversation $conversation, array $providerPolicy): ?array
    {
        try {
            $response = Http::timeout((int) config('services.eddy.timeout', 30))
                ->acceptJson()
                ->post(rtrim((string) config('services.eddy.url'), '/').'/eddy/chat', [
                    'message' => $message,
                    'surface' => $surface,
                    'page_context' => $input['page_context'] ?? null,
                    'page_component' => $input['page_component'] ?? null,
                    'page_data' => (object) ($input['page_data'] ?? []),
                    'history' => $history,
                    'user_profile' => [
                        'name' => $user->name,
                        'roles' => $user->getRoleNames()->all(),
                    ],
                    'user_id' => $user->id,
                    'conversation_id' => $conversation->eddy_conversation_uuid,
                    'provider_policy' => $providerPolicy,
                    // Process-awareness: the PHI-free live-ops snapshot + surface doctrine.
                    'live_context' => $this->context->forSurface($user, $surface) ?: (object) [],
                    'knowledge' => $this->knowledge->forSurface($surface, $message),
                    // The actions Eddy may PROPOSE (drafts for human approval); never executes.
                    'allowed_actions' => array_keys(EddyActionService::CATALOG),
                ]);
        } catch (\Throwable $e) {
            Log::warning('eddy.chat.transport_failed', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('eddy.chat.http_error', ['status' => $response->status()]);

            return null;
        }

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $providerPolicy
     * @param  array<string, mixed>  $result
     */
    private function recordCloudUsageIfApplicable(User $user, string $surface, array $providerPolicy, array $result): void
    {
        $provider = (string) ($result['provider'] ?? 'ollama');
        // A cloud call actually happened only when a non-local provider answered.
        if ($provider === 'ollama' || $provider === '') {
            return;
        }

        EddyCloudUsage::create([
            'user_id' => $user->id,
            'tokens_in' => (int) ($result['tokens_in'] ?? 0),
            'tokens_out' => (int) ($result['tokens_out'] ?? 0),
            'cost_usd' => (float) ($result['cost_usd'] ?? 0),
            'model' => (string) ($result['model'] ?? ''),
            'request_hash' => $result['request_hash'] ?? null,
            'sanitizer_redaction_count' => (int) ($result['sanitizer_redaction_count'] ?? 0),
            'route_reason' => $result['route_reason'] ?? null,
            'provider' => $provider,
            'transport' => $result['transport'] ?? null,
            'provider_profile_id' => $providerPolicy['profile_id'] ?? null,
            'entitlement_type' => $providerPolicy['entitlement'] ?? 'org_api_key',
            'request_surface' => $surface,
            'status' => (string) ($result['status'] ?? 'success'),
            'fallback_reason' => $result['fallback_reason'] ?? null,
            'response_latency_ms' => $result['latency_ms'] ?? null,
        ]);
    }

    private function normalizeSurface(?string $surface): string
    {
        $surface = $surface ?: 'chat';

        return in_array($surface, EddyProviderPolicyService::SURFACES, true) ? $surface : 'chat';
    }

    /**
     * @return array<string, mixed>
     */
    private function localFallbackPolicy(): array
    {
        return [
            'provider_type' => 'ollama',
            'profile_id' => 'local-medgemma',
            'mode' => 'local_only',
            'model' => (string) config('eddy.models.chat_local'),
            'entitlement' => 'local',
            'settings' => [],
        ];
    }

    /**
     * Validate a model-proposed action against the catalog and enrich it with
     * tier/risk for the dock. Unknown types are dropped (the propose endpoint
     * re-validates anyway). The model NEVER executes — this is a draft for a human.
     *
     * @param  array<string, mixed>|null  $proposed
     * @return array<string, mixed>|null
     */
    private function sanitizeProposedAction(?array $proposed): ?array
    {
        $type = is_array($proposed) ? (string) ($proposed['action_type'] ?? '') : '';
        $spec = EddyActionService::CATALOG[$type] ?? null;
        if ($spec === null) {
            return null;
        }

        return [
            'action_type' => $type,
            'title' => (string) ($proposed['title'] ?? $spec['label']),
            'params' => is_array($proposed['params'] ?? null) ? $proposed['params'] : [],
            'rationale' => isset($proposed['rationale']) ? (string) $proposed['rationale'] : null,
            'runner_up' => isset($proposed['runner_up']) ? (string) $proposed['runner_up'] : null,
            'tier' => $spec['tier'],
            'risk' => $spec['risk'],
            'label' => $spec['label'],
        ];
    }
}
