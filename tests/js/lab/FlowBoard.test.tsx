import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import FlowBoard from '@/Pages/Lab/FlowBoard';
import { labFlowBoardSchema, type LabFlowBoard } from '@/features/lab/schemas';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, preserveState: _preserveState, ...props }: any) => <a href={href} {...props}>{children}</a>,
}));
vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: any) => <main>{children}</main> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ title, subtitle, headerContent, children }: any) => <><h1>{title}</h1><p>{subtitle}</p>{headerContent}{children}</> }));
vi.mock('@/features/lab/hooks', () => ({ useLabFlowBoard: (data: LabFlowBoard) => ({ data, refetch: vi.fn() }) }));

function board(overrides: Partial<LabFlowBoard> = {}): LabFlowBoard {
  return labFlowBoardSchema.parse({
    generatedAt: '2026-07-11T14:00:00+00:00', sourceCutoffAt: '2026-07-11T13:58:00+00:00',
    state: 'degraded', stateMessage: 'Laboratory coverage is partial; coarse clocks remain visible without fabricated zero-duration segments.',
    freshness: { status: 'fresh', asOf: '2026-07-11T14:00:00+00:00', sourceCutoffAt: '2026-07-11T13:58:00+00:00', lagMinutes: 2, sourceLabel: 'Laboratory operational feeds', explanation: null },
    filters: { lens: 'all', priority: null, testFamily: null, unitId: null, shift: null },
    filterOptions: { lenses: ['all', 'ed', 'inpatient', 'discharge_gate', 'or_gate', 'degraded'], priorities: ['stat'], testFamilies: ['troponin'], units: [{ unitId: 1, label: 'ED' }], shifts: ['am_draw', 'day', 'evening', 'night'] },
    summary: { currentOrders: 13, openOrders: 8, statOrders: 3, statCompliant: 1, statCompliancePercent: 33.3, pendingDecisions: 3, openCriticalCallbacks: 1, degradedOrders: 2 },
    coverage: {
      transport: { status: 'missing', granularity: 'coarse', explanation: 'Transport feed is unavailable; collection-to-receipt remains visible as a coarse clock and transit duration is not reported as zero.' },
      middleware: { status: 'available', granularity: 'segmented', explanation: 'Analysis-start middleware evidence is available.' },
    },
    stageDistribution: [{ stage: 'LAB_PRELIM', label: 'Prelim', count: 3 }],
    tat: {
      collectToReceive: { count: 10, medianMinutes: 8, p90Minutes: 15, granularity: 'coarse', definition: 'Collection to receipt.' },
      receiveToResult: { count: 8, medianMinutes: 32, p90Minutes: 45, granularity: 'segmented', definition: 'Receipt to result.' },
    },
    criticalCallbacks: { total: 2, open: 1, oldestOpenAgeMinutes: 62, byState: [{ state: 'pending_notification', count: 1 }, { state: 'acknowledged', count: 1 }] },
    qualityStrip: [
      { key: 'rejection', label: 'Specimen rejection', count: 2, denominator: 13, ratePercent: 15.4, reference: { kind: 'benchmark', label: 'External benchmark not configured', valuePercent: null, source: 'Not configured.' } },
      { key: 'hemolysis', label: 'Hemolysis', count: 1, denominator: 13, ratePercent: 7.7, reference: { kind: 'benchmark', label: 'External benchmark not configured', valuePercent: null, source: 'Not configured.' } },
      { key: 'contamination', label: 'Contamination', count: 0, denominator: 13, ratePercent: 0, reference: { kind: 'local_policy', label: 'Site policy not configured', valuePercent: null, source: 'Not configured.' } },
    ],
    oldestItems: [{ orderUuid: '11111111-1111-4111-8111-111111111111', label: 'Troponin order', patientRef: 'demo-ed-patient', patientClass: 'emergency', priority: 'stat', testFamily: 'troponin', locationLabel: 'ED', currentStage: 'LAB_PRELIM', ageMinutes: 85, encounterLinked: true, decisionContext: { decision_class: 'ed_disposition', blocked_object_type: 'ed_visit', blocked_object_id: 7, explanation: 'ED disposition is blocked until the critical troponin is verified.' }, barrierCount: 0 }],
    barrierPareto: [], barrierReasons: [{ reasonCode: 'LAB_RECOLLECT_REQUIRED', category: 'medical', label: 'Specimen recollection required' }],
    definitions: [], canAnnotateBarriers: true, canViewPatientDetail: true,
    ...overrides,
  });
}

function renderBoard(value = board()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<FlowBoard flowBoard={value} />, { wrapper: ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider> });
}

describe('Laboratory Flow Board', () => {
  it('renders server-owned flow, clocks, downstream decision, and explicit benchmark labels', () => {
    renderBoard();
    expect(screen.getByRole('heading', { name: 'Laboratory Flow Board' })).toBeInTheDocument();
    expect(screen.getByText('33.3%')).toBeInTheDocument();
    expect(screen.getByText('Troponin order')).toBeInTheDocument();
    expect(screen.getByText(/ED disposition is blocked/i)).toBeInTheDocument();
    expect(screen.getAllByText(/External benchmark not configured/i)).toHaveLength(2);
    expect(screen.getByText(/local policy · Site policy not configured/i)).toBeInTheDocument();
    expect(screen.getByText(/not reported as zero/i)).toBeInTheDocument();
  });

  it('opens the audited governed Laboratory barrier drawer', () => {
    renderBoard();
    fireEvent.click(screen.getByRole('button', { name: 'Add barrier' }));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Annotate Laboratory barrier' })).toBeInTheDocument();
    expect(screen.getByLabelText('Reason')).toHaveValue('LAB_RECOLLECT_REQUIRED');
    expect(screen.getByText(/action is audited/i)).toBeInTheDocument();
  });

  it('renders the intentional empty contract without invented operational rows', () => {
    renderBoard(board({ state: 'no_data', stateMessage: 'No current Laboratory orders match the selected filters.', stageDistribution: [], oldestItems: [], summary: { currentOrders: 0, openOrders: 0, statOrders: 0, statCompliant: 0, statCompliancePercent: null, pendingDecisions: 0, openCriticalCallbacks: 0, degradedOrders: 0 } }));
    expect(screen.getByText('No current Laboratory orders match the selected filters.')).toBeInTheDocument();
    expect(screen.getByText('No stage cohorts match.')).toBeInTheDocument();
    expect(screen.getByText('No active items match the selected filters.')).toBeInTheDocument();
  });
});
