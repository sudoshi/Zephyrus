import { describe, it, expect, beforeAll } from 'vitest';
import { render } from '@testing-library/react';
import { EddyMessageList } from '@/Components/Eddy/EddyMessageList';
import type { EddyChatMessage } from '@/stores/eddyStore';

beforeAll(() => {
  // jsdom has no scrollIntoView; the list auto-scrolls on mount.
  Element.prototype.scrollIntoView = () => {};
});

describe('EddyMessageList', () => {
  it('renders the empty state with the advice-not-autopilot promise', () => {
    const { getByText } = render(<EddyMessageList messages={[]} isSending={false} />);
    expect(getByText(/Ask Eddy about this screen/i)).toBeTruthy();
    expect(getByText(/never take an action without your approval/i)).toBeTruthy();
  });

  it('renders user and assistant turns with a provider label', () => {
    const messages: EddyChatMessage[] = [
      { id: 'u1', role: 'user', content: 'what is the net bed need?' },
      { id: 'a1', role: 'assistant', content: 'Net bed need is 12.', provider: 'ollama' },
    ];
    const { getByText } = render(<EddyMessageList messages={messages} isSending={false} />);
    expect(getByText('what is the net bed need?')).toBeTruthy();
    expect(getByText('Net bed need is 12.')).toBeTruthy();
    expect(getByText(/MedGemma · local/i)).toBeTruthy();
  });

  it('shows a fallback chip with a text label (status never by colour alone)', () => {
    const messages: EddyChatMessage[] = [
      { id: 'a1', role: 'assistant', content: 'Answered locally.', provider: 'ollama', fallbackReason: 'phi_detected' },
    ];
    const { getByText } = render(<EddyMessageList messages={messages} isSending={false} />);
    expect(getByText(/fell back · phi_detected/i)).toBeTruthy();
  });

  it('honours the design canon — no raw white/gray surfaces', () => {
    const messages: EddyChatMessage[] = [{ id: 'a1', role: 'assistant', content: 'hi', provider: 'ollama' }];
    const { container } = render(<EddyMessageList messages={messages} isSending={false} />);
    expect(container.innerHTML).not.toMatch(/\bbg-white\b/);
    expect(container.innerHTML).not.toMatch(/\bbg-gray-/);
  });
});
