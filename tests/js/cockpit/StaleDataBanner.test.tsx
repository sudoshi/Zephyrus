// tests/js/cockpit/StaleDataBanner.test.tsx
//
// P8 WS-6b — the app-chrome-wide freshness banner. Escalates from a quiet aging
// cue to a loud, solid amber stale banner with a retry; renders nothing when
// data is fresh (so it can sit unconditionally in the app chrome).
import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { StaleDataBanner } from '@/Components/cockpit/StaleDataBanner';

describe('StaleDataBanner', () => {
  it('renders the loud stale banner with a working retry', () => {
    const onRetry = vi.fn();
    render(<StaleDataBanner stale updatedLabel="4 min ago" onRetry={onRetry} />);

    const banner = screen.getByRole('alert');
    expect(banner).toHaveTextContent(/Live updates interrupted/i);
    expect(banner).toHaveTextContent('4 min ago');
    fireEvent.click(screen.getByRole('button', { name: /retry now/i }));
    expect(onRetry).toHaveBeenCalledTimes(1);
  });

  it('renders the quiet aging cue (no retry) when aging but not stale', () => {
    render(<StaleDataBanner stale={false} aging updatedLabel="1 min ago" onRetry={() => {}} />);

    expect(screen.getByText(/Data aging/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /retry now/i })).not.toBeInTheDocument();
  });

  it('renders nothing when data is fresh (safe to mount unconditionally)', () => {
    const { container } = render(
      <StaleDataBanner stale={false} aging={false} updatedLabel="just now" onRetry={() => {}} />,
    );
    expect(container).toBeEmptyDOMElement();
  });

  it('prefers the loud stale banner over the aging cue when both are set', () => {
    render(<StaleDataBanner stale aging updatedLabel="5 min ago" onRetry={() => {}} />);
    expect(screen.getByRole('button', { name: /retry now/i })).toBeInTheDocument();
    expect(screen.queryByText(/Data aging/i)).not.toBeInTheDocument();
  });

  it('keeps the wall stale warning visible without mounting a retry control', () => {
    render(<StaleDataBanner stale updatedLabel="4 min ago" />);

    expect(screen.getByRole('alert')).toHaveTextContent(/Live updates interrupted/i);
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });
});
