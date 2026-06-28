<?php

namespace App\Http\Concerns;

use App\Models\User;
use App\Services\Eddy\EddyChatService;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proxies Eddy's SSE token stream to the caller and persists the assistant turn
 * from the terminal `complete` frame. Shared by the web dock controller and the
 * Hummingbird mobile BFF so both stream the byte-identical contract: an opening
 * `conversation_id` frame, Eddy's passthrough token frames, a terminal `persisted`
 * frame carrying the sanitized proposed action, then `[DONE]`.
 *
 * Eddy is stateless — {@see EddyChatService} owns conversation/message persistence.
 */
trait ProxiesEddyChatStream
{
    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, string>  $extraHeaders
     */
    protected function streamEddyChat(EddyChatService $chat, User $user, array $validated, array $extraHeaders = []): StreamedResponse
    {
        $prep = $chat->prepareStream($user, $validated);
        $eddyUrl = rtrim((string) config('services.eddy.url'), '/').'/eddy/chat/stream';

        return response()->stream(function () use ($chat, $prep, $eddyUrl, $user) {
            $this->sseFrame(['conversation_id' => $prep['conversation']->eddy_conversation_uuid]);

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
                    echo $chunk;            // passthrough Eddy's SSE frames to the caller
                    $this->sseFlush();
                    $buffer .= $chunk;
                    $complete = $this->extractCompleteFrame($buffer) ?? $complete;
                }
            } catch (\Throwable $e) {
                $this->sseFrame(['error' => 'Eddy stream is unavailable.']);
            }

            if ($complete !== null) {
                $assistant = $chat->persistStreamResult($user, $prep, $complete);
                // The sanitized (tier/risk/label-enriched) proposal — clients render
                // the approval card from THIS, not the raw model block in `complete`.
                $this->sseFrame([
                    'persisted' => true,
                    'message_id' => $assistant->eddy_message_id,
                    'proposed_action' => $assistant->metadata['proposed_action'] ?? null,
                ]);
            }
            $this->sseFrame('[DONE]');
        }, 200, array_merge([
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ], $extraHeaders));
    }

    private function sseFrame(array|string $payload): void
    {
        echo 'data: '.(is_string($payload) ? $payload : json_encode($payload))."\n\n";
        $this->sseFlush();
    }

    private function sseFlush(): void
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
    private function extractCompleteFrame(string $buffer): ?array
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
}
