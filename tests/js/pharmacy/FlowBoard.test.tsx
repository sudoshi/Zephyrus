import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import FlowBoard from '@/Pages/Pharmacy/FlowBoard';
import { pharmacyFlowBoardSchema, type PharmacyFlowBoard } from '@/features/pharmacy/schemas';
import { queueForecastFixture } from './forecastFixtures';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, preserveState: _preserveState, ...props }: any) => <a href={href} {...props}>{children}</a>,
}));
vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: any) => <main>{children}</main> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ title, subtitle, headerContent, children }: any) => <><h1>{title}</h1><p>{subtitle}</p>{headerContent}{children}</> }));
vi.mock('@/features/pharmacy/hooks', () => ({ usePharmacyFlowBoard: (data: PharmacyFlowBoard) => ({ data, refetch: vi.fn() }) }));

const freshFeeds = { status: 'fresh', asOf: '2026-07-11T14:00:00+00:00', sourceCutoffAt: '2026-07-11T14:00:00+00:00', lagMinutes: 0, sourceLabel: 'Pharmacy operational feeds', explanation: null } as const;
const batchTail = { status: 'batch', asOf: '2026-07-11T14:00:00+00:00', sourceCutoffAt: '2026-07-11T09:00:00+00:00', lagMinutes: 300, sourceLabel: 'MAR warehouse', explanation: 'Administration facts are warehouse-derived and current only as of the batch cutoff; they are never real-time.' } as const;

function definition(metricKey: string, stop: string) {
  return {
    definitionUuid: '22222222-2222-4222-8222-222222222222', department: 'rx' as const, metricKey,
    label: metricKey, startMilestoneCode: 'RX_ORDERED', stopMilestoneCode: stop, priority: null, patientClass: null,
    scope: {}, statistic: 'item_clock' as const, warningMinutes: 150, breachMinutes: 180, targetValue: null,
    direction: 'lower_is_better' as const, unit: 'minutes', effectiveFrom: '2026-07-01T00:00:00+00:00', effectiveTo: null,
    version: 1, active: true, definitionText: 'Governed demo clock definition.', sourceReferenceId: null,
  };
}

function board(overrides: Partial<PharmacyFlowBoard> = {}): PharmacyFlowBoard {
  return pharmacyFlowBoardSchema.parse({
    generatedAt: '2026-07-11T14:00:00+00:00', sourceCutoffAt: '2026-07-11T14:00:00+00:00',
    freshnessStatus: 'fresh', degradedMode: true, state: 'degraded',
    stateMessage: 'Pharmacy coverage is partial; coarse clocks and cutoff-qualified administration facts remain visible without fabricated segments.',
    freshness: freshFeeds, administrationFreshness: batchTail,
    filters: { lens: 'all', clockClass: null, branch: null, status: null, unitId: null, source: null, forecast: false },
    filterOptions: { lenses: ['all', 'stat', 'first_dose', 'sepsis', 'shortage', 'discharge', 'degraded'], clockClasses: ['stat', 'sepsis'], branches: ['adc', 'iv_room', 'central'], statuses: ['queued', 'verified'], units: [{ unitId: 1, label: 'ED' }] },
    appliedSlaDefinitions: [],
    planningForecast: { requested: false, enabled: false, queue: null, explanation: 'Planning forecasts are off by default.' },
    data: {
      summary: { currentOrders: 24, openOrders: 21, statOrders: 3, statCompliant: 1, statCompliancePercent: 33.3, verificationQueueDepth: 3, openBreaches: 2, shortageOrders: 1, dischargeOrders: 2, controlledOrders: 1, degradedOrders: 1 },
      verificationQueue: {
        depth: 3, oldestAgeMinutes: 237, medianAgeMinutes: 224,
        ageDistribution: [
          { key: 'under_15', label: '< 15 min', count: 0 }, { key: '15_to_30', label: '15–30 min', count: 0 },
          { key: '30_to_60', label: '30–60 min', count: 0 }, { key: '60_plus', label: '60+ min', count: 3 },
        ],
      },
      clockClasses: [
        { clockClass: 'sepsis', metricKey: 'rx.sepsis_abx', label: 'Sepsis antibiotic order to administration', definition: definition('rx.sepsis_abx', 'RX_ADMINISTERED'), openOrders: 2, openBreaches: 1, openWarnings: 1, clearedBreaches: 1, oldestOpenBreachAgeMinutes: 10, adminTail: true, state: 'breach', explanation: 'This clock stops on warehouse-observed administration evidence and is qualified by the batch cutoff; it is never real-time.' },
      ],
      segments: {
        orderToDispense: { count: 11, medianMinutes: 40, p90Minutes: 105, basis: 'real_time', definition: 'Selected RX_ORDERED to RX_DISPENSED assertions across the filtered cohort; sourced from real-time operational feeds.', freshness: freshFeeds },
        dispenseToAdmin: { count: 3, medianMinutes: 25, p90Minutes: 149, basis: 'as_of_cutoff', sourceCutoffAt: '2026-07-11T09:00:00+00:00', definition: 'Selected RX_DISPENSED assertions to warehouse-observed administration evidence; cutoff-qualified and never real-time.', freshness: batchTail },
      },
      preparationBranches: {
        branches: [
          { branch: 'adc', label: 'ADC cabinet', orders: 17, openOrders: 16, degradedOrders: 0 },
          { branch: 'iv_room', label: 'IV room', orders: 5, openOrders: 3, degradedOrders: 1 },
          { branch: 'central', label: 'Central pharmacy', orders: 2, openOrders: 2, degradedOrders: 0 },
          { branch: 'unknown', label: 'Unassigned branch', orders: 0, openOrders: 0, degradedOrders: 0 },
        ],
        ivwms: { status: 'partial', degradedOrders: 1, explanation: 'IV workflow evidence is missing for some IV-room orders; their verify-to-dispense interior remains a coarse clock and preparation duration is not reported as zero.' },
      },
      sepsisTimers: [{
        orderUuid: '11111111-1111-4111-8111-111111111111', label: 'Ceftriaxone 1 g intravenous', patientRef: 'demo-ed-patient',
        patientClass: 'emergency', locationLabel: 'ED', orderedAt: '2026-07-11T10:50:00+00:00', elapsedMinutes: 190,
        metricKey: 'rx.sepsis_abx', state: 'breached',
        stateExplanation: 'The governed sepsis clock recorded a breach; no administration evidence had stopped the clock at the breach threshold.',
        segments: [
          { code: 'RX_ORDERED', label: 'Ordered', at: '2026-07-11T10:50:00+00:00', state: 'complete' },
          { code: 'RX_VERIFIED', label: 'Verified', at: '2026-07-11T10:57:00+00:00', state: 'complete' },
          { code: 'RX_DISPENSED', label: 'Dispensed', at: '2026-07-11T11:05:00+00:00', state: 'complete' },
          { code: 'RX_DELIVERED', label: 'Delivered', at: null, state: 'pending' },
        ],
        adminSegment: { state: 'no_evidence_as_of_cutoff', administeredAt: null, sourceCutoffAt: '2026-07-11T09:00:00+00:00', elapsedMinutes: null, explanation: 'No administration evidence as of the warehouse cutoff; absence within the batch window is not a failure claim.' },
      }],
      oldestItems: [{
        orderUuid: '33333333-3333-4333-8333-333333333333', label: 'Ondansetron injection', patientRef: 'demo-inpatient',
        patientClass: 'inpatient', clockClass: 'stat', preparationBranch: 'adc', orderStatus: 'verified', locationLabel: 'Med/Surg',
        currentStage: 'RX_VERIFIED', ageMinutes: 25, onShortage: false, isControlled: false, encounterLinked: true,
        slaState: 'breach', slaExplanation: 'An open governed SLA breach is recorded for this order.', barrierCount: 0,
      }],
      barrierPareto: [],
    },
    barrierReasons: [{ reasonCode: 'RX_VERIFICATION_DELAY', category: 'medical', label: 'Pharmacist verification delayed' }],
    privacy: { directPatientIdentifiersIncluded: false, doseInstructionsIncluded: false, individualPerformanceIncluded: false, identifierPolicy: 'Pseudonymous display references only.' },
    canAnnotateBarriers: true, canViewPatientDetail: true,
    ...overrides,
  });
}

function renderBoard(value = board()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<FlowBoard flowBoard={value} />, { wrapper: ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider> });
}

describe('Medication Flow Board', () => {
  it('renders server-owned queue depth, clocks, branches, and the warehouse-qualified admin tail', () => {
    renderBoard();
    expect(screen.getByRole('heading', { name: 'Medication Flow Board' })).toBeInTheDocument();
    expect(screen.getByText('33.3%')).toBeInTheDocument();
    expect(screen.getByText('237 min')).toBeInTheDocument();
    expect(screen.getByText(/Order → dispense/)).toBeInTheDocument();
    expect(screen.getByText(/Dispense → administration/)).toBeInTheDocument();
    // The admin tail is labeled warehouse as-of and never renders as real-time.
    expect(screen.getAllByText('Warehouse as-of').length).toBeGreaterThanOrEqual(2);
    expect(screen.getByText(/No administration evidence as of the warehouse cutoff/)).toBeInTheDocument();
    expect(screen.getByText(/not reported as zero/)).toBeInTheDocument();
    expect(screen.getByText('Ceftriaxone 1 g intravenous')).toBeInTheDocument();
    // Status is carried by icon + label, and the breach class comes from the server.
    expect(screen.getAllByText('Breached').length).toBeGreaterThanOrEqual(2);
  });

  it('refuses to parse an admin tail that claims real-time freshness', () => {
    const value = board();
    expect(() => pharmacyFlowBoardSchema.parse({
      ...value,
      data: { ...value.data, segments: { ...value.data.segments, dispenseToAdmin: { ...value.data.segments.dispenseToAdmin, freshness: freshFeeds } } },
    })).toThrowError(/never render as real-time/);
  });

  it('renders a stale warehouse tail as unknown/as-of, never success or failure', () => {
    const staleTail = { ...batchTail, status: 'stale' as const, explanation: 'The latest administration cutoff exceeds the warehouse cadence tolerance; administration-dependent metrics are cutoff-qualified and cannot claim compliance.' };
    const value = board();
    renderBoard(board({
      administrationFreshness: staleTail,
      data: {
        ...value.data,
        clockClasses: [{ ...value.data.clockClasses[0], openWarnings: null, state: 'unknown', explanation: 'The warehouse administration tail is not current; open warnings are unknown and neither compliance nor breach can be newly asserted for this clock. Recorded breaches remain visible as historical facts.' }],
        segments: { ...value.data.segments, dispenseToAdmin: { ...value.data.segments.dispenseToAdmin, freshness: staleTail } },
        sepsisTimers: [{
          ...value.data.sepsisTimers[0], state: 'unknown',
          stateExplanation: 'The warehouse administration tail is not current; this clock can claim neither compliance nor breach.',
          adminSegment: { state: 'unknown', administeredAt: null, sourceCutoffAt: '2026-07-11T09:00:00+00:00', elapsedMinutes: null, explanation: 'Administration evidence is not current; whether this dose has been administered cannot be asserted — this is neither a success nor a failure claim.' },
        }],
      },
    }));
    expect(screen.getAllByText('Unknown · as-of').length).toBeGreaterThanOrEqual(2);
    expect(screen.getByText(/neither a success nor a failure claim/)).toBeInTheDocument();
    expect(screen.getByText('Administration unknown')).toBeInTheDocument();
    expect(screen.queryByText('Administered as of cutoff')).not.toBeInTheDocument();
  });

  it('opens the audited governed Pharmacy barrier drawer', () => {
    renderBoard();
    fireEvent.click(screen.getByRole('button', { name: 'Add barrier' }));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Annotate Pharmacy barrier' })).toBeInTheDocument();
    expect(screen.getByLabelText('Reason')).toHaveValue('RX_VERIFICATION_DELAY');
    expect(screen.getByText(/action is audited/i)).toBeInTheDocument();
  });

  it('preserves allowlisted drill provenance across lenses and filter submission', () => {
    const value = board();
    renderBoard(board({ filters: { ...value.filters, lens: 'sepsis', unitId: 1, source: 'cockpit' } }));

    expect(screen.getByRole('link', { name: 'all' })).toHaveAttribute('href', '/pharmacy?unitId=1&source=cockpit');
    expect(screen.getByRole('link', { name: 'sepsis' })).toHaveAttribute('href', '/pharmacy?lens=sepsis&unitId=1&source=cockpit');
    expect(document.querySelector('input[name="source"]')).toHaveValue('cockpit');
  });

  it('keeps the synthetic planning forecast off by default and renders it only after opt-in', () => {
    const hidden = renderBoard();
    expect(screen.getByRole('link', { name: 'Show planning forecast' })).toHaveAttribute('href', '/pharmacy?forecast=1');
    expect(screen.queryByRole('heading', { name: /Synthetic planning forecast · verification queue/ })).not.toBeInTheDocument();
    hidden.unmount();

    const value = board();
    renderBoard(board({
      filters: { ...value.filters, forecast: true },
      planningForecast: { requested: true, enabled: true, queue: queueForecastFixture(), explanation: 'Synthetic planning forecast requested.' },
    }));
    expect(screen.getByRole('heading', { name: /Synthetic planning forecast · verification queue/ })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Hide planning forecast' })).toHaveAttribute('href', '/pharmacy');
    expect(screen.getByText(/Both are lower than the hour-of-week and last-value baselines: yes/)).toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Synthetic hourly verification queue-depth projection' })).toBeInTheDocument();
  });

  it('renders the intentional empty contract without invented operational rows', () => {
    const value = board();
    renderBoard(board({
      state: 'no_data', stateMessage: 'No current Pharmacy orders match the selected filters.', degradedMode: false,
      data: {
        ...value.data,
        summary: { ...value.data.summary, currentOrders: 0, openOrders: 0, verificationQueueDepth: 0 },
        verificationQueue: { depth: 0, oldestAgeMinutes: null, medianAgeMinutes: null, ageDistribution: value.data.verificationQueue.ageDistribution.map((bucket) => ({ ...bucket, count: 0 })) },
        sepsisTimers: [], oldestItems: [],
      },
    }));
    expect(screen.getByText('No current Pharmacy orders match the selected filters.')).toBeInTheDocument();
    expect(screen.getByText('No sepsis antibiotic orders match the selected filters.')).toBeInTheDocument();
    expect(screen.getByText('No active items match the selected filters.')).toBeInTheDocument();
  });
});
