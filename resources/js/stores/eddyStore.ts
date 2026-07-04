import { create } from 'zustand';
import type { EddyProposedAction } from '@/features/eddy/schemas';

export type EddyProposalState = 'pending' | 'approved' | 'denied';

export interface EddyChatMessage {
  id: string;
  role: 'user' | 'assistant';
  content: string;
  provider?: string | null;
  model?: string | null;
  routeReason?: string | null;
  fallbackReason?: string | null;
  status?: 'success' | 'error';
  proposedAction?: EddyProposedAction | null;
  proposalState?: EddyProposalState;
}

interface EddyState {
  isOpen: boolean;
  conversationId: string | null;
  messages: EddyChatMessage[];
  isSending: boolean;
  /** P6: composer prefill consumed (and cleared) by EddySlideOver on open. */
  draft: string | null;
  /** P6: the cockpit alert that opened the dock — proposal provenance. */
  alertKey: string | null;

  open: () => void;
  close: () => void;
  toggle: () => void;
  reset: () => void;
  /**
   * P6 WS-4 — the AlertTicker hand-off. Pre-seeds the composer with the
   * alert context and remembers the alert key so an approved proposal
   * carries its provenance. The human still reviews and sends — advice,
   * not autopilot.
   */
  openWithPrefill: (draft: string, alertKey?: string) => void;
  clearDraft: () => void;
  pushUser: (content: string) => void;
  pushAssistant: (message: EddyChatMessage) => void;
  setSending: (sending: boolean) => void;
  setConversationId: (id: string | null) => void;
  setProposalState: (messageId: string, state: EddyProposalState) => void;
  appendAssistant: (messageId: string, chunk: string) => void;
  finalizeAssistant: (
    messageId: string,
    data: { content?: string; provider?: string | null; proposedAction?: EddyProposedAction | null; status?: 'success' | 'error' },
  ) => void;
}

export const useEddyStore = create<EddyState>((set) => ({
  isOpen: false,
  conversationId: null,
  messages: [],
  isSending: false,
  draft: null,
  alertKey: null,

  open: () => set({ isOpen: true }),
  close: () => set({ isOpen: false, draft: null, alertKey: null }),
  toggle: () => set((state) => ({ isOpen: !state.isOpen })),
  reset: () => set({ conversationId: null, messages: [], isSending: false, draft: null, alertKey: null }),
  openWithPrefill: (draft, alertKey) => set({ isOpen: true, draft, alertKey: alertKey ?? null }),
  clearDraft: () => set({ draft: null }),
  pushUser: (content) =>
    set((state) => ({
      messages: [...state.messages, { id: crypto.randomUUID(), role: 'user', content }],
    })),
  pushAssistant: (message) => set((state) => ({ messages: [...state.messages, message] })),
  setSending: (sending) => set({ isSending: sending }),
  setConversationId: (id) => set({ conversationId: id }),
  setProposalState: (messageId, proposalState) =>
    set((state) => ({
      messages: state.messages.map((m) => (m.id === messageId ? { ...m, proposalState } : m)),
    })),
  appendAssistant: (messageId, chunk) =>
    set((state) => ({
      messages: state.messages.map((m) => (m.id === messageId ? { ...m, content: m.content + chunk } : m)),
    })),
  finalizeAssistant: (messageId, data) =>
    set((state) => ({
      messages: state.messages.map((m) =>
        m.id === messageId
          ? {
              ...m,
              content: data.content !== undefined && data.content !== '' ? data.content : m.content,
              provider: data.provider ?? m.provider,
              status: data.status ?? m.status,
              proposedAction: data.proposedAction ?? null,
              proposalState: data.proposedAction ? 'pending' : m.proposalState,
            }
          : m,
      ),
    })),
}));
