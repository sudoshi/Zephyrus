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

  // P6 WS-4 — the AlertTicker hand-off.
  it('openWithPrefill opens the dock with a draft and remembers the alert provenance', () => {
    useEddyStore.getState().openWithPrefill('Cockpit alert ed.nedocs (CRIT): …', 'ed.nedocs');
    const s = useEddyStore.getState();
    expect(s.isOpen).toBe(true);
    expect(s.draft).toBe('Cockpit alert ed.nedocs (CRIT): …');
    expect(s.alertKey).toBe('ed.nedocs');

    // The composer consumes the draft exactly once…
    useEddyStore.getState().clearDraft();
    expect(useEddyStore.getState().draft).toBeNull();
    // …but the provenance survives until the dock closes.
    expect(useEddyStore.getState().alertKey).toBe('ed.nedocs');
  });

  it('close clears both the draft and the alert provenance', () => {
    useEddyStore.getState().openWithPrefill('draft', 'ed.nedocs');
    useEddyStore.getState().close();
    const s = useEddyStore.getState();
    expect(s.isOpen).toBe(false);
    expect(s.draft).toBeNull();
    expect(s.alertKey).toBeNull();
  });
});
