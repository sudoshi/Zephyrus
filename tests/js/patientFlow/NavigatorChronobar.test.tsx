import React from 'react';
import { render, screen } from '@testing-library/react';
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
});
