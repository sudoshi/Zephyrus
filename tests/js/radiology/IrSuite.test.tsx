import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import IrSuitePage from '@/Pages/Analytics/IrSuite';
import { irSuiteSchema, type IrSuite } from '@/features/radiology/schemas';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, ...props }: any) => <a href={href} {...props}>{children}</a>,
}));
vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: any) => <main>{children}</main> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ title, subtitle, headerContent, children }: any) => <><h1>{title}</h1><p>{subtitle}</p>{headerContent}{children}</> }));
vi.mock('@/Components/Ancillary', () => ({ SourceFreshnessBadge: () => <span>Fresh IR source</span> }));
vi.mock('@/features/radiology/hooks', () => ({ useIrSuite: (data: IrSuite) => ({ data }) }));
vi.mock('recharts', () => ({
  ResponsiveContainer: ({ children }: any) => <div>{children}</div>, BarChart: ({ children }: any) => <div>{children}</div>, LineChart: ({ children }: any) => <div>{children}</div>,
  Bar: ({ name }: any) => <span>{name}</span>, Line: ({ name }: any) => <span>{name}</span>, CartesianGrid: () => null, Legend: () => null, Tooltip: () => null, XAxis: () => null, YAxis: () => null,
}));

const at = '2026-07-12T14:00:00+00:00';
const roomUuid = '11111111-1111-4111-8111-111111111111';
const examUuid = '22222222-2222-4222-8222-222222222222';
const assertion = { milestoneUuid: '33333333-3333-4333-8333-333333333333', code: 'RAD_EXAM_START', occurredAt: '2026-07-12T08:10:00+00:00', receivedAt: '2026-07-12T08:11:00+00:00', sourceKey: 'demo.mpps', sourceRank: 10, assertionCount: 1 };
const distribution = { count: 1, median: 40, p90: 40, meanMinutes: 40 };

function payload(): IrSuite {
  return irSuiteSchema.parse({
    generatedAt: at, sourceCutoffAt: at, freshnessStatus: 'fresh', degradedMode: true, state: 'degraded',
    stateMessage: 'IR Study results are partial.', freshness: { status: 'fresh', asOf: at, sourceCutoffAt: at, lagMinutes: 0, sourceLabel: 'IR selected milestone feeds', explanation: null },
    filters: { dateFrom: '2026-07-12', dateTo: '2026-07-12', roomUuid: null, patientClass: null, limit: 500 },
    filterOptions: { rooms: [{ roomUuid, label: 'IR Suite 1' }], patientClasses: ['emergency', 'inpatient'], maxRangeDays: 31, maxLimit: 1000 },
    ownership: { operationalOwner: 'Radiology Workspace', studyOwner: 'Analytics', definitionAuthority: 'App\\Services\\Analytics\\SuiteMetricCalculator', radiologyHref: '/radiology/worklist?modality=IR', perioperativeHref: '/operations/room-status', perioperativeStudyHref: '/analytics/or-utilization', statement: 'Radiology owns live IR; Analytics owns Study.' },
    definitions: { shared: [{ label: 'Suite utilization', definition: 'Occupied minutes divided by explicit available minutes.', authority: 'SuiteMetricCalculator::utilization' }, { label: 'Room turnover', definition: 'Prior end to next start.', authority: 'SuiteMetricCalculator::turnoverMinutes' }], denominator: 'Deployment-declared staffed windows only.', cohort: 'Declared IR cases.', cutoff: 'Newest selected assertion.' },
    summary: { declaredRoomCount: 1, candidateCaseCount: 1, analyzedCaseCount: 1, completedCaseCount: 1, availableMinutes: 480, occupiedMinutes: 50, plannedDowntimeMinutes: 0, unplannedDowntimeMinutes: 0, idleMinutes: 430, utilizationPercent: 10.4, reconciliationDeltaMinutes: 0, fcots: { eligibleCount: 1, onTimeCount: 1, percent: 100, graceMinutes: 15 }, turnover: distribution },
    roomRunning: { sampleCount: 12, averageRoomsRunning: 0.1, maxRoomsRunning: 1, points: [{ hour: '08:00', averageRoomsRunning: 1, maxRoomsRunning: 1, sampleDays: 1 }], definition: 'Distinct declared rooms with an overlapping interval.' },
    gates: [
      { key: 'preparation', label: 'Order to preparation complete', startMilestoneCode: 'RAD_ORDERED', stopMilestoneCode: 'RAD_PREP_COMPLETE', ...distribution, missingCount: 0, invalidCount: 0, sourceCutoffAt: at, definition: 'Selected assertions.' },
      { key: 'transport', label: 'Transport request to patient arrival', startMilestoneCode: 'RAD_TRANSPORT_REQUESTED', stopMilestoneCode: 'RAD_TRANSPORT_COMPLETE', count: 0, median: null, p90: null, meanMinutes: null, missingCount: 1, invalidCount: 0, sourceCutoffAt: at, definition: 'Selected assertions.' },
      { key: 'read', label: 'Images available to final report', startMilestoneCode: 'RAD_IMAGES_AVAILABLE', stopMilestoneCode: 'RAD_FINAL', ...distribution, missingCount: 0, invalidCount: 0, sourceCutoffAt: at, definition: 'Selected assertions.' },
    ],
    coverage: { status: 'partial', candidateIntervalCount: 1, coveredIntervalCount: 1, percent: 100, missingIntervalCount: 0, invalidIntervalCount: 0, missingOperatingWindowRoomCount: 0, uncoveredRoomCount: 0, missingGatePairCount: 1, invalidGateIntervalCount: 0, truncated: false, unanalyzedCandidateCount: 0, definition: 'Coverage ledger.' },
    rooms: [{ roomUuid, label: 'IR Suite 1', capacity: 1, timezone: 'UTC', operatingWindows: [{ startAt: '2026-07-12T08:00:00+00:00', endAt: '2026-07-12T16:00:00+00:00' }], caseCount: 1, completedCaseCount: 1, availableMinutes: 480, occupiedMinutes: 50, plannedDowntimeMinutes: 0, unplannedDowntimeMinutes: 0, idleMinutes: 430, utilizationPercent: 10.4, reconciliationDeltaMinutes: 0, fcots: { firstCaseCount: 1, eligibleCount: 1, onTimeCount: 1, percent: 100, missingActualStartCount: 0 }, turnover: { ...distribution, invalidCount: 0 }, coverage: { status: 'complete', candidateIntervalCount: 1, coveredIntervalCount: 1, missingIntervalCount: 0, invalidIntervalCount: 0, warning: null }, segments: [{ startAt: '2026-07-12T08:00:00+00:00', endAt: '2026-07-12T08:10:00+00:00', type: 'idle', minutes: 10 }, { startAt: '2026-07-12T08:10:00+00:00', endAt: '2026-07-12T09:00:00+00:00', type: 'exam', minutes: 50 }] }],
    lineage: { count: 1, truncated: false, definition: 'Selected assertion audit.', items: [{ examUuid, roomUuid, roomLabel: 'IR Suite 1', procedureCode: 'IR_DRAIN', scheduledStartAt: '2026-07-12T08:00:00+00:00', actualStartAt: '2026-07-12T08:10:00+00:00', actualEndAt: '2026-07-12T09:00:00+00:00', isFirstCase: true, fcotsOnTime: true, turnoverFromPriorMinutes: null, startAssertion: assertion, endAssertion: { ...assertion, milestoneUuid: '44444444-4444-4444-8444-444444444444', code: 'RAD_EXAM_END', occurredAt: '2026-07-12T09:00:00+00:00', receivedAt: '2026-07-12T09:01:00+00:00' } }] },
    privacy: { patientIdentifiersIncluded: false, clinicalReportTextIncluded: false, identifierPolicy: 'Operational UUIDs only.' },
  });
}

describe('IR Suite Study', () => {
  it('renders explicit denominators, shared definitions, additive gates, ownership links, and selected assertion lineage', () => {
    render(<IrSuitePage irSuite={payload()} />);

    expect(screen.getByRole('heading', { name: 'IR Suite Study' })).toBeInTheDocument();
    expect(screen.getByLabelText('From')).toHaveValue('2026-07-12');
    expect(screen.getByLabelText('Declared room')).toHaveValue('');
    expect(screen.getByRole('img', { name: /utilization by declared room/i })).toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Accessible IR suite room utilization summary' })).toHaveTextContent('IR Suite 1');
    expect(screen.getByText(/Denominator: Deployment-declared staffed windows only/)).toBeInTheDocument();
    expect(screen.getByRole('img', { name: /shared Perioperative overlap definition/i })).toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Accessible IR imaging gate summary' })).toHaveTextContent('RAD_TRANSPORT_REQUESTED → RAD_TRANSPORT_COMPLETE');
    expect(screen.getByRole('table', { name: 'IR shared suite metric definitions' })).toHaveTextContent('SuiteMetricCalculator::utilization');
    expect(screen.getByRole('link', { name: 'Open live IR worklist' })).toHaveAttribute('href', '/radiology/worklist?modality=IR');
    expect(screen.getByRole('link', { name: 'Open OR room status' })).toHaveAttribute('href', '/operations/room-status');
    expect(screen.getByRole('alert')).toHaveTextContent('1 missing imaging-gate pair');
    fireEvent.click(screen.getByText(/Selected assertion and shared-clock audit/));
    expect(screen.getByRole('table', { name: 'IR Suite selected assertion lineage' })).toHaveTextContent('demo.mpps');
    expect(document.body).not.toHaveTextContent(/ir-test-patient/i);
  });
});
