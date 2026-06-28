import { describe, it, expect, vi, beforeEach } from 'vitest';
import { streamEddyChat } from '@/features/eddy/stream';

function mockFetchWithFrames(frames: string[]) {
  const encoder = new TextEncoder();
  let i = 0;
  const reader = {
    read: vi.fn(async () =>
      i < frames.length ? { done: false, value: encoder.encode(frames[i++]) } : { done: true, value: undefined },
    ),
  };
  return vi.fn().mockResolvedValue({ ok: true, body: { getReader: () => reader } });
}

describe('streamEddyChat', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('parses conversation_id + tokens and finalizes with the sanitized proposal', async () => {
    global.fetch = mockFetchWithFrames([
      'data: {"conversation_id":"c-1"}\n\n',
      'data: {"token":"Flag"}\n\n',
      'data: {"token":"ging it."}\n\n',
      'data: {"complete":true,"clean_reply":"Flagging it.","provider":"ollama"}\n\n',
      'data: {"persisted":true,"proposed_action":{"action_type":"flag_barrier","title":"x","params":{"unit":"3W"},"rationale":"y","runner_up":"z","tier":"T1","risk":"low","label":"l"}}\n\n',
      'data: [DONE]\n\n',
    ]) as unknown as typeof fetch;

    const tokens: string[] = [];
    let convId = '';
    let complete: { cleanReply: string; proposedAction: { action_type: string; tier: string } | null } | null = null;

    await streamEddyChat(
      { message: 'flag it', surface: 'rtdc' },
      {
        onConversationId: (id) => {
          convId = id;
        },
        onToken: (t) => tokens.push(t),
        onComplete: (d) => {
          complete = d as never;
        },
      },
    );

    expect(convId).toBe('c-1');
    expect(tokens.join('')).toBe('Flagging it.');
    expect(complete!.cleanReply).toBe('Flagging it.');
    expect(complete!.proposedAction?.action_type).toBe('flag_barrier');
    expect(complete!.proposedAction?.tier).toBe('T1');
  });

  it('reassembles frames split across chunks', async () => {
    global.fetch = mockFetchWithFrames([
      'data: {"conversa',
      'tion_id":"c-2"}\n\ndata: {"token":"hi"}\n\n',
      'data: {"complete":true,"clean_reply":"hi"}\n\n',
      'data: {"persisted":true,"proposed_action":null}\n\n',
    ]) as unknown as typeof fetch;

    let convId = '';
    const tokens: string[] = [];
    let complete: { proposedAction: unknown } | null = null;

    await streamEddyChat(
      { message: 'x', surface: 'chat' },
      {
        onConversationId: (id) => {
          convId = id;
        },
        onToken: (t) => tokens.push(t),
        onComplete: (d) => {
          complete = d as never;
        },
      },
    );

    expect(convId).toBe('c-2');
    expect(tokens).toEqual(['hi']);
    expect(complete!.proposedAction).toBeNull();
  });

  it('calls onError when the response is not ok', async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: false, body: null }) as unknown as typeof fetch;
    let err = '';
    await streamEddyChat({ message: 'x', surface: 'chat' }, { onError: (m) => { err = m; } });
    expect(err).toBeTruthy();
  });
});
