import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Worklist from '@/Pages/Radiology/Worklist';
import { radiologyWorklistSchema, type RadiologyWorklist } from '@/features/radiology/schemas';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, ...props }: any) => <a href={href} {...props}>{children}</a>,
}));
vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: any) => <main>{children}</main> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ title, subtitle, headerContent, children }: any) => <><h1>{title}</h1><p>{subtitle}</p>{headerContent}{children}</> }));
vi.mock('@/features/radiology/hooks', () => ({ useRadiologyWorklist: (data: RadiologyWorklist) => ({ data, refetch: vi.fn() }) }));

const freshness = { status: 'fresh' as const, asOf: '2026-07-11T14:00:00+00:00', sourceCutoffAt: '2026-07-11T13:55:00+00:00', lagMinutes: 5, sourceLabel: 'Radiology operational feeds', explanation: null };
const ordered = { code: 'RAD_ORDERED', label: 'Order placed', state: 'done' as const, required: true, occurredAt: '2026-07-11T12:00:00+00:00', selectedSource: 'demo.ris', assertionCount: 1, conflict: false };
const transport = { code: 'RAD_TRANSPORT_REQUESTED', label: 'Transport requested', state: 'done' as const, required: false, occurredAt: '2026-07-11T12:20:00+00:00', selectedSource: 'demo.transport', assertionCount: 1, conflict: false };
const final = { code: 'RAD_FINAL', label: 'Final report', state: 'current' as const, required: true, occurredAt: '2026-07-11T13:00:00+00:00', selectedSource: 'demo.reporting', assertionCount: 2, conflict: true };

function payload(overrides: Partial<RadiologyWorklist> = {}): RadiologyWorklist {
  return radiologyWorklistSchema.parse({
    generatedAt: '2026-07-11T14:00:00+00:00', freshness,
    filters: { lens: 'discharge', priority: null, modality: null, unitId: null, state: null, sort: 'oldest', search: null, source: 'rtdc', risk: false, perPage: 1, cursor: null },
    filterOptions: { lenses: ['all', 'ed', 'inpatient', 'discharge', 'degraded'], priorities: ['routine'], modalities: [{ code: 'CT', label: 'Computed tomography' }], units: [], sorts: ['oldest', 'newest', 'priority', 'breach_risk'], deepLinkSources: ['flow_board', 'rtdc'] },
    predictiveSort: {
      available: true, enabled: true, requested: true, explanation: 'Predictive scoring is available as an opt-in planning aid.',
      model: {
        modelVersion: 'radiology-breach-risk-2026.07.13-synthetic-v1', modelFamily: 'calibrated_logistic', calibratedAt: '2026-07-13T12:00:00+00:00',
        synthetic: true, syntheticLabel: 'Synthetic demo calibration - planning aid only.', trainingWindow: { cohortSize: 900 }, featureSchema: ['age_minutes'],
        evaluation: { calibrationError: 0.04, discriminationAuc: 0.89, brierScore: 0.16, coverage: { fraction: 1 }, naiveBaseline: { brierScore: 0.23 }, beatsBaseline: true },
      },
    },
    data: [{
      orderId: 1, orderUuid: '11111111-1111-4111-8111-111111111111', label: 'Discharge-pending chest CT', patientRef: 'demo-patient', patientClass: 'inpatient', priority: 'routine', modality: 'CT', locationLabel: '5 East', ageMinutes: 120, status: 'breach', currentState: 'final',
      downstreamImpact: { edDecision: false, dischargeBlocking: true, orCaseId: null },
      readiness: [{ key: 'imaging', label: 'Imaging', status: 'blocked', state: 'blocked', pendingCount: 1, oldestAgeMinutes: 120, blocking: true, freshness, drillTarget: '/radiology/worklist?search=11111111-1111-4111-8111-111111111111&source=flow_board', topOrderUuid: '11111111-1111-4111-8111-111111111111', drillHref: '/radiology/worklist?search=11111111-1111-4111-8111-111111111111&source=flow_board' }],
      barriers: [{ barrierId: 9, reasonCode: 'RAD_READ_QUEUE', label: 'Interpretation queue delay', owner: 'Radiology operations', openedAt: '2026-07-11T13:30:00+00:00' }],
      sourceAssertions: [
        { milestoneUuid: '22222222-2222-4222-8222-222222222222', code: 'RAD_FINAL', occurredAt: '2026-07-11T13:00:00+00:00', receivedAt: '2026-07-11T13:01:00+00:00', sourceKey: 'demo.reporting', sourceRank: 1, selected: true },
        { milestoneUuid: '33333333-3333-4333-8333-333333333333', code: 'RAD_FINAL', occurredAt: '2026-07-11T12:58:00+00:00', receivedAt: '2026-07-11T13:02:00+00:00', sourceKey: 'demo.ris', sourceRank: 2, selected: false },
      ],
      transportSegment: [transport],
      risk: null,
      timeline: { orderUuid: '11111111-1111-4111-8111-111111111111', label: 'Discharge-pending chest CT', milestones: [ordered, transport, final], clock: { metricKey: 'rad.ed_image_final', label: 'ED images available to final', state: 'complete', startMilestoneCode: 'RAD_IMAGES_AVAILABLE', stopMilestoneCode: 'RAD_FINAL', startedAt: '2026-07-11T12:40:00+00:00', stoppedAt: '2026-07-11T13:00:00+00:00', elapsedMinutes: 20, warningMinutes: 30, breachMinutes: 60, definitionUuid: '44444444-4444-4444-8444-444444444444' }, freshness, degradedMode: false, degradedExplanation: null },
    }],
    privacy: { patientContextIncluded: true, identifierPolicy: 'Authorized pseudonymous patient context.' },
    meta: { perPage: 1, count: 1, hasMore: true, nextCursor: 'next-cursor', previousCursor: null },
    ...overrides,
  });
}

describe('Radiology Worklist', () => {
  it('renders deep-link context, downstream impact, bounded cursor navigation, and predictive seam', () => {
    render(<Worklist worklist={payload()} />);
    expect(screen.getByRole('heading', { name: 'Radiology Order Worklist' })).toBeInTheDocument();
    expect(screen.getByText('rtdc').closest('p')).toHaveTextContent('Filtered deep link from rtdc');
    expect(screen.getByText('Discharge blocking')).toBeInTheDocument();
    expect(screen.getByText('Interpretation queue delay')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Next/ })).toHaveAttribute('href', expect.stringContaining('cursor=next-cursor'));
  });

  it('expands the shared timeline and distinguishes selected from retained assertions', () => {
    render(<Worklist worklist={payload()} />);
    fireEvent.click(screen.getByText('Expand milestone and source detail'));
    expect(screen.getByText('Transport requested')).toBeInTheDocument();
    expect(screen.getByText(/Source conflict · 2 assertions retained/)).toBeInTheDocument();
    fireEvent.click(screen.getByText('View 2 retained source assertions'));
    expect(screen.getByText('Selected')).toBeInTheDocument();
    expect(screen.getByText('Retained')).toBeInTheDocument();
  });

  it('renders degraded and empty results without inventing transport or rows', () => {
    const degraded = payload();
    degraded.data[0].transportSegment = null;
    degraded.data[0].timeline.milestones = [ordered, { ...final, state: 'pending_required', occurredAt: null, selectedSource: null, assertionCount: 0, conflict: false }];
    degraded.data[0].timeline.degradedMode = true;
    degraded.data[0].timeline.degradedExplanation = 'One or more minimum-feed milestones are unavailable.';
    const { rerender } = render(<Worklist worklist={radiologyWorklistSchema.parse(degraded)} />);
    fireEvent.click(screen.getByText('Expand milestone and source detail'));
    expect(screen.getByText(/Degraded feed/)).toBeInTheDocument();
    expect(screen.queryByText('Transport requested')).not.toBeInTheDocument();

    rerender(<Worklist worklist={payload({ data: [], meta: { perPage: 1, count: 0, hasMore: false, nextCursor: null, previousCursor: null } })} />);
    expect(screen.getByText('No Radiology orders match the allowlisted filters.')).toBeInTheDocument();
  });
});
