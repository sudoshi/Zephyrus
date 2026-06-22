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
});
