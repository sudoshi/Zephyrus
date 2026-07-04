// tests/js/cockpit/ExecutiveBriefPanel.test.tsx
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { ExecutiveBriefPanel } from '@/Components/cockpit/ExecutiveBriefPanel';

const mockRun = vi.fn();

vi.mock('@/features/ops/hooks', () => ({
  useRunAgent: () => mockRun(),
}));

vi.mock('@inertiajs/react', () => ({
  Link: ({ href, children, className }: { href: string; children: React.ReactNode; className?: string }) => (
    <a href={href} className={className}>{children}</a>
  ),
}));

describe('ExecutiveBriefPanel', () => {
  beforeEach(() => {
    mockRun.mockReset();
  });

  it('composes ON DEMAND — never on mount (slower cadence than the gauges)', () => {
    const mutate = vi.fn();
    mockRun.mockReturnValue({ mutate, isPending: false, data: undefined });

    render(<ExecutiveBriefPanel />);
    expect(mutate).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: 'Compose brief' }));
    expect(mutate).toHaveBeenCalledWith('executive_briefing_agent');
  });

  it('renders the composed headline + situation glyphs and keeps the full-brief deep-link', () => {
    mockRun.mockReturnValue({
      mutate: vi.fn(),
      isPending: false,
      data: {
        agentKey: 'executive_briefing_agent',
        output: {
          headline: 'House strained: ED boarding is the constraint.',
          situation: [
            { domain: 'ED', status: 'critical', detail: 'NEDOCS severe' },
            { domain: 'Periop', status: 'success', detail: 'On plan' },
          ],
        },
      },
    });

    render(<ExecutiveBriefPanel />);
    expect(screen.getByText('House strained: ED boarding is the constraint.')).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Critical' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /full brief/i })).toHaveAttribute('href', '/ops/executive-brief');
  });
});
