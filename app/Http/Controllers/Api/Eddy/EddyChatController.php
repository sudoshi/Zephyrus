<?php

namespace App\Http\Controllers\Api\Eddy;

use App\Http\Concerns\ProxiesEddyChatStream;
use App\Http\Controllers\Controller;
use App\Http\Requests\Eddy\EddyChatRequest;
use App\Models\Eddy\EddyConversation;
use App\Services\Eddy\EddyChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EddyChatController extends Controller
{
    use ProxiesEddyChatStream;

    public function __construct(private readonly EddyChatService $chat) {}

    /** Send a turn; returns the assistant reply + the conversation id. */
    public function chat(EddyChatRequest $request): JsonResponse
    {
        $result = $this->chat->chat($request->user(), $request->validated());

        // 503 only on a hard failure (Eddy unreachable → message is a notice string).
        // A degraded-but-real reply (assistant message object) returns 200 so the dock
        // can render it with its fallback/degraded chip.
        $hardFailure = is_string($result['message'] ?? null);

        return response()->json(['data' => $result], $hardFailure ? 503 : 200);
    }

    /**
     * Stream a turn over SSE: persist the user message, proxy Eddy's token stream
     * to the browser, and persist the assistant turn from the terminal `complete`
     * frame. (The Reverb shared-bus variant — needed for Hummingbird — lands when
     * the Reverb server + queue worker are provisioned.)
     */
    public function stream(EddyChatRequest $request): StreamedResponse
    {
        return $this->streamEddyChat($this->chat, $request->user(), $request->validated());
    }

    /** List the authenticated user's conversations (most recent first). */
    public function conversations(Request $request): JsonResponse
    {
        $conversations = EddyConversation::forUser($request->user()->id)
            ->whereNull('archived_at')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get(['eddy_conversation_uuid', 'title', 'surface', 'created_at', 'updated_at'])
            ->map(fn (EddyConversation $c): array => [
                'id' => $c->eddy_conversation_uuid,
                'title' => $c->title,
                'surface' => $c->surface,
                'createdAt' => $c->created_at,
                'updatedAt' => $c->updated_at,
            ]);

        return response()->json(['data' => $conversations]);
    }

    /** Show one conversation with its messages (user-scoped). */
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
                'model' => $m->metadata['model'] ?? null,
                'createdAt' => $m->created_at,
            ]);

        return response()->json([
            'data' => [
                'id' => $conversation->eddy_conversation_uuid,
                'title' => $conversation->title,
                'surface' => $conversation->surface,
                'messages' => $messages,
            ],
        ]);
    }

    /** Soft-archive a conversation (user-scoped). */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $conversation = EddyConversation::forUser($request->user()->id)
            ->where('eddy_conversation_uuid', $uuid)
            ->firstOrFail();

        $conversation->update(['archived_at' => now()]);

        return response()->json(['data' => ['archived' => true]]);
    }
}
