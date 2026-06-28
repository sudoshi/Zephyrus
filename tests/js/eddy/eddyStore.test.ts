import { describe, it, expect, beforeEach } from 'vitest';
import { useEddyStore } from '@/stores/eddyStore';

describe('eddyStore', () => {
  beforeEach(() => {
    useEddyStore.setState({ isOpen: false, conversationId: null, messages: [], isSending: false });
  });

  it('opens, toggles and closes the dock', () => {
    const s = useEddyStore.getState();
    s.open();
    expect(useEddyStore.getState().isOpen).toBe(true);
    useEddyStore.getState().toggle();
    expect(useEddyStore.getState().isOpen).toBe(false);
  });

  it('pushes user and assistant messages immutably', () => {
    const before = useEddyStore.getState().messages;
    useEddyStore.getState().pushUser('what is the net bed need?');
    useEddyStore.getState().pushAssistant({ id: 'a1', role: 'assistant', content: '12 beds short', provider: 'ollama' });

    const after = useEddyStore.getState().messages;
    expect(after).not.toBe(before); // new array (immutable)
    expect(after).toHaveLength(2);
    expect(after[0].role).toBe('user');
    expect(after[1].provider).toBe('ollama');
  });

  it('reset clears the conversation', () => {
    useEddyStore.getState().setConversationId('uuid-123');
    useEddyStore.getState().pushUser('hi');
    useEddyStore.getState().reset();
    expect(useEddyStore.getState().conversationId).toBeNull();
    expect(useEddyStore.getState().messages).toHaveLength(0);
  });
});
