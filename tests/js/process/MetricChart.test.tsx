import React from 'react';
import { render, screen } from '@testing-library/react';
import { LineChart } from 'recharts';
import MetricChart from '@/Components/Process/Intelligence/Common/MetricChart';
import { formatDurationMinutes } from '@/lib/duration';

vi.mock('recharts', () => ({
  ResponsiveContainer: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  LineChart: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
  CartesianGrid: () => null,
  XAxis: () => null,
  YAxis: ({ tickFormatter, width }: { tickFormatter?: (value: number) => string; width?: number }) => (
    <div data-testid="metric-chart-y-axis" data-width={width}>
      {tickFormatter?.(61.5)}
    </div>
  ),
  Tooltip: ({ formatter }: { formatter?: (value: number) => string }) => (
    <div data-testid="metric-chart-tooltip">{formatter?.(61.5)}</div>
  ),
  Legend: () => null,
}));

describe('MetricChart duration formatting', () => {
  it('passes opt-in formatters to the Y axis and tooltip', () => {
    render(
      <MetricChart
        yAxisTickFormatter={formatDurationMinutes}
        yAxisWidth={108}
        tooltipFormatter={formatDurationMinutes}
      >
        <LineChart />
      </MetricChart>,
    );

    expect(screen.getByTestId('metric-chart-y-axis')).toHaveTextContent('1 hr 1 min 30 sec');
    expect(screen.getByTestId('metric-chart-y-axis')).toHaveAttribute('data-width', '108');
    expect(screen.getByTestId('metric-chart-tooltip')).toHaveTextContent('1 hr 1 min 30 sec');
  });
});
