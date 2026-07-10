// tests/js/commandCenter/Band.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Band } from '@/Components/CommandCenter/Band';
import type { BandData, KpiMetric } from '@/types/commandCenter';

const metric = (key: string, label: string): KpiMetric => ({
  key, label, value: 1, unit: '', display: '1', target: null, targetDisplay: null,
  status: 'success', trajectory: null, drillHref: null, definition: `${label} def`,
});

const flat: BandData = {
  key: 'capacity', title: 'Capacity', summary: '88% occupied',
  drillHref: '/rtdc/bed-tracking', drillLabel: 'open RTDC',
  metrics: [metric('available_beds', 'Available'), metric('blocked_beds', 'Blocked')],
};

const grouped: BandData = {
  key: 'flow', title: 'Flow', summary: 'ED / IP / OR', drillHref: '/dashboard/emergency',
  drillLabel: 'open ED', metrics: [],
  subgroups: [
    { key: 'ed', label: 'Emergency', metrics: [metric('ed_d2p', 'Door-to-Provider')] },
    { key: 'or', label: 'Operating Room', metrics: [metric('fcots', 'First-Case On-Time')] },
  ],
};

describe('Band', () => {
  it('renders a flat band header, summary, drill link, and tiles', () => {
    render(<Band band={flat} />);
    expect(screen.getByRole('heading', { name: 'Capacity' })).toBeInTheDocument();
    expect(screen.getByText('88% occupied')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /open RTDC/ })).toHaveAttribute('href', '/rtdc/bed-tracking');
    expect(screen.getByText('Available')).toBeInTheDocument();
    expect(screen.getByText('Blocked')).toBeInTheDocument();
  });

  it('renders subgroup labels and their tiles', () => {
    render(<Band band={grouped} />);
    expect(screen.getByText('Emergency')).toBeInTheDocument();
    expect(screen.getByText('Operating Room')).toBeInTheDocument();
    expect(screen.getByText('Door-to-Provider')).toBeInTheDocument();
    expect(screen.getByText('First-Case On-Time')).toBeInTheDocument();
  });

  it('suppresses the band drill link on a static wall', () => {
    render(<Band band={flat} interactive={false} />);
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
  });

  it('shows an empty state instead of a blank grid when a flat band has no metrics', () => {
    render(<Band band={{ ...flat, metrics: [] }} />);
    expect(screen.getByText('No capacity metrics reporting')).toBeInTheDocument();
  });

  it('shows a per-subgroup empty state when a subgroup has no metrics', () => {
    const emptySub: BandData = {
      ...grouped,
      subgroups: [{ key: 'ed', label: 'Emergency', metrics: [] }],
    };
    render(<Band band={emptySub} />);
    expect(screen.getByText('No Emergency metrics reporting')).toBeInTheDocument();
  });
});
