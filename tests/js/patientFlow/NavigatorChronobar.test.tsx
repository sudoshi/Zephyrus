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
});
