import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import LastMonthSection from '@/Components/Dashboard/LastMonthSection';
import MonthToDateSection from '@/Components/Dashboard/MonthToDateSection';
import DrillDownModal from '@/Components/Dashboard/DrillDownModal';

vi.mock('@/Components/Common/Modal', () => ({
  default: ({ open, title, children }: any) => open ? (
    <section aria-label={title}>
      <h2>{title}</h2>
      {children}
    </section>
  ) : null,
}));

vi.mock('@/Components/cockpit/Sparkline', () => ({
  Sparkline: () => <div data-testid="sparkline" />,
}));

vi.mock('@/Components/Dashboard/Charts/ServiceBarChart', () => ({ default: () => null }));
vi.mock('@/Components/Dashboard/Charts/DualBarChart', () => ({ default: () => null }));
vi.mock('@/Components/Dashboard/Charts/StackedBarChart', () => ({ default: () => null }));
vi.mock('@/Components/Dashboard/Charts/LineChart', () => ({ default: () => null }));
vi.mock('@/Components/Dashboard/WorkbenchReports', () => ({ default: () => null }));

describe('dashboard duration formatting', () => {
  it('keeps Last Month turnover formatting through its drill-down', () => {
    render(<LastMonthSection />);

    expect(screen.getByText('31 min 0 sec')).toBeInTheDocument();
    const turnoverButton = screen.getByText('Average Room Turnover').closest('button');
    expect(turnoverButton).not.toBeNull();
    fireEvent.click(turnoverButton!);

    expect(screen.getByRole('heading', { name: 'Average Room Turnover Details' })).toBeInTheDocument();
    expect(screen.getByText('33 min 0 sec')).toBeInTheDocument();
    expect(screen.getByText('-2 min 0 sec')).toBeInTheDocument();
  });

  it('does not reinterpret compound units as elapsed time', () => {
    render(
      <DrillDownModal
        isOpen
        onClose={vi.fn()}
        metric={{
          key: 'throughput',
          label: 'Throughput',
          value: 12,
          display: '12 patients/min',
          unit: 'patients/min',
          status: 'neutral',
          target: null,
          targetDisplay: null,
          trajectory: { points: [10, 12], direction: 'up', goodWhenDown: false },
          drillHref: null,
          definition: 'Patients processed per minute.',
        } as any}
      />,
    );

    expect(screen.getByText('10 patients/min')).toBeInTheDocument();
    expect(screen.getByText('+2 patients/min')).toBeInTheDocument();
  });

  it('rounds duration changes once at the whole-second boundary', () => {
    render(
      <DrillDownModal
        isOpen
        onClose={vi.fn()}
        metric={{
          key: 'turnover',
          label: 'Turnover',
          value: 61.50834,
          display: '1 hr 1 min 31 sec',
          unit: 'min',
          status: 'warning',
          target: null,
          targetDisplay: null,
          trajectory: { points: [30, 61.50834], direction: 'up', goodWhenDown: true },
          drillHref: null,
          definition: 'Elapsed turnover time.',
        } as any}
      />,
    );

    expect(screen.getByText('+31 min 31 sec')).toBeInTheDocument();
  });

  it('renders Month-to-Date turnover summaries as decomposed durations', () => {
    render(<MonthToDateSection />);

    expect(screen.getByText('32 min 0 sec')).toBeInTheDocument();
    expect(screen.getByText('22 min 0 sec')).toBeInTheDocument();
    expect(screen.getByText('1 hr 8 min 0 sec')).toBeInTheDocument();
  });
});
