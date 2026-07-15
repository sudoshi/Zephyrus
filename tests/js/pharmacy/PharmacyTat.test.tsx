import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import PharmacyTatPage from '@/Pages/Analytics/PharmacyTat';
import { pharmacyTatSchema, type PharmacyTat } from '@/features/pharmacy/tat-schemas';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, ...props }: any) => <a href={href} {...props}>{children}</a>,
}));
vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: any) => <main>{children}</main> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ title, subtitle, headerContent, children }: any) => <><h1>{title}</h1><p>{subtitle}</p>{headerContent}{children}</> }));
vi.mock('@/Components/Ancillary', () => ({ SourceFreshnessBadge: ({ value }: any) => <span>{value.status === 'batch' ? 'Warehouse as-of source' : 'Fresh Pharmacy source'}</span> }));
vi.mock('@/features/pharmacy/hooks', () => ({ usePharmacyTat: (data: PharmacyTat) => ({ data }) }));
vi.mock('recharts', () => ({
  ResponsiveContainer: ({ children }: any) => <div>{children}</div>,
  BarChart: ({ children }: any) => <div>{children}</div>, LineChart: ({ children }: any) => <div>{children}</div>,
  Bar: ({ name }: any) => <span>{name}</span>, Line: ({ name }: any) => <span>{name}</span>,
  CartesianGrid: () => null, Legend: () => null, Tooltip: () => null, XAxis: () => null, YAxis: () => null,
}));

const at = '2026-07-13T14:00:00+00:00';
const adminCutoff = '2026-07-13T09:00:00+00:00';

function definition(metricKey: string, start: string, stop: string, scope: Record<string, unknown> = {}) {
  const suffix = metricKey.replace(/[^0-9]/g, '').padStart(12, '0').slice(-12) || '000000000000';
  return {
    definitionUuid: `11111111-1111-4111-8111-${suffix}`, department: 'rx', metricKey, label: metricKey.replaceAll('.', ' '),
    startMilestoneCode: start, stopMilestoneCode: stop, priority: null, patientClass: null, scope,
    statistic: 'median' as const, warningMinutes: null, breachMinutes: null, targetValue: null,
    direction: 'lower_is_better' as const, unit: 'minutes', effectiveFrom: '2026-01-01T00:00:00+00:00', effectiveTo: null,
    version: 1, active: false, definitionText: `${start} to ${stop} for the bounded cohort.`, sourceReferenceId: 'study_clock_no_numeric_benchmark',
  };
}

const primary = definition('rx.study.order_admin', 'RX_ORDERED', 'RX_ADMINISTERED', { study_segment: true, phase: 'end_to_end', sequence: 50, basis: 'warehouse_as_of' });
const distribution = { count: 3, medianMinutes: 80, p90Minutes: 176, meanMinutes: 115 };
const point = { key: 'first_dose', label: 'First Dose', ...distribution };
const wChart = { clockDefinition: primary, cohortCount: 3, sourceCutoffAt: adminCutoff, benchmarkSourceLabel: 'Reference clock only; no governed numeric benchmark' };
const realtimeAssertion = {
  milestoneUuid: '55555555-5555-4555-8555-555555555555', code: 'RX_ORDERED', basis: 'real_time' as const, occurredAt: '2026-07-13T08:00:00+00:00',
  receivedAt: '2026-07-13T08:01:00+00:00', sourceKey: 'demo.pharmacy', sourceRank: 10, assertionCount: 1,
};
const warehouseAssertion = {
  milestoneUuid: '00000000-0000-0000-0000-000000000000', code: 'RX_ADMINISTERED', basis: 'warehouse_as_of' as const, occurredAt: '2026-07-13T09:20:00+00:00',
  receivedAt: adminCutoff, sourceKey: 'bcma_warehouse', sourceRank: 0, assertionCount: 1,
};

function payload(): PharmacyTat {
  return pharmacyTatSchema.parse({
    generatedAt: at, sourceCutoffAt: at, administrationCutoffAt: adminCutoff, freshnessStatus: 'fresh', degradedMode: true, state: 'degraded',
    stateMessage: 'Study results are partial; inspect coverage and the warehouse administration cutoff.',
    freshness: { status: 'fresh', asOf: at, sourceCutoffAt: at, lagMinutes: 0, sourceLabel: 'Pharmacy operational feeds', explanation: null },
    administrationFreshness: { status: 'batch', asOf: at, sourceCutoffAt: adminCutoff, lagMinutes: 300, sourceLabel: 'Administration warehouse', explanation: 'Warehouse-derived, current only as of the batch cutoff.' },
    filters: { dateFrom: '2026-07-13', dateTo: '2026-07-13', priority: null, patientClass: null, shift: null, branch: null, limit: 1000 },
    filterOptions: { priorities: ['stat', 'first_dose', 'sepsis'], patientClasses: ['emergency', 'inpatient'], shifts: ['day', 'evening', 'night', 'weekend'], branches: ['adc', 'iv_room', 'central', 'unknown'], maxRangeDays: 90, maxLimit: 2000 },
    appliedSlaDefinitions: [primary],
    summary: { ...distribution, candidateOrderCount: 24, includedOrderCount: 3, clockDefinition: primary, basis: 'warehouse_as_of', administrationCutoffAt: adminCutoff },
    waterfall: [
      { phase: 'verification', basis: 'real_time', definition: definition('rx.study.order_verify', 'RX_ORDERED', 'RX_VERIFIED', { study_segment: true, phase: 'verification', sequence: 10, basis: 'real_time' }), cohortCount: 21, count: 21, medianMinutes: 15, p90Minutes: 30, meanMinutes: 18, missingIntervalCount: 3, excludedNegativeCount: 0, invalidTimestampCount: 0, sourceCutoffAt: at, benchmarkSourceLabel: wChart.benchmarkSourceLabel },
      { phase: 'preparation', basis: 'real_time', definition: definition('rx.study.verify_dispense', 'RX_VERIFIED', 'RX_DISPENSED', { study_segment: true, phase: 'preparation', sequence: 20, basis: 'real_time' }), cohortCount: 11, count: 11, medianMinutes: 30, p90Minutes: 75, meanMinutes: 40, missingIntervalCount: 0, excludedNegativeCount: 0, invalidTimestampCount: 0, sourceCutoffAt: at, benchmarkSourceLabel: wChart.benchmarkSourceLabel },
      { phase: 'dispense', basis: 'real_time', definition: definition('rx.study.dispense_deliver', 'RX_DISPENSED', 'RX_DELIVERED', { study_segment: true, phase: 'dispense', sequence: 30, basis: 'real_time' }), cohortCount: 7, count: 7, medianMinutes: 10, p90Minutes: 10, meanMinutes: 10, missingIntervalCount: 0, excludedNegativeCount: 0, invalidTimestampCount: 0, sourceCutoffAt: at, benchmarkSourceLabel: wChart.benchmarkSourceLabel },
      { phase: 'delivery', basis: 'warehouse_as_of', definition: definition('rx.study.deliver_admin', 'RX_DELIVERED', 'RX_ADMINISTERED', { study_segment: true, phase: 'delivery', sequence: 40, basis: 'warehouse_as_of' }), cohortCount: 3, count: 3, medianMinutes: 15, p90Minutes: 139, meanMinutes: 70, missingIntervalCount: 0, excludedNegativeCount: 0, invalidTimestampCount: 0, sourceCutoffAt: adminCutoff, benchmarkSourceLabel: wChart.benchmarkSourceLabel },
      { phase: 'end_to_end', basis: 'warehouse_as_of', definition: primary, cohortCount: 3, ...distribution, missingIntervalCount: 0, excludedNegativeCount: 0, invalidTimestampCount: 0, sourceCutoffAt: adminCutoff, benchmarkSourceLabel: wChart.benchmarkSourceLabel },
    ],
    dailyTrend: { ...wChart, label: 'Daily order-to-administration trend', basis: 'warehouse_as_of', points: [{ ...point, key: '2026-07-13', label: 'Jul 13' }] },
    breakdowns: {
      priority: { ...wChart, label: 'Order-to-administration by Priority', dimension: 'priority', basis: 'warehouse_as_of', points: [point] },
      shift: { ...wChart, label: 'Order-to-administration by Shift', dimension: 'shift', basis: 'warehouse_as_of', points: [{ ...point, key: 'day', label: 'Day' }] },
      unit: { ...wChart, label: 'Order-to-administration by Unit', dimension: 'unitLabel', basis: 'warehouse_as_of', points: [{ ...point, key: 'ICU', label: 'ICU' }] },
      branch: { ...wChart, label: 'Order-to-administration by Preparation branch', dimension: 'branch', basis: 'warehouse_as_of', points: [{ ...point, key: 'iv_room', label: 'Iv Room' }] },
    },
    queueDepthHeatmap: { clockDefinition: 'Verification-queue entries by weekday and hour.', basis: 'real_time', days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], hours: Array.from({ length: 24 }, (_, i) => i), cells: [{ day: 'Mon', dayIndex: 1, hour: 9, count: 5 }], totalQueued: 24, peakCount: 5, sourceCutoffAt: at },
    missingDosePareto: { clockDefinition: 'Orders with a missing-dose event, grouped by branch. No causal claim is made.', basis: 'real_time', chainCount: 1, sourceCutoffAt: at, points: [{ key: 'adc', label: 'Adc', count: 1, percent: 100, cumulativePercent: 100 }] },
    dischargeReadinessTrend: { clockDefinition: 'Discharge-clock orders by planned-discharge day.', basis: 'real_time', cohortCount: 2, sourceCutoffAt: at, points: [{ date: '2026-07-13', label: 'Jul 13', cohortCount: 2, readyOnTimeCount: 1, readyOnTimePercent: 50 }] },
    shortageImpact: { clockDefinition: 'Order-to-administration contrasted by shortage flag; does not assert causation.', basis: 'warehouse_as_of', shortageOrderCount: 1, sourceCutoffAt: adminCutoff, points: [{ key: 'on_shortage', label: 'On shortage', count: 0, medianMinutes: null, p90Minutes: null, meanMinutes: null }, { key: 'not_on_shortage', label: 'Not on shortage', count: 3, ...distribution }] },
    mappingCoverage: { clockDefinition: 'Terminology mapping of the bounded cohort. Unmapped orders are counted, not hidden.', totalOrderCount: 24, mappedCount: 23, unmappedLocalCount: 1, mappedPercent: 95.8, unmappedLocalPercent: 4.2, points: [{ key: 'mapped', label: 'Mapped (RxNorm / NDC)', count: 23 }, { key: 'unmapped_local', label: 'Unmapped local', count: 1 }] },
    benchmarkReferences: [{ definitionUuid: primary.definitionUuid, metricKey: 'rx.study.order_admin', label: 'Order to administration', basis: 'warehouse_as_of', sourceReferenceId: 'study_clock_no_numeric_benchmark', sourceLabel: 'Reference clock only; no governed numeric benchmark', classification: 'no_numeric_benchmark', numericLines: [] }],
    coverage: { candidateOrderCount: 24, analyzedOrderCount: 24, includedOrderCount: 3, possibleIntervalCount: 120, includedIntervalCount: 45, percent: 37.5, missingAssertionIntervalCount: 75, excludedNegativeIntervalCount: 0, invalidTimestampIntervalCount: 0, selectedAssertionConflictCount: 3, truncated: false, unanalyzedCandidateCount: 0, definition: 'Coverage ledger definition.' },
    lineage: { count: 45, truncated: false, definition: 'Each interval references its exact clock and assertions.', items: [{ orderUuid: '66666666-6666-4666-8666-666666666666', definitionUuid: primary.definitionUuid, metricKey: primary.metricKey, basis: 'warehouse_as_of', minutes: 80, date: '2026-07-13', priority: 'first_dose', clockClass: 'first_dose', branch: 'iv_room', medicationLabel: 'Vancomycin IV', patientClass: 'inpatient', unitLabel: 'ICU', shift: 'night', sourceCutoffAt: adminCutoff, startAssertion: realtimeAssertion, stopAssertion: warehouseAssertion }] },
    privacy: { patientIdentifiersIncluded: false, doseInstructionsIncluded: false, individualPerformanceIncluded: false, identifierPolicy: 'Only operational UUIDs are returned; no user-level dimension.' },
  });
}

describe('Pharmacy TAT Study', () => {
  it('renders every governed chart, the real-time versus warehouse split, coverage, and lineage', () => {
    render(<PharmacyTatPage pharmacyTat={payload()} />);

    expect(screen.getByRole('heading', { name: 'Pharmacy TAT Study' })).toBeInTheDocument();
    expect(screen.getByLabelText('From')).toHaveValue('2026-07-13');
    expect(screen.getByLabelText('Branch')).toBeInTheDocument();
    expect(screen.getByText('Order-to-administration P90').closest('section')).toHaveTextContent('176 min');
    // Warehouse-as-of badge and administration cutoff labeling are present.
    expect(screen.getByText('Warehouse as-of source')).toBeInTheDocument();
    // The waterfall table separates real-time from warehouse-as-of segments.
    const waterfall = screen.getByRole('table', { name: 'Accessible Pharmacy TAT segment waterfall summary' });
    expect(waterfall).toHaveTextContent('RX_DELIVERED → RX_ADMINISTERED');
    expect(waterfall).toHaveTextContent('Warehouse as-of');
    expect(waterfall).toHaveTextContent('Real-time');
    // Heatmap has an accessible table fallback.
    expect(screen.getByRole('table', { name: 'Accessible verification queue depth by weekday and hour' })).toHaveTextContent('9:00');
    expect(screen.getByRole('table', { name: 'Accessible missing-dose Pareto summary' })).toHaveTextContent('Adc');
    expect(screen.getByRole('table', { name: 'Accessible discharge readiness trend summary' })).toHaveTextContent('50%');
    expect(screen.getByRole('table', { name: 'Accessible shortage impact contrast summary' })).toHaveTextContent('Not on shortage');
    // Mapping coverage quantifies unmapped orders rather than hiding them.
    expect(screen.getByRole('table', { name: 'Accessible mapping coverage summary' })).toHaveTextContent('Unmapped local');
    expect(screen.getByRole('table', { name: 'Accessible mapping coverage summary' })).toHaveTextContent('1');
    // Benchmark table shows the study clock as reference-only, never an SLA.
    expect(screen.getByRole('table', { name: 'Pharmacy governed benchmark references' })).toHaveTextContent('No governed numeric line');
    expect(screen.getByRole('alert')).toHaveTextContent('missing pairs');
    // Lineage names the warehouse RX_ADMINISTERED stop with its warehouse basis.
    fireEvent.click(screen.getByText(/Selected assertion and clock audit sample/));
    const lineage = screen.getByRole('table', { name: 'Pharmacy TAT selected assertion lineage sample' });
    expect(lineage).toHaveTextContent('bcma_warehouse');
    expect(lineage).toHaveTextContent('RX_ADMINISTERED');
    expect(screen.getByRole('link', { name: 'Open Medication Flow Board' })).toHaveAttribute('href', '/pharmacy');
    expect(screen.queryByText(/patient_ref|verifier_ref|diversion/i)).not.toBeInTheDocument();
  });
});
