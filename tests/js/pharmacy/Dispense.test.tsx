import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import Dispense from '@/Pages/Pharmacy/Dispense';
import { pharmacyDispenseSchema, type PharmacyDispense } from '@/features/pharmacy/dispense-schemas';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, preserveState: _preserveState, ...props }: any) => <a href={href} {...props}>{children}</a>,
}));
vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: any) => <main>{children}</main> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ title, subtitle, headerContent, children }: any) => <><h1>{title}</h1><p>{subtitle}</p>{headerContent}{children}</> }));
vi.mock('@/features/pharmacy/hooks', () => ({ usePharmacyDispense: (data: PharmacyDispense) => ({ data, refetch: vi.fn() }) }));

const freshFeeds = { status: 'fresh', asOf: '2026-07-11T14:00:00+00:00', sourceCutoffAt: '2026-07-11T13:35:00+00:00', lagMinutes: 25, sourceLabel: 'Pharmacy dispensing feeds', explanation: null } as const;

function station(overrides: Record<string, unknown> = {}) {
  return {
    stationId: 1, label: 'Demo ADC — ED Bay', stationType: 'emergency', unitName: 'Emergency Dept',
    vends: 3, overrides: 1, stockouts: 1, controlledVends: 0, hasDenominator: true, denominatorCount: 3,
    overrideRatePercent: 33.3, stockoutRatePercent: 33.3, overrideStatus: 'over_target', stockoutStatus: 'over_target',
    hasActiveStockout: true, transactionCounts: { vend: 3, override: 1, stockout: 1 },
    ...overrides,
  };
}

function payload(overrides: Partial<PharmacyDispense> = {}): PharmacyDispense {
  return pharmacyDispenseSchema.parse({
    generatedAt: '2026-07-11T14:00:00+00:00', sourceCutoffAt: '2026-07-11T13:35:00+00:00',
    freshnessStatus: 'fresh', degradedMode: false, state: 'normal', stateMessage: 'Dispense and delivery facts are current.',
    freshness: freshFeeds,
    filters: { stationType: null },
    filterOptions: { stationType: ['emergency', 'general'] },
    appliedSlaDefinitions: [],
    policy: {
      kind: 'local_policy',
      overrideTargetRate: { label: 'Override rate target', ratePercent: 5, denominatorLabel: 'ADC vend transactions in the window', description: 'Locally configured maximum override rate. Policy reference line, not a measured value.' },
      stockoutTargetRate: { label: 'Stockout rate target', ratePercent: 2, denominatorLabel: 'ADC vend transactions in the window', description: 'Locally configured maximum stockout rate. Policy reference line, not an observed event.' },
    },
    window: { hours: 24, startAt: '2026-07-10T14:00:00+00:00', endAt: '2026-07-11T14:00:00+00:00' },
    data: {
      summary: { stationsReporting: 3, stationsWithDenominator: 2, stationsWithoutDenominator: 1, totalVends: 6, totalOverrides: 1, totalStockouts: 1, overrideRatePercent: 16.7, stockoutRatePercent: 16.7, stationsOverOverrideTarget: 2, stationsWithActiveStockout: 1 },
      stations: [
        station(),
        station({ stationId: 2, label: 'Demo ADC — Med/Surg', stationType: 'general', unitName: 'Med/Surg', vends: 3, overrides: 0, stockouts: 0, hasActiveStockout: false, overrideRatePercent: 0, stockoutRatePercent: 0, overrideStatus: 'within_target', stockoutStatus: 'within_target', transactionCounts: { vend: 3 } }),
        station({ stationId: 3, label: 'Demo ADC — ICU', stationType: 'general', unitName: 'ICU', vends: 0, overrides: 0, stockouts: 0, hasDenominator: false, denominatorCount: 0, overrideRatePercent: null, stockoutRatePercent: null, overrideStatus: 'no_data', stockoutStatus: 'no_data', hasActiveStockout: false, transactionCounts: { refill: 1 } }),
      ],
      units: [
        { unitId: 10, unitName: 'Emergency Dept', vends: 3, overrides: 1, stockouts: 1, hasDenominator: true, denominatorCount: 3, overrideRatePercent: 33.3, stockoutRatePercent: 33.3, overrideStatus: 'over_target', stockoutStatus: 'over_target' },
      ],
      shortages: {
        count: 1,
        orders: [
          { orderUuid: '22222222-2222-5222-9222-222222222222', medicationLabel: 'Ceftriaxone 1 g intravenous', patientRef: 'sim-hx-0011', orderStatus: 'verified', locationLabel: 'Med/Surg', reasonCode: 'RX_STOCKOUT', stationKey: 'demo:rx:station:ED-01', notedAt: '2026-07-11T11:50:00+00:00' },
        ],
        basis: 'Orders flagged on shortage in the current operational window.',
      },
      vendToRefill: {
        measurableStations: 1,
        stations: [{ stationId: 2, label: 'Demo ADC — Med/Surg', pairCount: 1, medianMinutes: 190, maxMinutes: 190 }],
        basis: 'For each refill, the interval from the most recent prior vend at the same station. A refill with no preceding vend in the window is not measurable and is excluded, never shown as zero.',
      },
      missingDose: {
        chainCount: 1,
        chains: [{ orderUuid: '33333333-3333-5333-9333-333333333333', medicationLabel: 'Ondansetron injection', patientRef: 'sim-hx-0010', missingDoseAt: '2026-07-11T12:30:00+00:00', reDispenseChannel: 'central' }],
        basis: 'Orders with a missing-dose event followed by a later re-dispense in the milestone ledger.',
      },
      delivery: {
        coverage: 'absent', dispenses: 8, delivered: 0, returned: 1, medianMinutes: null, p90Minutes: null,
        coverageStatement: 'Delivery tracking is not available for the current dispense cohort. Dispense evidence is complete, but no dispense-to-delivery interval is reported — it is never shown as zero when delivery times are absent.',
      },
    },
    privacy: { directPatientIdentifiersIncluded: false, individualPerformanceIncluded: false, diversionScoringIncluded: false, userLevelDimensionIncluded: false, identifierPolicy: 'Station and unit aggregates only.' },
    canViewPatientDetail: true,
    ...overrides,
  });
}

function renderPage(value = payload()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<Dispense dispense={value} />, { wrapper: ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider> });
}

describe('Dispense and Delivery', () => {
  it('renders station rates, policy targets, missing-dose chains, and delivery coverage with icon+label status', () => {
    renderPage();
    expect(screen.getByRole('heading', { name: 'Dispense and Delivery' })).toBeInTheDocument();

    // The ED station's observed override rate is shown, over target by icon + label.
    expect(screen.getByText('Demo ADC — ED Bay')).toBeInTheDocument();
    expect(screen.getAllByText('33.3%').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Override Over target').length).toBeGreaterThan(0);
    expect(screen.getByText('Active stockout')).toBeInTheDocument();

    // Local policy is presented as configuration, distinct from the measured rates.
    expect(screen.getByRole('heading', { name: /Local policy targets/ })).toBeInTheDocument();
    expect(screen.getByText('Override rate target')).toBeInTheDocument();

    // Missing-dose chain and its re-dispense channel are surfaced.
    expect(screen.getByText(/re-dispensed via central/)).toBeInTheDocument();

    // Shortage context surfaces the flagged order and its station linkage.
    expect(screen.getByText('Ceftriaxone 1 g intravenous')).toBeInTheDocument();

    // Delivery tracking is absent: coverage statement disclaims a zero interval.
    expect(screen.getByText('Delivery tracking absent')).toBeInTheDocument();
    expect(screen.getByText(/no dispense-to-delivery interval is reported/)).toBeInTheDocument();
    expect(screen.getByText('not tracked')).toBeInTheDocument();
  });

  it('renders a station with no vend denominator as "no data", never 0%', () => {
    renderPage();
    const icuHeading = screen.getByText('Demo ADC — ICU');
    const icuCard = icuHeading.closest('article');
    expect(icuCard).not.toBeNull();
    const card = within(icuCard as HTMLElement);
    // The override/stockout rate values are "no data" — a null rate is NEVER 0%.
    expect(card.getAllByText('no data').length).toBeGreaterThanOrEqual(2);
    expect(card.queryByText('0%')).not.toBeInTheDocument();
    // And the no-denominator caption is present with an icon.
    expect(card.getByText(/No vends in the window/)).toBeInTheDocument();
  });

  it('measures the delivery interval when delivery tracking is available', () => {
    const value = payload();
    renderPage(payload({
      data: {
        ...value.data,
        delivery: { coverage: 'available', dispenses: 8, delivered: 5, returned: 1, medianMinutes: 22, p90Minutes: 40, coverageStatement: 'Delivery timestamps are recorded for dispensed medications; the dispense-to-delivery interval is measured over those with a delivery time.' },
      },
    }));
    expect(screen.getByText('Delivery tracking available')).toBeInTheDocument();
    expect(screen.getByText('22 min')).toBeInTheDocument();
    expect(screen.queryByText('not tracked')).not.toBeInTheDocument();
  });

  it('renders a distinct no-data empty state when no station reported transactions', () => {
    const value = payload();
    renderPage(payload({
      state: 'no_data', stateMessage: 'No automated dispensing cabinet transactions are in the current operational window.',
      data: {
        ...value.data,
        summary: { ...value.data.summary, stationsReporting: 0, totalVends: 0, totalOverrides: 0, overrideRatePercent: null, stockoutRatePercent: null, stationsOverOverrideTarget: 0, stationsWithActiveStockout: 0, stationsWithDenominator: 0, stationsWithoutDenominator: 0 },
        stations: [],
        units: [],
      },
    }));
    expect(screen.getByText(/No automated dispensing cabinet transactions/)).toBeInTheDocument();
    expect(screen.getByText('No stations reported transactions in the current window.')).toBeInTheDocument();
  });
});
