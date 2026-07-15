import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
  AgingHeatmap,
  BarrierChip,
  QueueDepthSparkline,
  SlaComplianceTile,
  metricTileSchema,
  readinessAxisSchema,
  sourceFreshnessSchema,
  worklistRowSchema,
  type MetricTileContract,
  type OperationalState,
} from '@/Components/Ancillary';

const freshness = {
  status: 'fresh' as const,
  asOf: '2026-07-11T14:00:00Z',
  sourceCutoffAt: '2026-07-11T13:59:00Z',
  lagMinutes: 1,
  sourceLabel: 'RIS',
  explanation: null,
};

function tile(status: OperationalState): MetricTileContract {
  return {
    key: `tile-${status}`,
    label: `${status} tile`,
    status,
    value: ['stale', 'no_data', 'loading'].includes(status) ? null : 88,
    displayValue: ['stale', 'no_data', 'loading'].includes(status) ? '—' : '88%',
    unit: 'percent',
    cohortCount: status === 'no_data' ? 0 : 12,
    median: status === 'no_data' ? null : 32,
    p90: status === 'no_data' ? null : 55,
    freshness: status === 'stale' ? { ...freshness, status: 'stale', lagMinutes: 90 } : freshness,
    definition: null,
    explanation: status === 'degraded' ? 'Intermediate assertions unavailable.' : null,
  };
}

describe('ancillary Zod contracts', () => {
  it('accepts the canonical freshness shape and rejects aliases, impossible values, and extra keys', () => {
    expect(sourceFreshnessSchema.safeParse(freshness).success).toBe(true);
    expect(sourceFreshnessSchema.safeParse({ ...freshness, asOf: undefined, evaluatedAt: freshness.asOf }).success).toBe(false);
    expect(sourceFreshnessSchema.safeParse({ ...freshness, lagMinutes: -1 }).success).toBe(false);
    expect(sourceFreshnessSchema.safeParse({ ...freshness, status: 'unknown', sourceCutoffAt: freshness.sourceCutoffAt }).success).toBe(false);
  });

  it('rejects malformed readiness, metric, and worklist rows at the browser boundary', () => {
    expect(readinessAxisSchema.safeParse({ key: 'lab', label: 'Lab', status: 'ready', state: 'ready', pendingCount: 0, oldestAgeMinutes: null, blocking: true, freshness, drillTarget: null, topOrderUuid: null, drillHref: null }).success).toBe(false);
    expect(metricTileSchema.safeParse({ ...tile('normal'), cohortCount: -1 }).success).toBe(false);
    expect(worklistRowSchema.safeParse({ orderUuid: 'not-a-uuid', department: 'rad', label: 'CT', priority: 'stat', patientRef: 'P1', locationLabel: null, status: 'normal', ageMinutes: 10, barrierCount: 0, readiness: [], freshness }).success).toBe(false);
  });
});

describe('ancillary operational visual states', () => {
  it('renders all canonical metric states and intentional unavailable values', () => {
    const states: OperationalState[] = ['normal', 'warning', 'breach', 'stale', 'no_data', 'degraded', 'loading'];
    render(<div>{states.map((state) => <SlaComplianceTile key={state} value={tile(state)} />)}</div>);
    expect(screen.getByRole('img', { name: 'Within target' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Approaching threshold' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Threshold breached' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Stale · metric unavailable' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'No cohort data' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Degraded · partial feed' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Loading metric' })).toBeInTheDocument();
    expect(screen.getAllByText('Unavailable')).toHaveLength(3);
  });

  it('provides tabular fallbacks for the heatmap and sparkline, including missing observations', () => {
    render(<><AgingHeatmap title="Aging" cells={[{ key: 'a', rowLabel: 'Radiology', columnLabel: '61+ min', count: null, state: 'no_data' }]} /><QueueDepthSparkline title="Queue" points={[{ at: '2026-07-11T13:00:00Z', value: 4 }, { at: '2026-07-11T14:00:00Z', value: null }]} /></>);
    fireEvent.click(screen.getByText('View heatmap data table'));
    fireEvent.click(screen.getByText('View queue-depth data table'));
    expect(screen.getByRole('table', { name: 'Aging values' })).toHaveTextContent('Unavailable');
    expect(screen.getByRole('table', { name: 'Queue values' })).toHaveTextContent('Unavailable');
  });

  it('opens an accessible barrier drawer with evidence and next action', () => {
    render(<BarrierChip barrier={{ key: 'barrier', label: 'Final report pending', owner: null, ageMinutes: 72, severity: 'breach', explanation: 'No final assertion.', nextAction: 'Escalate the reading queue.' }} />);
    fireEvent.click(screen.getByRole('button', { name: 'Final report pending' }));
    expect(screen.getByRole('dialog', { name: 'Final report pending' })).toHaveTextContent('No final assertion.');
    expect(screen.getByRole('dialog')).toHaveTextContent('Escalate the reading queue.');
  });
});
