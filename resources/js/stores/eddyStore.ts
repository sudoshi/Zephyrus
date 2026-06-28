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

  open: () => void;
  close: () => void;
  toggle: () => void;
  reset: () => void;
  pushUser: (content: string) => void;
  pushAssistant: (message: EddyChatMessage) => void;
  setSending: (sending: boolean) => void;
  setConversationId: (id: string | null) => void;
  setProposalState: (messageId: string, state: EddyProposalState) => void;
}

export const useEddyStore = create<EddyState>((set) => ({
  isOpen: false,
  conversationId: null,
  messages: [],
  isSending: false,

  open: () => set({ isOpen: true }),
  close: () => set({ isOpen: false }),
  toggle: () => set((state) => ({ isOpen: !state.isOpen })),
  reset: () => set({ conversationId: null, messages: [], isSending: false }),
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
}));
