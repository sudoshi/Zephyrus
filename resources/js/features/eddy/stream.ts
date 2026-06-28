import type { SendChatInput } from './api';
import { eddyProposedActionSchema, type EddyProposedAction } from './schemas';

export interface EddyStreamHandlers {
  onConversationId?: (id: string) => void;
  onToken?: (token: string) => void;
  onComplete?: (data: { cleanReply: string; proposedAction: EddyProposedAction | null; provider?: string | null }) => void;
  onError?: (message: string) => void;
}

function xsrfHeader(): Record<string, string> {
  const match = typeof document !== 'undefined' ? document.cookie.match(/XSRF-TOKEN=([^;]+)/) : null;
  return match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {};
}

/**
 * Consume the Laravel SSE proxy of Eddy's token stream. Reads to stream-close
 * (never tripping on an intermediate `[DONE]`), then finalizes once: tokens arrive
 * live; `complete` carries the clean reply; the `persisted` frame carries the
 * SANITIZED proposed action (tier/risk/label) the dock renders the card from.
 */
export async function streamEddyChat(input: SendChatInput, handlers: EddyStreamHandlers, signal?: AbortSignal): Promise<void> {
  let response: Response;
  try {
    response = await fetch('/api/eddy/chat/stream', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream', ...xsrfHeader() },
      credentials: 'same-origin',
      body: JSON.stringify(input),
      signal,
    });
  } catch {
    handlers.onError?.('Eddy is unavailable right now.');
    return;
  }

  if (!response.ok || !response.body) {
    handlers.onError?.('Eddy stream failed to start.');
    return;
  }

  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';

  let cleanReply = '';
  let proposedAction: EddyProposedAction | null = null;
  let provider: string | null = null;
  let errorMessage: string | null = null;

  for (;;) {
    const { done, value } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });

    let sep: number;
    while ((sep = buffer.indexOf('\n\n')) >= 0) {
      const frame = buffer.slice(0, sep).trim();
      buffer = buffer.slice(sep + 2);
      if (!frame.startsWith('data: ')) continue;
      const data = frame.slice(6);
      if (data === '[DONE]') continue;

      let obj: Record<string, unknown>;
      try {
        obj = JSON.parse(data) as Record<string, unknown>;
      } catch {
        continue;
      }

      if (typeof obj.conversation_id === 'string') {
        handlers.onConversationId?.(obj.conversation_id);
      } else if (typeof obj.token === 'string') {
        handlers.onToken?.(obj.token);
      } else if (obj.complete === true) {
        cleanReply = typeof obj.clean_reply === 'string' ? obj.clean_reply : cleanReply;
        provider = typeof obj.provider === 'string' ? obj.provider : provider;
      } else if (obj.persisted === true) {
        const parsed = eddyProposedActionSchema.safeParse(obj.proposed_action);
        proposedAction = parsed.success ? parsed.data : null;
      } else if (typeof obj.error === 'string') {
        errorMessage = obj.error;
      }
    }
  }

  if (errorMessage !== null) {
    handlers.onError?.(errorMessage);
  } else {
    handlers.onComplete?.({ cleanReply, proposedAction, provider });
  }
}
