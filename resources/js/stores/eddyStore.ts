import { create } from 'zustand';

export interface EddyChatMessage {
  id: string;
  role: 'user' | 'assistant';
  content: string;
  provider?: string | null;
  model?: string | null;
  routeReason?: string | null;
  fallbackReason?: string | null;
  status?: 'success' | 'error';
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
}));
