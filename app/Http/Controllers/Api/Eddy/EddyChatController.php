<?php

namespace App\Http\Controllers\Api\Eddy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Eddy\EddyChatRequest;
use App\Models\Eddy\EddyConversation;
use App\Services\Eddy\EddyChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EddyChatController extends Controller
{
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
        $user = $request->user();
        $prep = $this->chat->prepareStream($user, $request->validated());
        $eddyUrl = rtrim((string) config('services.eddy.url'), '/').'/eddy/chat/stream';

        return response()->stream(function () use ($prep, $eddyUrl, $user) {
            $this->sse(['conversation_id' => $prep['conversation']->eddy_conversation_uuid]);

            $buffer = '';
            $complete = null;
            try {
                $response = Http::withOptions(['stream' => true])->timeout(180)->acceptJson()->post($eddyUrl, $prep['envelope']);
                $body = $response->toPsrResponse()->getBody();
                while (! $body->eof()) {
                    $chunk = $body->read(2048);
                    if ($chunk === '') {
                        continue;
                    }
                    echo $chunk;            // passthrough Eddy's SSE frames to the browser
                    $this->flush();
                    $buffer .= $chunk;
                    $complete = $this->extractComplete($buffer) ?? $complete;
                }
            } catch (\Throwable $e) {
                $this->sse(['error' => 'Eddy stream is unavailable.']);
            }

            if ($complete !== null) {
                $assistant = $this->chat->persistStreamResult($user, $prep, $complete);
                // The sanitized (tier/risk/label-enriched) proposal — the dock renders
                // the approval card from THIS, not the raw model block in `complete`.
                $this->sse([
                    'persisted' => true,
                    'message_id' => $assistant->eddy_message_id,
                    'proposed_action' => $assistant->metadata['proposed_action'] ?? null,
                ]);
            }
            $this->sse('[DONE]');
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function sse(array|string $payload): void
    {
        echo 'data: '.(is_string($payload) ? $payload : json_encode($payload))."\n\n";
        $this->flush();
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }

    /**
     * Find the terminal `complete` frame in the accumulated SSE buffer.
     *
     * @return array<string, mixed>|null
     */
    private function extractComplete(string $buffer): ?array
    {
        foreach (explode("\n\n", $buffer) as $frame) {
            $line = trim($frame);
            if (! str_starts_with($line, 'data: ')) {
                continue;
            }
            $decoded = json_decode(substr($line, 6), true);
            if (is_array($decoded) && ($decoded['complete'] ?? false) === true) {
                return $decoded;
            }
        }

        return null;
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
