import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import RadiologyReadsPage from '@/Pages/Radiology/Reads';
import { radiologyReadsSchema, type RadiologyReads } from '@/features/radiology/schemas';

vi.mock('@inertiajs/react', () => ({ Head: () => null }));
vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: any) => <main>{children}</main> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ title, subtitle, headerContent, children }: any) => <><h1>{title}</h1><p>{subtitle}</p>{headerContent}{children}</> }));
vi.mock('@/Components/Ancillary', () => ({ SourceFreshnessBadge: () => <span>Fresh reporting source</span> }));
vi.mock('@/features/radiology/hooks', () => ({ useRadiologyReads: (data: RadiologyReads) => ({ data }) }));
vi.mock('recharts', () => ({
  ResponsiveContainer: ({ children }: any) => <div>{children}</div>, LineChart: ({ children }: any) => <div>{children}</div>,
  Line: ({ name }: any) => <span>{name}</span>, CartesianGrid: () => null, Legend: () => null, Tooltip: () => null, XAxis: () => null, YAxis: () => null,
}));

function payload(): RadiologyReads {
  const at = '2026-07-12T14:00:00+00:00';
  return radiologyReadsSchema.parse({
    generatedAt: at, sourceCutoffAt: at, state: 'normal', stateMessage: 'Radiology read and result facts are current.',
    freshness: { status: 'fresh', asOf: at, sourceCutoffAt: at, lagMinutes: 0, sourceLabel: 'Radiology reporting feeds', explanation: null },
    filters: { state: 'unread', priority: null, subspecialty: null, modality: null, windowHours: 12, limit: 25 },
    filterOptions: { states: ['unread', 'no_report', 'preliminary', 'final', 'corrected'], priorities: ['stat', 'urgent', 'routine', 'discharge'], subspecialties: [{ code: 'neuro', label: 'Neuroradiology' }], modalities: [{ code: 'CT', label: 'Computed Tomography' }], windowHours: [6, 12, 24, 48] },
    health: { unreadCount: 1, oldestUnreadAgeMinutes: 90, unreadByPriority: [{ priority: 'stat', count: 1, oldestAgeMinutes: 90 }], openCriticalLoopCount: 1, oldestCriticalLoopAgeMinutes: 30, sourceState: 'fresh', sourceCutoffAt: at },
    unread: { total: 1, oldestAgeMinutes: 90, byPriority: [{ priority: 'stat', count: 1, oldestAgeMinutes: 90 }], bySubspecialty: [{ code: 'neuro', label: 'Neuroradiology', count: 1, oldestAgeMinutes: 90 }] },
    reportStates: [{ state: 'no_report', count: 1 }, { state: 'preliminary', count: 0 }, { state: 'final', count: 2 }, { state: 'corrected', count: 1 }],
    backlog: { bucketMinutes: 60, windowStart: '2026-07-12T12:00:00+00:00', windowEnd: at, comparable: true, points: [{ bucketStart: '2026-07-12T13:00:00+00:00', bucketEnd: at, openAtEnd: 1, entered: 2, finalized: 1, netChange: 1 }], missing: { completionTimestampCount: 0, finalTimestampCount: 0 }, definition: 'Full 60-minute buckets; the current partial hour is excluded.' },
    preliminaryToFinal: { count: 2, medianMinutes: 25, p90Minutes: 30, maxMinutes: 30, missingPreliminaryCount: 1, excludedNegativeCount: 0, definition: 'First preliminary timestamp to first final timestamp per exam. Corrections do not move the original final clock.' },
    criticalLoops: { summary: { total: 2, open: 1, oldestOpenAgeMinutes: 30, byState: [{ state: 'pending_notification', count: 0 }, { state: 'notified', count: 1 }, { state: 'acknowledged', count: 1 }, { state: 'escalated', count: 0 }, { state: 'closed', count: 0 }] }, timings: { identifiedToNotified: { count: 1, medianMinutes: 5, p90Minutes: 5 }, notifiedToAcknowledged: { count: 1, medianMinutes: 10, p90Minutes: 10 } }, openItems: [{ criticalResultUuid: '11111111-1111-4111-8111-111111111111', examUuid: '22222222-2222-4222-8222-222222222222', findingClass: 'critical', state: 'notified', priority: 'stat', modality: 'CT', identifiedAt: at, ageMinutes: 30, recipientRole: 'ordering_clinician', drillHref: '/radiology/worklist?search=33333333-3333-4333-8333-333333333333' }] },
    items: [{ examUuid: '22222222-2222-4222-8222-222222222222', orderUuid: '33333333-3333-4333-8333-333333333333', patientRef: 'RAD-001', label: 'CT Head', priority: 'stat', patientClass: 'emergency', modality: 'CT', subspecialtyCode: 'neuro', subspecialtyLabel: 'Neuroradiology', reportState: 'no_report', urgency: 'breach', ageMinutes: 90, completedAt: at, firstPreliminaryAt: null, firstFinalAt: null, latestCorrectedAt: null, latestReadUuid: null, sourceReportVersion: null, correctionCount: 0, isTeleradiology: false, definition: null, drillHref: '/radiology/worklist?search=33333333-3333-4333-8333-333333333333' }],
    privacy: { clinicalReportTextIncluded: false, identifierPolicy: 'Only pseudonymous operational identifiers are returned.' },
  });
}

describe('Radiology Reads and Results', () => {
  it('renders filters, backlog, report states, critical loop, queue drill, and privacy boundary', () => {
    render(<RadiologyReadsPage radiologyReads={payload()} />);

    expect(screen.getByRole('heading', { name: 'Reads and Results' })).toBeInTheDocument();
    expect(screen.getByLabelText('Report state')).toHaveValue('unread');
    expect(screen.getByRole('img', { name: /Radiology unread backlog/i })).toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Accessible Radiology read backlog summary' })).toBeInTheDocument();
    expect(screen.getByText('CT Head')).toHaveAttribute('href', expect.stringContaining('/radiology/worklist?search='));
    expect(screen.getByText('Report text excluded')).toBeInTheDocument();
    expect(screen.getByText(/corrections do not move the original final clock/i)).toBeInTheDocument();
    expect(screen.queryByText(/clinical report narrative content/i)).not.toBeInTheDocument();
  });
});
