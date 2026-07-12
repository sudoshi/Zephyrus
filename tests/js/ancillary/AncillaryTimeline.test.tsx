import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
  AncillaryOrderTimeline,
  PageClockProvider,
  ReadinessVector,
  type AncillaryTimelineContract,
  type ReadinessAxisContract,
} from '@/Components/Ancillary';

const now = new Date('2026-07-11T14:00:00Z');

function timeline(overrides: Partial<AncillaryTimelineContract> = {}): AncillaryTimelineContract {
  return {
    orderUuid: '11111111-1111-4111-8111-111111111111',
    label: 'STAT CT head',
    milestones: [
      { code: 'RAD_ORDERED', label: 'Ordered', state: 'done', required: true, occurredAt: '2026-07-11T12:00:00Z', selectedSource: 'EHR', assertionCount: 1, conflict: false },
      { code: 'RAD_EXAM_END', label: 'Exam complete', state: 'current', required: true, occurredAt: '2026-07-11T13:00:00Z', selectedSource: 'MPPS', assertionCount: 2, conflict: true },
      { code: 'RAD_FINAL', label: 'Final report', state: 'pending_required', required: true, occurredAt: null, selectedSource: null, assertionCount: 0, conflict: false },
      { code: 'RAD_PRELIM', label: 'Preliminary', state: 'missing_optional', required: false, occurredAt: null, selectedSource: null, assertionCount: 0, conflict: false },
      { code: 'LAB_REJECTED', label: 'Rejected', state: 'exception', required: false, occurredAt: '2026-07-11T13:05:00Z', selectedSource: 'LIS', assertionCount: 1, conflict: false },
      { code: 'LAB_RECOLLECT_ORDERED', label: 'Recollect requested', state: 'exception', required: false, occurredAt: '2026-07-11T13:07:00Z', selectedSource: 'LIS', assertionCount: 1, conflict: false },
      { code: 'RAD_CANCELLED', label: 'Cancelled', state: 'terminal', required: false, occurredAt: '2026-07-11T13:10:00Z', selectedSource: 'RIS', assertionCount: 1, conflict: false },
    ],
    clock: { metricKey: 'rad.stat', label: 'Order to final', state: 'breached', startMilestoneCode: 'RAD_ORDERED', stopMilestoneCode: 'RAD_FINAL', startedAt: '2026-07-11T12:00:00Z', stoppedAt: null, elapsedMinutes: 120, warningMinutes: 90, breachMinutes: 120, definitionUuid: '22222222-2222-4222-8222-222222222222' },
    freshness: { status: 'fresh', asOf: now.toISOString(), sourceCutoffAt: '2026-07-11T13:59:00Z', lagMinutes: 1, sourceLabel: 'RIS', explanation: null },
    degradedMode: false,
    degradedExplanation: null,
    ...overrides,
  };
}

function renderTimeline(value = timeline(), onOpenDefinition = vi.fn()) {
  return render(<PageClockProvider initialNow={now}><AncillaryOrderTimeline value={value} onOpenDefinition={onOpenDefinition} /></PageClockProvider>);
}

describe('AncillaryOrderTimeline', () => {
  it('identifies every milestone state with text and shape, not color alone', () => {
    renderTimeline();
    for (const label of ['Completed', 'Current selected milestone', 'Pending required milestone', 'Optional milestone not asserted', 'Exception or rework milestone', 'Terminal milestone']) {
      expect(screen.getAllByRole('img', { name: label }).length).toBeGreaterThan(0);
      expect(screen.getAllByText(new RegExp(label)).length).toBeGreaterThan(0);
    }
    expect(screen.getByText(/Source conflict · 2 assertions retained/)).toBeInTheDocument();
    expect(screen.getByText('Recollect requested')).toBeInTheDocument();
  });

  it('renders breached clock with stable tabular timer and keyboard definition access', () => {
    const open = vi.fn();
    renderTimeline(timeline(), open);
    const timer = screen.getByRole('timer', { name: /Order to final: breached/ });
    expect(timer).toHaveTextContent('120 min elapsed · 0 min remaining');
    expect(timer.querySelector('.tabular-nums')).toHaveClass('min-w-[18ch]');
    const button = screen.getByRole('button', { name: 'View clock definition' });
    button.focus();
    fireEvent.keyDown(button, { key: 'Enter' });
    fireEvent.click(button);
    expect(open).toHaveBeenCalledWith('22222222-2222-4222-8222-222222222222');
  });

  it('renders degraded, stale, cancelled, recollect/conflict and warehouse-as-of states honestly', () => {
    const { rerender } = render(<PageClockProvider initialNow={now}><AncillaryOrderTimeline value={timeline({ degradedMode: true, degradedExplanation: 'Ordered and final only' })} /></PageClockProvider>);
    expect(screen.getByText(/Degraded feed: Ordered and final only/)).toBeInTheDocument();
    rerender(<PageClockProvider initialNow={now}><AncillaryOrderTimeline value={timeline({ freshness: { ...timeline().freshness, status: 'batch' } })} /></PageClockProvider>);
    expect(screen.getByText(/Warehouse as-of/)).toBeInTheDocument();
    rerender(<PageClockProvider initialNow={now}><AncillaryOrderTimeline value={timeline({ freshness: { ...timeline().freshness, status: 'stale' }, clock: { ...timeline().clock!, state: 'unknown' } })} /></PageClockProvider>);
    expect(screen.getByText(/ · stale/)).toBeInTheDocument();
    expect(screen.getByRole('timer', { name: /unknown/ })).toBeInTheDocument();
  });

  it('uses one page-level interval for multiple visible timelines', () => {
    vi.useFakeTimers();
    const interval = vi.spyOn(window, 'setInterval');
    const { unmount } = render(<PageClockProvider initialNow={now}><AncillaryOrderTimeline value={timeline()} /><AncillaryOrderTimeline value={timeline({ orderUuid: '33333333-3333-4333-8333-333333333333', label: 'Second order' })} /></PageClockProvider>);
    expect(interval).toHaveBeenCalledTimes(1);
    unmount();
    interval.mockRestore();
    vi.useRealTimers();
  });
});

describe('ReadinessVector', () => {
  const axes: ReadinessAxisContract[] = [
    { key: 'imaging', label: 'Imaging', status: 'ready', pendingCount: 0, oldestAgeMinutes: 0, blocking: false, freshness: timeline().freshness, drillTarget: '/rtdc/imaging', explanation: null },
    { key: 'lab', label: 'Lab', status: 'pending', pendingCount: 2, oldestAgeMinutes: 44, blocking: true, freshness: timeline().freshness, drillTarget: '/rtdc/lab', explanation: null },
    { key: 'medication', label: 'Medication', status: 'blocked', pendingCount: 1, oldestAgeMinutes: 61, blocking: true, freshness: { ...timeline().freshness, status: 'batch' }, drillTarget: '/rtdc/medication', explanation: null },
    { key: 'stale', label: 'Stale source', status: 'ready', pendingCount: 0, oldestAgeMinutes: null, blocking: false, freshness: { ...timeline().freshness, status: 'stale' }, drillTarget: '/rtdc/stale', explanation: null },
  ];

  it('renders ready, pending, blocked and stale-as-unknown axes with accessible drills', () => {
    const drill = vi.fn();
    render(<ReadinessVector axes={axes} onDrill={drill} />);
    expect(screen.getByRole('img', { name: 'Ready' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Pending' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Blocked' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Unknown' })).toBeInTheDocument();
    expect(screen.getByText(/Warehouse as-of/)).toBeInTheDocument();
    expect(screen.getByText(/age unavailable/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Open Lab: Pending' }));
    expect(drill).toHaveBeenCalledWith('/rtdc/lab');
  });
});
