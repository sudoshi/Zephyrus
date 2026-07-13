import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import Specimens from '@/Pages/Lab/Specimens';
import { labSpecimensSchema, type LabSpecimens } from '@/features/lab/schemas';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, ...props }: any) => <a href={href} {...props}>{children}</a>,
}));
vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: any) => <main>{children}</main> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ title, subtitle, headerContent, children }: any) => <><h1>{title}</h1><p>{subtitle}</p>{headerContent}{children}</> }));
vi.mock('@/features/lab/hooks', () => ({ useLabSpecimens: (data: LabSpecimens) => ({ data, refetch: vi.fn() }) }));

const parentUuid = '11111111-1111-4111-8111-111111111111';
const childUuid = '22222222-2222-4222-8222-222222222222';
const orderUuid = '33333333-3333-4333-8333-333333333333';

function stage(code: string, label: string, at: string | null, state: 'complete' | 'pending' | 'not_asserted' | 'exception') { return { code, label, at, state }; }

function payload(overrides: Partial<LabSpecimens> = {}): LabSpecimens {
  const base = {
    generatedAt: '2026-07-11T14:00:00+00:00', state: 'normal', stateMessage: 'Laboratory specimen facts are current and transport-segmented.',
    freshness: { status: 'fresh', asOf: '2026-07-11T14:00:00+00:00', sourceCutoffAt: '2026-07-11T13:58:00+00:00', lagMinutes: 2, sourceLabel: 'Laboratory specimen feeds', explanation: null },
  filters: { status: null, testFamily: null, unitId: null, priority: null, rejection: 'all', age: 'all', orderUuid: null, perPage: 25, cursor: null },
    filterOptions: { statuses: ['collection_pending', 'recollect_requested'], testFamilies: ['troponin'], units: [{ unitId: 1, label: 'ED' }], priorities: ['stat'], rejections: ['all', 'rejected', 'recollect', 'none'], ageBands: ['all', '0_29', '30_59', '60_119', '120_plus'] },
    coverage: { transport: { status: 'available', columnVisible: true, explanation: 'Transport timestamps are evidenced.' } },
    data: [
      {
        specimenUuid: parentUuid, orderUuid, accessionIdentity: { sourceSpecimenKey: 'SPEC-1', sourceAccessionKey: 'ACC-1', sourceKey: 'demo.lab' },
        patientRef: 'demo-ed-patient', patientClass: 'emergency', priority: 'stat', testFamily: 'troponin', unitLabel: 'ED', specimenType: 'plasma', containerType: 'tube', collectorRole: 'nurse', collectionMethod: 'venipuncture',
        status: 'recollect_requested', rejectionReasonCode: 'HEMOLYZED', ageMinutes: 80,
        timeline: [stage('ordered', 'Ordered', '2026-07-11T12:30:00+00:00', 'complete'), stage('collected', 'Collected', '2026-07-11T12:40:00+00:00', 'complete'), stage('in_transit', 'In transit', '2026-07-11T12:45:00+00:00', 'complete'), stage('received', 'Received', null, 'pending'), stage('rejected', 'Rejected', '2026-07-11T12:55:00+00:00', 'exception')],
        result: null, chain: { rootSpecimenUuid: parentUuid, depth: 0, position: 1, length: 2, parentSpecimenUuid: null, childSpecimenUuids: [childUuid], representativeSpecimenUuid: childUuid },
        downstreamImpact: null, decisionRepresentedBySpecimenUuid: childUuid, sourceCutoffAt: '2026-07-11T13:58:00+00:00',
      },
      {
        specimenUuid: childUuid, orderUuid, accessionIdentity: { sourceSpecimenKey: 'SPEC-1-R', sourceAccessionKey: 'ACC-1', sourceKey: 'demo.lab' },
        patientRef: 'demo-ed-patient', patientClass: 'emergency', priority: 'stat', testFamily: 'troponin', unitLabel: 'ED', specimenType: 'plasma', containerType: 'tube', collectorRole: null, collectionMethod: null,
        status: 'collection_pending', rejectionReasonCode: null, ageMinutes: 20,
        timeline: [stage('ordered', 'Ordered', '2026-07-11T12:30:00+00:00', 'complete'), stage('collected', 'Collected', null, 'pending'), stage('in_transit', 'In transit', null, 'not_asserted'), stage('received', 'Received', null, 'pending')],
        result: { resultUuid: '44444444-4444-4444-8444-444444444444', testLabel: 'Troponin I', status: 'preliminary', stage: 'preliminary', abnormalFlag: 'critical', autoVerified: false, critical: true, resultedAt: '2026-07-11T13:10:00+00:00', verifiedAt: null, correctedAt: null, versionCount: 1 },
        chain: { rootSpecimenUuid: parentUuid, depth: 1, position: 2, length: 2, parentSpecimenUuid: parentUuid, childSpecimenUuids: [], representativeSpecimenUuid: childUuid },
        downstreamImpact: { decision_class: 'ed_disposition', blocked_object_type: 'ed_visit', blocked_object_id: 7, explanation: 'ED disposition waits for the viable recollect result.' }, decisionRepresentedBySpecimenUuid: childUuid, sourceCutoffAt: '2026-07-11T13:58:00+00:00',
      },
    ],
    privacy: { patientContextIncluded: true, directPatientIdentifiersIncluded: false, resultContentIncluded: false, identifierPolicy: 'Pseudonymous only.' },
    meta: { perPage: 25, count: 2, hasMore: false, nextCursor: null, previousCursor: null },
  };

  return labSpecimensSchema.parse({ ...base, ...overrides });
}

function renderTracker(value = payload()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<Specimens specimens={value} />, { wrapper: ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider> });
}

describe('Laboratory Specimen Tracker', () => {
  it('renders complete timelines, accession identity, result state, chain drill, and one downstream decision', () => {
    renderTracker();
    expect(screen.getByRole('heading', { name: 'Specimen Tracker' })).toBeInTheDocument();
    expect(screen.getByText('Accession ACC-1 · specimen SPEC-1')).toBeInTheDocument();
    expect(screen.getAllByText('In transit')).toHaveLength(2);
    expect(screen.getByText(/Troponin I · preliminary · critical/)).toBeInTheDocument();
    expect(screen.getByText(/Parent 11111111/)).toBeInTheDocument();
    expect(screen.getByText(/Children 22222222/)).toBeInTheDocument();
    expect(screen.getByText(/ED disposition waits for the viable recollect result/)).toBeInTheDocument();
    expect(screen.getAllByText(/Downstream decision is represented once/)).toHaveLength(1);
  });

  it('hides the optional transit stage and explains degraded coverage', () => {
    const degraded = payload({
      state: 'degraded', stateMessage: 'Transport evidence is unavailable.',
      coverage: { transport: { status: 'missing', columnVisible: false, explanation: 'Transport feed is unavailable; the tracker hides the transit column and does not infer a zero-minute segment.' } },
      data: payload().data.map((row) => ({ ...row, timeline: row.timeline.filter((item) => item.code !== 'in_transit') })),
    });
    renderTracker(degraded);
    expect(screen.queryByText('In transit')).not.toBeInTheDocument();
    expect(screen.getByText(/does not infer a zero-minute segment/)).toBeInTheDocument();
  });

  it('renders the server-owned empty state', () => {
    renderTracker(payload({ state: 'no_data', stateMessage: 'No Laboratory specimens match the selected filters.', data: [], meta: { perPage: 25, count: 0, hasMore: false, nextCursor: null, previousCursor: null } }));
    expect(screen.getAllByText('No Laboratory specimens match the selected filters.').length).toBeGreaterThan(0);
  });
});
