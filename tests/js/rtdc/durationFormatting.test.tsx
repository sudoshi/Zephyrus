import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import TrendsModal from '@/Components/RTDC/TrendsModal';
import FlowMetricsChart from '@/Components/RTDC/HistoricalMetrics/FlowMetricsChart';

vi.mock('@/Components/Analytics/Common/TrendChart', () => ({
  default: () => <div data-testid="trend-chart" />,
}));

describe('RTDC duration formatting', () => {
  it('formats historical flow KPI values instead of appending raw minute units', () => {
    render(
      <FlowMetricsChart data={[
        { date: '2026-07-08', edToAdmission: 60, dischargeToExit: 30, transferTime: 15 },
        { date: '2026-07-09', edToAdmission: 61.5166666667, dischargeToExit: 30.25, transferTime: 15.5 },
      ]} />,
    );

    expect(screen.getByText('1 hr 1 min 31 sec')).toBeInTheDocument();
    expect(screen.getByText('30 min 15 sec')).toBeInTheDocument();
    expect(screen.getByText('15 min 30 sec')).toBeInTheDocument();
  });

  it('formats Trends table and summary durations while preserving wall-clock cells', async () => {
    const firstTime = '2026-07-09T10:00:00Z';
    const trend = [
      { time: firstTime, value: 60.25 },
      { time: '2026-07-09T11:00:00Z', value: 61.5 },
    ];

    render(
      <TrendsModal
        isOpen
        onClose={vi.fn()}
        units={[{ id: 1, name: 'Unit A', services: { lab: true } }]}
        data={{ allTrends: [{ unitName: 'Unit A', serviceName: 'Lab', trend }] }}
      />,
    );

    await waitFor(() => expect(screen.getByText('1 hr 0 min 53 sec')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /Table View/i }));

    expect(screen.getByText('Wait Duration')).toBeInTheDocument();
    expect(screen.getAllByText('1 hr 0 min 15 sec').length).toBeGreaterThan(0);
    expect(screen.getAllByText(new Date(firstTime).toLocaleTimeString()).length).toBeGreaterThan(0);
    expect(screen.queryByText('Wait Time (min)')).not.toBeInTheDocument();
  });
});
