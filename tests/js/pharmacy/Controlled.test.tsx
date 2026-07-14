import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import Controlled from '@/Pages/Pharmacy/Controlled';
import { pharmacyControlledSchema, type PharmacyControlled } from '@/features/pharmacy/controlled-schemas';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
}));
vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: any) => <main>{children}</main> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ title, subtitle, headerContent, children }: any) => <><h1>{title}</h1><p>{subtitle}</p>{headerContent}{children}</> }));
vi.mock('@/features/pharmacy/hooks', () => ({ usePharmacyControlled: (data: PharmacyControlled) => ({ data, refetch: vi.fn() }) }));

const freshFeeds = { status: 'fresh', asOf: '2026-07-11T14:00:00+00:00', sourceCutoffAt: '2026-07-11T13:35:00+00:00', lagMinutes: 25, sourceLabel: 'Pharmacy dispensing feeds', explanation: null } as const;

function station(overrides: Record<string, unknown> = {}) {
  return {
    stationId: 1, label: 'Demo ADC — ED Bay', stationType: 'emergency', unitName: 'Emergency Dept',
    controlledVends: 4, controlledOverrides: 1, controlledDiscrepancies: 1, controlledWaste: 0,
    hasDenominator: true, denominatorCount: 4, overrideRatePercent: 25, overrideStatus: 'over_target',
    openDiscrepancies: 1, openDiscrepanciesPastPolicy: 1, oldestOpenDiscrepancyAt: '2026-07-11T12:25:00+00:00',
    transactionCounts: { vend: 4, override: 1, discrepancy_open: 1 },
    ...overrides,
  };
}

function payload(overrides: Partial<PharmacyControlled> = {}): PharmacyControlled {
  return pharmacyControlledSchema.parse({
    generatedAt: '2026-07-11T14:00:00+00:00', sourceCutoffAt: '2026-07-11T13:35:00+00:00',
    freshnessStatus: 'fresh', degradedMode: false, state: 'normal', stateMessage: 'Controlled substance reconciliation facts are current.',
    freshness: freshFeeds,
    appliedSlaDefinitions: [],
    policy: {
      kind: 'local_policy',
      shiftEnd: { label: 'Shift-end reconciliation policy', timezone: 'America/New_York', times: ['07:00', '19:00'], graceMinutes: 0, description: 'Controlled discrepancies are expected to be reconciled by shift-end. Policy reference, not a measured event.' },
      overrideTargetRate: { label: 'Controlled override rate target', ratePercent: 5, denominatorLabel: 'Controlled ADC vend transactions in the window', description: 'Locally configured maximum controlled override rate. Configuration, not an observed event.' },
    },
    window: { hours: 24, startAt: '2026-07-10T14:00:00+00:00', endAt: '2026-07-11T14:00:00+00:00' },
    data: {
      summary: { openDiscrepancyCount: 1, openDiscrepanciesPastPolicy: 1, oldestOpenMinutes: 95, stationsWithOpenDiscrepancy: 1, stationsOverOverrideTarget: 1, totalControlledVends: 4, totalControlledOverrides: 1 },
      openDiscrepancies: {
        count: 1,
        items: [
          { discrepancyKey: 'demo:rx:disc:02', stationId: 1, unitId: 10, medicationLabel: 'Morphine injection', openedAt: '2026-07-11T12:25:00+00:00', applicableShiftEndAt: '2026-07-11T11:00:00+00:00', minutesOpen: 95, minutesPastShiftEnd: 180, agingStatus: 'past_policy' },
        ],
        basis: 'Controlled discrepancies opened without a matching resolve on the same discrepancy key.',
      },
      stationPatterns: {
        stations: [
          station(),
          station({ stationId: 2, label: 'Demo ADC — ICU', stationType: 'general', unitName: 'ICU', controlledVends: 0, controlledOverrides: 0, controlledDiscrepancies: 0, hasDenominator: false, denominatorCount: 0, overrideRatePercent: null, overrideStatus: 'no_data', openDiscrepancies: 0, openDiscrepanciesPastPolicy: 0, oldestOpenDiscrepancyAt: null, transactionCounts: {} }),
        ],
        basis: 'Controlled override and discrepancy patterns by station over the pattern window.',
      },
      unitPatterns: {
        units: [
          { unitId: 10, unitName: 'Emergency Dept', controlledVends: 4, controlledOverrides: 1, controlledDiscrepancies: 1, hasDenominator: true, denominatorCount: 4, overrideRatePercent: 25, overrideStatus: 'over_target', openDiscrepancies: 1, openDiscrepanciesPastPolicy: 1 },
        ],
        basis: 'The same controlled override and discrepancy patterns aggregated to the unit dimension.',
      },
    },
    scope: {
      diversionInvestigationInScope: false, individualScoringInScope: false, individualPerformanceIncluded: false, userLevelDimensionIncluded: false,
      aggregationLevel: 'unit_and_station',
      statement: 'This is an operational reconciliation view. Diversion investigation and individual scoring are out of scope: it reports open controlled-discrepancy reconciliation and override/discrepancy patterns by unit and station only.',
      exportEnabled: false, exportStatement: 'Aggregate export is deferred and not enabled.', tone: 'operational_non_accusatory',
    },
    ...overrides,
  });
}

function renderPage(value = payload()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<Controlled controlled={value} />, { wrapper: ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider> });
}

describe('Controlled Substances', () => {
  it('renders the out-of-scope statement, open discrepancy aging, and station patterns', () => {
    renderPage();
    expect(screen.getByRole('heading', { name: 'Controlled Substances' })).toBeInTheDocument();

    // The out-of-scope statement is present and prominent.
    expect(screen.getByRole('heading', { name: /Operational reconciliation view/ })).toBeInTheDocument();
    expect(screen.getByText(/Diversion investigation and individual scoring are out of scope/)).toBeInTheDocument();
    expect(screen.getByText(/Aggregate export is deferred/)).toBeInTheDocument();

    // The open discrepancy is aged past policy — by icon + label, not color alone.
    expect(screen.getByText('Morphine injection')).toBeInTheDocument();
    expect(screen.getAllByText('Past reconciliation policy').length).toBeGreaterThan(0);

    // The station override rate is over target by icon + label.
    expect(screen.getByText('Demo ADC — ED Bay')).toBeInTheDocument();
    expect(screen.getAllByText('25%').length).toBeGreaterThan(0);
    expect(screen.getByText('Override Over target')).toBeInTheDocument();

    // The shift-end policy is presented as configuration.
    expect(screen.getByText(/Shift-end reconciliation policy/)).toBeInTheDocument();
  });

  it('renders a station with no controlled-vend denominator as "no data", never 0%', () => {
    renderPage();
    const icuHeading = screen.getByText('Demo ADC — ICU');
    const card = within(icuHeading.closest('article') as HTMLElement);
    expect(card.getByText('no data')).toBeInTheDocument();
    expect(card.queryByText('0%')).not.toBeInTheDocument();
    expect(card.getByText(/No controlled vends in the window/)).toBeInTheDocument();
  });

  it('renders a distinct empty state when no controlled discrepancy is open', () => {
    const value = payload();
    renderPage(payload({
      data: {
        ...value.data,
        summary: { ...value.data.summary, openDiscrepancyCount: 0, openDiscrepanciesPastPolicy: 0, oldestOpenMinutes: null, stationsWithOpenDiscrepancy: 0 },
        openDiscrepancies: { count: 0, items: [], basis: value.data.openDiscrepancies.basis },
      },
    }));
    expect(screen.getByText(/No controlled discrepancies are open/)).toBeInTheDocument();
  });
});
