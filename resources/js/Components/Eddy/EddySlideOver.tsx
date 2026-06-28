import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { Surface } from '@/Components/ui/Surface';
import { useEddyStore } from '@/stores/eddyStore';
import { useEddyContext } from '@/features/eddy/hooks';
import { streamEddyChat } from '@/features/eddy/stream';
import { EddyMessageList } from './EddyMessageList';
import { EddyMark } from './EddyMark';

const SURFACE_LABEL: Record<string, string> = {
  chat: 'General',
  rtdc: 'RTDC',
  ed: 'ED',
  periop: 'Perioperative',
  transport: 'Transport',
  evs: 'EVS',
  staffing: 'Staffing',
  improvement: 'Improvement',
  command_center: 'Command Center',
};

export function EddySlideOver() {
  const { close, messages, pushUser, pushAssistant, appendAssistant, finalizeAssistant, setSending, isSending, conversationId, setConversationId } = useEddyStore();
  const ctx = useEddyContext();
  const [text, setText] = useState('');

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') close();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [close]);

  const send = async () => {
    const message = text.trim();
    if (!message || isSending) return;
    setText('');
    pushUser(message);

    // Stream the reply token-by-token; the assistant bubble fills in live, then
    // finalizes with the clean reply + any proposed action on completion.
    const assistantId = crypto.randomUUID();
    pushAssistant({ id: assistantId, role: 'assistant', content: '' });
    setSending(true);

    await streamEddyChat(
      { message, surface: ctx.surface, page_context: ctx.pageContext, conversation_id: conversationId },
      {
        onConversationId: (id) => setConversationId(id),
        onToken: (token) => appendAssistant(assistantId, token),
        onComplete: ({ cleanReply, proposedAction, provider }) =>
          finalizeAssistant(assistantId, { content: cleanReply, provider, proposedAction }),
        onError: (msg) =>
          finalizeAssistant(assistantId, { content: msg || 'Eddy is unavailable right now.', status: 'error' }),
      },
    );

    setSending(false);
  };

  const onSubmit = (event: React.FormEvent) => {
    event.preventDefault();
    void send();
  };

  const onComposerKeyDown = (event: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      void send();
    }
  };

  return createPortal(
    <div className="fixed inset-0 z-[80] flex justify-end" role="dialog" aria-modal="true" aria-label="Eddy assistant">
      <div className="absolute inset-0 bg-black/30" onClick={close} aria-hidden="true" />
      <Surface className="relative flex h-full w-full max-w-md flex-col rounded-none border-l shadow-lg">
        {/* Header */}
        <div className="flex items-center justify-between border-b border-healthcare-border px-4 py-3 dark:border-healthcare-border-dark">
          <div className="flex items-center gap-2">
            <span className="text-healthcare-primary dark:text-healthcare-primary-dark">
              <EddyMark size={24} />
            </span>
            <div className="leading-tight">
              <p className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Eddy</p>
              <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {SURFACE_LABEL[ctx.surface] ?? 'General'} · advice, not autopilot
              </p>
            </div>
          </div>
          <button
            type="button"
            onClick={close}
            aria-label="Close Eddy"
            className="rounded-md p-1.5 text-healthcare-text-secondary hover:bg-healthcare-surface-hover dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-surface-hover-dark"
          >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
              <path d="M18 6 6 18M6 6l12 12" />
            </svg>
          </button>
        </div>

        {/* Messages */}
        <EddyMessageList messages={messages} isSending={isSending} surface={ctx.surface} />

        {/* Composer */}
        <form onSubmit={onSubmit} className="border-t border-healthcare-border p-3 dark:border-healthcare-border-dark">
          <div className="flex items-end gap-2">
            <textarea
              value={text}
              onChange={(event) => setText(event.target.value)}
              onKeyDown={onComposerKeyDown}
              rows={1}
              placeholder="Ask Eddy…"
              aria-label="Message Eddy"
              className="max-h-32 min-h-[2.5rem] flex-1 resize-none rounded-md border border-healthcare-border bg-healthcare-surface px-3 py-2 text-sm text-healthcare-text-primary placeholder:text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark"
            />
            <button
              type="submit"
              disabled={isSending || text.trim().length === 0}
              className="rounded-md bg-healthcare-primary px-3 py-2 text-sm font-medium text-white hover:bg-healthcare-primary-hover disabled:opacity-50 dark:bg-healthcare-primary-dark"
            >
              Send
            </button>
          </div>
        </form>
      </Surface>
    </div>,
    document.body,
  );
}
