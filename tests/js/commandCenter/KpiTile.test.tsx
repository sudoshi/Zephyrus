// tests/js/commandCenter/KpiTile.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { KpiTile } from '@/Components/CommandCenter/KpiTile';
import type { KpiMetric } from '@/types/commandCenter';

const base: KpiMetric = {
  key: 'occupancy', label: 'Occupancy', value: 88, unit: '%', display: '88%',
  target: 85, targetDisplay: '≤85%', status: 'warning',
  trajectory: { points: [82, 84, 88], direction: 'up', goodWhenDown: true },
  drillHref: '/rtdc/bed-tracking', definition: 'Staffed occupancy.',
};

describe('KpiTile', () => {
  it('renders label, value, target and definition', () => {
    render(<KpiTile metric={base} />);
    expect(screen.getByText('Occupancy')).toBeInTheDocument();
    expect(screen.getByText('88%')).toBeInTheDocument();
    expect(screen.getByText(/Target/)).toBeInTheDocument();
    expect(screen.getByLabelText(/Definition: Staffed occupancy/)).toBeInTheDocument();
  });

  it('wraps in a drill link when drillHref is set', () => {
    render(<KpiTile metric={base} />);
    const link = screen.getByTestId('kpi-occupancy').closest('a');
    expect(link).toHaveAttribute('href', '/rtdc/bed-tracking');
  });

  it('renders without a link when drillHref is null', () => {
    render(<KpiTile metric={{ ...base, key: 'x', drillHref: null }} />);
    expect(screen.getByTestId('kpi-x').closest('a')).toBeNull();
  });

  it('renders source trust when lineage metadata is available', () => {
    render(<KpiTile metric={{
      ...base,
      sourceTrust: {
        score: 92,
        status: 'success',
        freshSourceCount: 2,
        staleSourceCount: 0,
        missingSourceCount: 0,
      },
      lineageHref: '/api/analytics/metrics/occupancy/lineage',
      lineageSummary: '92% trust from 2 source(s): Capacity census, ED flow.',
    }} />);

    expect(screen.getByLabelText('Source trust: 92%')).toBeInTheDocument();
    expect(screen.getByText('Trust 92%')).toBeInTheDocument();
  });

  it('renders a sparkline for non-circle metrics with trajectory data', () => {
    render(<KpiTile metric={{ ...base, key: 'net_beds', unit: 'beds', display: '4', value: 4 }} />);
    expect(screen.getByTestId('sparkline-net_beds')).toBeInTheDocument();
  });

  it('keeps percent metrics on the circle gauge treatment', () => {
    render(<KpiTile metric={base} />);
    expect(screen.queryByTestId('sparkline-occupancy')).not.toBeInTheDocument();
  });

  it('renders detail visualization and sparkline for detailed percent metrics', () => {
    render(<KpiTile metric={{
      ...base,
      key: 'surge_prob',
      label: 'Surge Probability',
      detail: {
        caption: '24h surge model drivers',
        segments: [
          { label: 'Occupancy', value: 32, display: '+32 pp', status: 'warning' },
          { label: 'Bed deficit', value: 10, display: '+10 pp', status: 'warning' },
        ],
        rows: [
          { label: 'Occupancy now', value: '88%', status: 'warning' },
          { label: 'Net beds now', value: '-2', status: 'critical' },
        ],
      },
    }} />);

    expect(screen.getByTestId('metric-detail-surge_prob')).toBeInTheDocument();
    expect(screen.getByTestId('sparkline-surge_prob')).toBeInTheDocument();
    expect(screen.getByText('24h surge model drivers')).toBeInTheDocument();
    expect(screen.getByText('Net beds now')).toBeInTheDocument();
  });
});
