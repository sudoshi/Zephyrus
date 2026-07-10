import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Triage from '@/Pages/ED/Operations/Triage';

vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: any) => <>{children}</> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ children }: any) => <>{children}</> }));
vi.mock('@/Components/Common/StudyLink', () => ({ StudyLink: () => null }));
vi.mock('@/Components/system', () => ({
  metric: (value: any) => value,
  Section: ({ children }: any) => <section>{children}</section>,
  Panel: ({ children }: any) => <div>{children}</div>,
  EmptyState: ({ message }: any) => <div>{message}</div>,
  MetricGrid: ({ metrics }: any) => (
    <div>
      {metrics.map((item: any) => (
        <div key={item.key}>
          <span>{item.display}</span>
          <span>{item.targetDisplay}</span>
          <span>{item.caption}</span>
          <span>{item.definition}</span>
        </div>
      ))}
    </div>
  ),
}));

describe('Triage duration target', () => {
  it('uses the shared duration display in visible target copy and definition', () => {
    render(<Triage kpis={{ medianDoorToTriage: 12 }} />);

    expect(screen.getByText('Target: 10 min 0 sec')).toBeInTheDocument();
    expect(screen.getByText('Median time from arrival to triage completion. Target 10 min 0 sec.')).toBeInTheDocument();
    expect(screen.queryByText(/10m/)).not.toBeInTheDocument();
  });
});
