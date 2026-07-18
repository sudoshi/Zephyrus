import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import NavigatorChronobar from '@/Components/PatientFlowNavigator/NavigatorChronobar';

describe('NavigatorChronobar', () => {
  it('shows stale historical coverage instead of a false empty 48-hour message', () => {
    const dataStart = Date.parse('2026-07-02T00:00:00Z');
    const dataEnd = Date.parse('2026-07-05T00:00:00Z');

    render(
      <NavigatorChronobar
        windowStart={dataStart}
        windowEnd={dataEnd}
        nowMs={Date.parse('2026-07-09T12:00:00Z')}
        currentTime={dataEnd}
        dataStart={dataStart}
        dataEnd={dataEnd}
        historical
        freshness="stale"
        forecast={null}
        onScrub={vi.fn()}
      />,
    );

    expect(screen.getByText(/Historical replay/)).toBeInTheDocument();
    expect(screen.getByText(/Stale source/)).toBeInTheDocument();
    expect(screen.queryByText(/No replay events/)).not.toBeInTheDocument();
    expect(screen.getByRole('slider', { name: /Historical patient flow/ })).toBeInTheDocument();
  });

  it('renders relative replay offsets as hours, minutes, and seconds', () => {
    const now = Date.parse('2026-07-09T12:00:00Z');
    const current = now + (90 * 60 + 31) * 1_000;

    render(
      <NavigatorChronobar
        windowStart={now - 3_600_000}
        windowEnd={now + 7_200_000}
        nowMs={now}
        currentTime={current}
        dataStart={now - 3_600_000}
        dataEnd={now}
        historical={false}
        freshness="fresh"
        forecast={null}
        onScrub={vi.fn()}
      />,
    );

    expect(screen.getByText('Projected · now +1 hr 30 min 31 sec')).toBeInTheDocument();
    expect(screen.queryByText(/1\.5h/)).not.toBeInTheDocument();
  });

  it('N-1: the Now button scrubs back to now in one click', () => {
    const now = Date.parse('2026-07-09T12:00:00Z');
    const onScrub = vi.fn();

    render(
      <NavigatorChronobar
        windowStart={now - 24 * 3_600_000}
        windowEnd={now + 24 * 3_600_000}
        nowMs={now}
        currentTime={now - 6 * 3_600_000}
        dataStart={now - 24 * 3_600_000}
        dataEnd={now}
        historical={false}
        freshness="fresh"
        forecast={null}
        onScrub={onScrub}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Now' }));
    expect(onScrub).toHaveBeenCalledWith(now);
  });

  it('N-1: the Now button is disabled in historical replay', () => {
    const dataStart = Date.parse('2026-07-02T00:00:00Z');
    const dataEnd = Date.parse('2026-07-05T00:00:00Z');

    render(
      <NavigatorChronobar
        windowStart={dataStart}
        windowEnd={dataEnd}
        nowMs={Date.parse('2026-07-09T12:00:00Z')}
        currentTime={dataEnd}
        dataStart={dataStart}
        dataEnd={dataEnd}
        historical
        freshness="stale"
        forecast={null}
        onScrub={vi.fn()}
      />,
    );

    expect(screen.getByRole('button', { name: 'Now' })).toBeDisabled();
  });

  it('N-2: shift detents and barrier ticks are focusable jump buttons', () => {
    const now = Date.parse('2026-07-09T12:00:00Z');
    const barrierOpened = now - 5 * 3_600_000;
    const onScrub = vi.fn();

    render(
      <NavigatorChronobar
        windowStart={now - 24 * 3_600_000}
        windowEnd={now + 24 * 3_600_000}
        nowMs={now}
        currentTime={now}
        dataStart={now - 24 * 3_600_000}
        dataEnd={now}
        historical={false}
        freshness="fresh"
        forecast={null}
        barrierTicks={[barrierOpened]}
        onScrub={onScrub}
      />,
    );

    // A 48h window always spans at least one 07:00/19:00 shift change.
    const detents = screen.getAllByRole('button', { name: /shift change$/ });
    expect(detents.length).toBeGreaterThan(0);
    fireEvent.click(detents[0]);
    expect(onScrub).toHaveBeenCalledTimes(1);
    const jumpedTo = onScrub.mock.calls[0][0] as number;
    expect(jumpedTo).toBeGreaterThanOrEqual(now - 24 * 3_600_000);
    expect(jumpedTo).toBeLessThanOrEqual(now + 24 * 3_600_000);

    fireEvent.click(screen.getByRole('button', { name: /Jump to barrier opened/ }));
    expect(onScrub).toHaveBeenLastCalledWith(barrierOpened);
  });

  it('N-8: labels the connected stream as a replay, never live', () => {
    const now = Date.parse('2026-07-09T12:00:00Z');

    render(
      <NavigatorChronobar
        windowStart={now - 24 * 3_600_000}
        windowEnd={now + 24 * 3_600_000}
        nowMs={now}
        currentTime={now}
        dataStart={now - 24 * 3_600_000}
        dataEnd={now}
        historical={false}
        freshness="fresh"
        forecast={null}
        replaying
        onScrub={vi.fn()}
      />,
    );

    expect(screen.getByText(/Replay stream ·/)).toBeInTheDocument();
    expect(screen.queryByText(/\bLive\b/)).not.toBeInTheDocument();
  });
});
