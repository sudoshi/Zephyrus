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
 * `conversation_id` frame, safe token frames, a server-persisted terminal
 * `complete` frame, a terminal `persisted` frame carrying the sanitized proposed
 * action, then `[DONE]`. The upstream terminal frame is deliberately never
 * forwarded verbatim because it can contain raw proposal fields.
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
                    $buffer .= $chunk;
                    $complete = $this->relaySafeEddyFrames($buffer) ?? $complete;
                }

                // A compliant SSE producer terminates each frame, but process a
                // final unterminated frame defensively instead of discarding a
                // completed assistant turn on connection close.
                $complete = $this->relaySafeEddyFrames($buffer, true) ?? $complete;
            } catch (\Throwable $e) {
                $this->sseFrame(['error' => 'Eddy stream is unavailable.']);
            }

            if ($complete !== null) {
                $assistant = $chat->persistStreamResult($user, $prep, $complete);
                // Native clients render this persisted clean reply, not the raw
                // upstream complete frame that may have carried proposal markup.
                $this->sseFrame([
                    'complete' => true,
                    'clean_reply' => $assistant->content,
                    'provider' => $assistant->metadata['provider'] ?? null,
                ]);
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
            // The stream can carry a governed AI response and therefore must not
            // be retained by browsers, proxies, or native URL caches.
            'Cache-Control' => 'no-store, no-cache, max-age=0',
            'Pragma' => 'no-cache',
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
     * Consume complete upstream frames from an incremental SSE buffer and emit
     * only safe client frames. The upstream terminal `complete` frame is captured
     * for persistence but never reaches the browser/native client verbatim.
     *
     * @param  string  $buffer  Mutated to retain an incomplete trailing frame.
     * @return array<string, mixed>|null
     */
    private function relaySafeEddyFrames(string &$buffer, bool $final = false): ?array
    {
        $complete = null;

        while (preg_match('/\r?\n\r?\n/', $buffer, $delimiter, PREG_OFFSET_CAPTURE) === 1) {
            $frame = substr($buffer, 0, $delimiter[0][1]);
            $buffer = substr($buffer, $delimiter[0][1] + strlen($delimiter[0][0]));
            $complete = $this->relaySafeEddyFrame($frame) ?? $complete;
        }

        if ($final && trim($buffer) !== '') {
            $complete = $this->relaySafeEddyFrame($buffer) ?? $complete;
            $buffer = '';
        }

        if (strlen($buffer) > 131072) {
            throw new \RuntimeException('Eddy stream frame exceeded the safe buffer limit.');
        }

        return $complete;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function relaySafeEddyFrame(string $frame): ?array
    {
        foreach (preg_split('/\r?\n/', trim($frame)) ?: [] as $line) {
            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $decoded = json_decode(ltrim(substr($line, 5)), true);
            if (! is_array($decoded)) {
                continue;
            }
            if (($decoded['complete'] ?? false) === true) {
                return $decoded;
            }
            if (is_string($decoded['token'] ?? null)) {
                $this->sseFrame(['token' => $decoded['token']]);

                return null;
            }
            if (is_string($decoded['error'] ?? null)) {
                $this->sseFrame(['error' => 'Eddy stream is unavailable.']);

                return null;
            }
        }

        return null;
    }
}
