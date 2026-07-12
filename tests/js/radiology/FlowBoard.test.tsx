import { fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import FlowBoard from '@/Pages/Radiology/FlowBoard';
import { radiologyFlowBoardSchema, type RadiologyFlowBoard } from '@/features/radiology/schemas';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, preserveState: _preserveState, ...props }: any) => <a href={href} {...props}>{children}</a>,
}));
vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: any) => <main>{children}</main> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ title, subtitle, headerContent, children }: any) => <><h1>{title}</h1><p>{subtitle}</p>{headerContent}{children}</> }));
vi.mock('@/features/radiology/hooks', () => ({ useRadiologyFlowBoard: (data: RadiologyFlowBoard) => ({ data, refetch: vi.fn() }) }));

function renderBoard(value: RadiologyFlowBoard) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<FlowBoard flowBoard={value} />, { wrapper: ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider> });
}

function board(overrides: Partial<RadiologyFlowBoard> = {}): RadiologyFlowBoard {
  return radiologyFlowBoardSchema.parse({
    generatedAt: '2026-07-11T14:00:00+00:00',
    sourceCutoffAt: '2026-07-11T13:55:00+00:00',
    state: 'degraded',
    stateMessage: 'Some Radiology orders lack optional modality or milestone evidence.',
    freshness: { status: 'fresh', asOf: '2026-07-11T14:00:00+00:00', sourceCutoffAt: '2026-07-11T13:55:00+00:00', lagMinutes: 5, sourceLabel: 'Radiology operational feeds', explanation: null },
    filters: { lens: 'all', priority: null, modality: null, unitId: null },
    filterOptions: { lenses: ['all', 'ed', 'inpatient', 'discharge', 'degraded'], priorities: ['routine'], modalities: [{ code: 'CT', label: 'Computed tomography' }], units: [{ unitId: 1, label: '5 East' }] },
    summary: { openOrders: 1, openBreaches: 1, dischargeBlocking: 1, degradedOrders: 1 },
    thresholds: { warningMinutes: 30, breachMinutes: 60, definitions: [] },
    heatmap: [{ key: 'ct-60', rowLabel: 'CT', columnLabel: '60–119 min', count: 1, state: 'breach' }],
    oldestItems: [{ orderId: 1, orderUuid: '11111111-1111-4111-8111-111111111111', label: 'Discharge-pending chest CT', patientRef: 'demo-patient', patientClass: 'inpatient', priority: 'routine', modality: 'CT', locationLabel: '5 East', currentState: 'final', currentMilestoneCode: 'RAD_FINAL', ageMinutes: 180, status: 'breach', barrierCount: 1, encounterLinked: true, sourceCutoffAt: '2026-07-11T13:55:00+00:00' }],
    worklistHref: '/radiology/worklist?lens=discharge',
    barrierPareto: [{ reasonCode: 'RAD_READ_QUEUE', label: 'Interpretation queue delay', count: 1 }],
    barrierReasons: [{ reasonCode: 'RAD_READ_QUEUE', category: 'medical', label: 'Interpretation queue delay' }],
    scanners: { total: 1, operational: 0, downtime: 1, items: [{ scannerUuid: '22222222-2222-4222-8222-222222222222', label: 'DEMO-CT-1', modality: 'CT', capacity: 1, state: 'downtime', reasonCode: 'UNPLANNED_SERVICE', downtimeEndsAt: '2026-07-11T14:30:00+00:00' }] },
    canAnnotateBarriers: true,
    canViewPatientDetail: true,
    ...overrides,
  });
}

describe('Radiology Flow Board', () => {
  it('renders server-derived summary, heatmap, bounded drill, scanner state, and freshness', () => {
    renderBoard(board());

    expect(screen.getByRole('heading', { name: 'Imaging Flow Board' })).toBeInTheDocument();
    expect(screen.getByText('Discharge-pending chest CT')).toBeInTheDocument();
    expect(screen.getByText('Interpretation queue delay')).toBeInTheDocument();
    expect(screen.getByText('DEMO-CT-1')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open worklist' })).toHaveAttribute('href', '/radiology/worklist?lens=discharge');
    expect(screen.getByText('Fresh')).toBeInTheDocument();
  });

  it('opens the policy-projected accessible barrier annotation drawer', () => {
    renderBoard(board());
    fireEvent.click(screen.getByRole('button', { name: 'Add barrier' }));

    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Annotate Radiology barrier' })).toBeInTheDocument();
    expect(screen.getByLabelText('Reason')).toHaveValue('RAD_READ_QUEUE');
    expect(screen.getByText(/action is audited/i)).toBeInTheDocument();
  });

  it('renders intentional no-data and source-error contracts without fabricated counts', () => {
    const noData = board({ state: 'no_data', stateMessage: 'No open Radiology orders match the selected lens.', heatmap: [], oldestItems: [], summary: { openOrders: 0, openBreaches: 0, dischargeBlocking: 0, degradedOrders: 0 } });
    const { rerender } = renderBoard(noData);
    expect(screen.getByText('No open Radiology orders match the selected lens.')).toBeInTheDocument();
    expect(screen.getByText('No aging cohorts available.')).toBeInTheDocument();

    rerender(<FlowBoard flowBoard={board({ state: 'source_error', stateMessage: 'Radiology source health reports an error. Last known operational facts remain visible.', heatmap: [{ key: 'ct-error', rowLabel: 'CT', columnLabel: '60–119 min', count: null, state: 'no_data' }] })} />);
    expect(screen.getByText(/source health reports an error/i)).toBeInTheDocument();
    expect(screen.getAllByText('—').length).toBeGreaterThan(0);
  });
});
