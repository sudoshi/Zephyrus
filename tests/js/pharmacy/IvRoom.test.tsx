import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import IvRoom from '@/Pages/Pharmacy/IvRoom';
import { pharmacyIvRoomSchema, type PharmacyIvRoom } from '@/features/pharmacy/iv-room-schemas';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, preserveState: _preserveState, ...props }: any) => <a href={href} {...props}>{children}</a>,
}));
vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: any) => <main>{children}</main> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ title, subtitle, headerContent, children }: any) => <><h1>{title}</h1><p>{subtitle}</p>{headerContent}{children}</> }));
vi.mock('@/features/pharmacy/hooks', () => ({ usePharmacyIvRoom: (data: PharmacyIvRoom) => ({ data, refetch: vi.fn() }) }));

const freshFeeds = { status: 'fresh', asOf: '2026-07-11T14:00:00+00:00', sourceCutoffAt: '2026-07-11T13:30:00+00:00', lagMinutes: 30, sourceLabel: 'Pharmacy IV workflow feeds', explanation: null } as const;

function prep(overrides: Record<string, unknown> = {}) {
  return {
    prepUuid: '11111111-1111-5111-9111-111111111111', label: 'Vancomycin intravenous infusion',
    patientRef: 'sim-hx-0009', patientClass: 'emergency', locationLabel: 'ED', prepType: 'iv_batch', prepTypeLabel: 'IV batch',
    batchRef: null, prepState: 'in_progress', prepStateLabel: 'In progress', elapsedMinutes: 140, elapsedIsMeasured: true,
    budExpiresAt: '2026-07-12T02:00:00+00:00', budMinutesRemaining: 720, budState: 'within_window',
    stages: [
      { code: 'started', label: 'Started', at: '2026-07-11T11:40:00+00:00', state: 'complete' },
      { code: 'completed', label: 'Compounded', at: null, state: 'pending' },
      { code: 'checked', label: 'Checked', at: null, state: 'pending' },
    ],
    ...overrides,
  };
}

function payload(overrides: Partial<PharmacyIvRoom> = {}): PharmacyIvRoom {
  return pharmacyIvRoomSchema.parse({
    generatedAt: '2026-07-11T14:00:00+00:00', sourceCutoffAt: '2026-07-11T13:30:00+00:00',
    freshnessStatus: 'fresh', degradedMode: true, state: 'degraded', stateMessage: 'IV workflow coverage is partial; degraded IV-room orders keep a coarse verify-to-dispense clock without fabricated preparation stages.',
    freshness: freshFeeds,
    filters: { prepType: null },
    filterOptions: { prepType: ['iv_batch', 'chemo', 'tpn', 'compound', 'repack', 'other'] },
    appliedSlaDefinitions: [],
    policy: {
      kind: 'configuration',
      tpnCutoff: { label: 'TPN daily production cutoff', localHour: 18, timezone: 'UTC', nextCutoffAt: '2026-07-11T18:00:00+00:00', description: 'Configured local production deadline for the daily TPN batch. This is a policy time, not an observed event.' },
      budWarningWindow: { label: 'BUD warning window', minutes: 120, description: 'A batch whose beyond-use date expires within this configured window of the current time is flagged expiring.' },
    },
    data: {
      summary: { totalPreps: 4, activePreps: 1, batches: 2, chemoPreps: 0, tpnPreps: 1, budExpiringSoon: 0, budExpired: 0, degradedOrders: 1 },
      batches: [
        { key: 'demo:2026-07-11:rx:iv-batch:am', batchRef: 'demo:2026-07-11:rx:iv-batch:am', batched: true, prepType: 'iv_batch', prepTypeLabel: 'IV batch', prepCount: 2, activeCount: 0, stateCounts: { checked: 2 }, earliestStartedAt: '2026-07-11T07:10:00+00:00', latestCompletedAt: '2026-07-11T07:45:00+00:00', budExpiresAt: '2026-07-12T08:00:00+00:00', budMinutesRemaining: 1080, budState: 'within_window', budCrossesDayBoundary: true },
        { key: 'demo:2026-07-11:rx:tpn-batch:01', batchRef: 'demo:2026-07-11:rx:tpn-batch:01', batched: true, prepType: 'tpn', prepTypeLabel: 'TPN admixture', prepCount: 1, activeCount: 0, stateCounts: { complete: 1 }, earliestStartedAt: '2026-07-11T09:50:00+00:00', latestCompletedAt: '2026-07-11T12:30:00+00:00', budExpiresAt: '2026-07-12T14:00:00+00:00', budMinutesRemaining: 1440, budState: 'within_window', budCrossesDayBoundary: true },
        { key: 'unbatched:iv_batch', batchRef: null, batched: false, prepType: 'iv_batch', prepTypeLabel: 'IV batch', prepCount: 1, activeCount: 1, stateCounts: { in_progress: 1 }, earliestStartedAt: '2026-07-11T11:40:00+00:00', latestCompletedAt: null, budExpiresAt: '2026-07-12T02:00:00+00:00', budMinutesRemaining: 720, budState: 'within_window', budCrossesDayBoundary: true },
      ],
      chemoTimeline: [],
      activeWork: [prep()],
      waste: { wasteEvents: 1, wasteQuantity: 0.5, denominatorLabel: 'ADC vend transactions in the same station-scope window', denominatorCount: 8, wastePerHundredVends: 12.5, windowHours: 24, windowStartAt: '2026-07-10T14:00:00+00:00', windowEndAt: '2026-07-11T14:00:00+00:00', basis: 'Waste is measured from automated dispensing cabinet waste-transaction facts; the rate is per hundred vend transactions in the same window and station scope, a unit/station aggregate with no user-level dimension.' },
      degradedOrders: {
        coverage: 'partial',
        orders: [
          { orderUuid: '22222222-2222-5222-9222-222222222222', label: 'Cyclophosphamide intravenous infusion', patientRef: 'sim-hx-0014', patientClass: 'inpatient', locationLabel: 'ICU', orderStatus: 'dispensed', verifiedAt: '2026-07-11T09:55:00+00:00', dispensedAt: '2026-07-11T11:00:00+00:00', coarseVerifyToDispenseMinutes: 65, clockResolution: 'coarse' },
        ],
        coverageStatement: 'IV workflow (IVWMS) preparation evidence is absent for these IV-room orders. Only a coarse verify-to-dispense interval is available; preparation stages, batch identity, and BUD windows are not reported for them, and their preparation duration is never shown as zero.',
      },
    },
    privacy: { directPatientIdentifiersIncluded: false, doseInstructionsIncluded: false, compoundingRecipeIncluded: false, individualPerformanceIncluded: false, identifierPolicy: 'Pseudonymous display references only.' },
    canViewPatientDetail: true,
    ...overrides,
  });
}

function renderPage(value = payload()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<IvRoom ivRoom={value} />, { wrapper: ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider> });
}

describe('IV Room and Batches', () => {
  it('renders batches, the policy block, waste denominator, and active work with icon+label status', () => {
    renderPage();
    expect(screen.getByRole('heading', { name: 'IV Room and Batches' })).toBeInTheDocument();

    // Policy is presented as configuration, visually distinct from measured timing.
    expect(screen.getByRole('heading', { name: /Policy and configuration/ })).toBeInTheDocument();
    expect(screen.getByText('TPN daily production cutoff')).toBeInTheDocument();

    // A batch reference and its next-day BUD are shown.
    expect(screen.getByText('demo:2026-07-11:rx:tpn-batch:01')).toBeInTheDocument();

    // BUD state is carried by icon + label, not color alone.
    expect(screen.getAllByText('Within BUD window').length).toBeGreaterThan(0);

    // Waste declares its denominator and 24h window explicitly.
    expect(screen.getByText('ADC vend transactions in the same station-scope window')).toBeInTheDocument();
    expect(screen.getByText(/Window: last 24 h/)).toBeInTheDocument();

    // The degraded IVWMS order surfaces its coarse-clock coverage statement.
    expect(screen.getByText(/never shown as zero/)).toBeInTheDocument();
    expect(screen.getByText('Coarse verify → dispense only')).toBeInTheDocument();
  });

  it('renders a null waste rate as an em dash, never a fabricated number', () => {
    const value = payload();
    renderPage(payload({
      data: { ...value.data, waste: { ...value.data.waste, wasteEvents: 0, wasteQuantity: 0, denominatorCount: 0, wastePerHundredVends: null } },
    }));
    // The waste rate value sits next to its label; a null rate is an em dash.
    const rateLabel = screen.getByText('Waste per 100 vends');
    const rateValue = rateLabel.parentElement?.querySelector('.tabular-nums');
    expect(rateValue?.textContent).toBe('—');
  });

  it('renders an unmeasured elapsed as "not measured" rather than zero', () => {
    const value = payload();
    renderPage(payload({
      data: {
        ...value.data,
        activeWork: [prep({ prepState: 'pending', prepStateLabel: 'Pending', elapsedMinutes: null, elapsedIsMeasured: false, stages: [
          { code: 'started', label: 'Started', at: null, state: 'pending' },
          { code: 'completed', label: 'Compounded', at: null, state: 'pending' },
          { code: 'checked', label: 'Checked', at: null, state: 'pending' },
        ] })],
      },
    }));
    expect(screen.getByText('not measured')).toBeInTheDocument();
  });

  it('shows full coverage and no degraded rows when IVWMS is available', () => {
    const value = payload();
    renderPage(payload({
      state: 'normal', degradedMode: false, stateMessage: 'IV-room preparation facts are current.',
      data: {
        ...value.data,
        summary: { ...value.data.summary, degradedOrders: 0 },
        degradedOrders: { coverage: 'available', orders: [], coverageStatement: 'IV workflow preparation evidence is available for every current IV-room order; batch preparation stages are fully covered.' },
      },
    }));
    expect(screen.getByText(/IV workflow preparation evidence is available for every current IV-room order/)).toBeInTheDocument();
    expect(screen.queryByText('Coarse verify → dispense only')).not.toBeInTheDocument();
  });
});
