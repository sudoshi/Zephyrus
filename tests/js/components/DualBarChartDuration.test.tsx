import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import DualBarChart from '@/Components/Dashboard/Charts/DualBarChart';

vi.mock('@/hooks/useDarkMode', () => ({
  useDarkMode: () => [false],
  HEALTHCARE_COLORS: {
    light: {
      border: '#ccc',
      surface: '#fff',
      critical: '#d00',
      warning: '#e90',
      success: '#090',
      info: '#09f',
      text: { primary: '#111', secondary: '#555' },
    },
  },
}));

vi.mock('recharts', () => ({
  ResponsiveContainer: ({ children }: any) => <>{children}</>,
  BarChart: ({ children }: any) => <>{children}</>,
  CartesianGrid: () => null,
  XAxis: () => null,
  YAxis: ({ label, tickFormatter }: any) => (
    <div data-testid="duration-axis" data-label={label.value}>
      {tickFormatter(61.5)}
    </div>
  ),
  Tooltip: ({ content }: any) => content.type({
    ...content.props,
    active: true,
    label: 'ENT',
    payload: [{ payload: { room: 61.5, procedure: 45.25, total: 106.75 } }],
  }),
  Legend: () => null,
  ReferenceLine: () => null,
  Bar: () => null,
}));

describe('DualBarChart duration formatting', () => {
  it('formats tooltip values, variance, totals, targets, and axis ticks', () => {
    render(<DualBarChart data={{ ENT: { room: 61.5, procedure: 45.25 } }} />);

    expect(screen.getByTestId('duration-axis')).toHaveAttribute('data-label', 'Duration');
    expect(screen.getAllByText('1 hr 1 min 30 sec')).toHaveLength(2);
    expect(screen.getByText('(+16 min 30 sec)')).toBeInTheDocument();
    expect(screen.getByText('45 min 15 sec')).toBeInTheDocument();
    expect(screen.getByText('(+15 min 15 sec)')).toBeInTheDocument();
    expect(screen.getByText('1 hr 46 min 45 sec')).toBeInTheDocument();
    expect(screen.getByText('Target: 45 min 0 sec')).toBeInTheDocument();
    expect(screen.getByText('Target: 30 min 0 sec')).toBeInTheDocument();
  });
});
