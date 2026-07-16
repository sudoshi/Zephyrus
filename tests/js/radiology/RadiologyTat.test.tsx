import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import RadiologyTatPage from '@/Pages/Analytics/RadiologyTat';
import { radiologyTatSchema, type RadiologyTat } from '@/features/radiology/schemas';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, ...props }: any) => <a href={href} {...props}>{children}</a>,
}));
vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: any) => <main>{children}</main> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ title, subtitle, headerContent, children }: any) => <><h1>{title}</h1><p>{subtitle}</p>{headerContent}{children}</> }));
vi.mock('@/Components/Ancillary', () => ({ SourceFreshnessBadge: () => <span>Fresh milestone source</span> }));
vi.mock('@/features/radiology/hooks', () => ({ useRadiologyTat: (data: RadiologyTat) => ({ data }) }));
vi.mock('recharts', () => ({
  ResponsiveContainer: ({ children }: any) => <div>{children}</div>,
  BarChart: ({ children }: any) => <div>{children}</div>, LineChart: ({ children }: any) => <div>{children}</div>,
  Bar: ({ name }: any) => <span>{name}</span>, Line: ({ name }: any) => <span>{name}</span>,
  CartesianGrid: () => null, Legend: () => null, Tooltip: () => null, XAxis: () => null, YAxis: () => null,
}));

const at = '2026-07-12T14:00:00+00:00';
const definition = {
  definitionUuid: '11111111-1111-4111-8111-111111111111', department: 'rad' as const,
  metricKey: 'rad.study.order_final', label: 'Order to final report', startMilestoneCode: 'RAD_ORDERED',
  stopMilestoneCode: 'RAD_FINAL', priority: null, patientClass: null,
  scope: { study_segment: true, sequence: 60, primary_trend: true }, statistic: 'median' as const,
  warningMinutes: null, breachMinutes: null, targetValue: null, direction: 'lower_is_better' as const,
  unit: 'minutes', effectiveFrom: '2026-01-01T00:00:00+00:00', effectiveTo: null,
  version: 1, active: false, definitionText: 'RAD_ORDERED to RAD_FINAL for the bounded study cohort.',
  sourceReferenceId: 'study_clock_no_numeric_benchmark',
};
const point = { key: 'stat', label: 'Stat', count: 2, medianMinutes: 75, p90Minutes: 111, meanMinutes: 80 };
const chart = { clockDefinition: definition, cohortCount: 2, sourceCutoffAt: at, benchmarkSourceLabel: 'Reference clock only; no governed numeric benchmark' };
const assertion = {
  milestoneUuid: '22222222-2222-4222-8222-222222222222', code: 'RAD_ORDERED', occurredAt: '2026-07-12T12:00:00+00:00',
  receivedAt: '2026-07-12T12:01:00+00:00', sourceKey: 'demo.ris', sourceRank: 10, assertionCount: 1,
};
const benchmark = {
  definitionUuid: '33333333-3333-4333-8333-333333333333', metricKey: 'rad.stat_order_final',
  label: 'STAT imaging order to final Breach', lineKind: 'breach' as const, valueMinutes: 120,
  scopeLabel: 'STAT', sourceLabel: 'Local demo policy; not an external benchmark', sourceReferenceId: 'demo_local_policy',
};

function payload(): RadiologyTat {
  return radiologyTatSchema.parse({
    generatedAt: at, sourceCutoffAt: at, state: 'degraded',
    stateMessage: 'Study results are partial; inspect coverage and exclusions.',
    freshness: { status: 'fresh', asOf: at, sourceCutoffAt: at, lagMinutes: 0, sourceLabel: 'Radiology milestone feeds', explanation: null },
    filters: { dateFrom: '2026-07-01', dateTo: '2026-07-12', priority: null, modality: null, patientClass: null, shift: null, limit: 1000 },
    filterOptions: { priorities: ['stat', 'urgent', 'routine', 'discharge'], modalities: [{ code: 'CT', label: 'Computed Tomography' }], patientClasses: ['emergency', 'inpatient', 'outpatient', 'observation', 'perioperative', 'unknown'], shifts: ['day', 'evening', 'night', 'weekend'], maxRangeDays: 90, maxLimit: 2000 },
    summary: { count: 2, median: 75, p90: 111, meanMinutes: 80, candidateExamCount: 4, includedExamCount: 2 },
    waterfall: [{ definition, cohortCount: 2, medianMinutes: 75, p90Minutes: 111, meanMinutes: 80, missingIntervalCount: 1, excludedNegativeCount: 1, invalidTimestampCount: 0, sourceCutoffAt: at, benchmarkSourceLabel: chart.benchmarkSourceLabel, benchmarkLines: [] }],
    dailyTrend: { ...chart, label: 'Daily order-to-final trend', points: [{ ...point, key: '2026-07-12', label: 'Jul 12' }] },
    breakdowns: {
      priority: { ...chart, label: 'Order-to-final by Priority', dimension: 'priority', points: [point] },
      modality: { ...chart, label: 'Order-to-final by Modality', dimension: 'modality', points: [{ ...point, key: 'CT', label: 'CT' }] },
      patientClass: { ...chart, label: 'Order-to-final by Patient class', dimension: 'patientClass', points: [{ ...point, key: 'emergency', label: 'Emergency' }] },
      shift: { ...chart, label: 'Order-to-final by Shift', dimension: 'shift', points: [{ ...point, key: 'weekend', label: 'Weekend' }] },
    },
    nightWeekendComparison: { ...chart, label: 'Weekday versus night and weekend', definition: 'Facility-time shift definition.', points: [{ ...point, key: 'weekend', label: 'Weekend' }] },
    breachPareto: { cohortCount: 4, sourceCutoffAt: at, definition: 'Persisted governed breach lifecycle.', points: [{ key: 'rad.stat_order_final', label: 'STAT imaging order to final', count: 1, percent: 100, cumulativePercent: 100 }] },
    benchmarkLines: [benchmark],
    coverage: { candidateExamCount: 4, analyzedExamCount: 4, includedExamCount: 2, possibleIntervalCount: 18, includedIntervalCount: 12, percent: 66.7, missingAssertionIntervalCount: 1, excludedNegativeIntervalCount: 1, invalidTimestampIntervalCount: 0, excludedCorrectedExamCount: 1, selectedAssertionConflictCount: 1, truncated: false, unanalyzedCandidateCount: 0, definition: 'Coverage ledger definition.' },
    lineage: { count: 12, truncated: false, definition: 'Each interval references its exact clock and assertions.', items: [{ orderUuid: '44444444-4444-4444-8444-444444444444', examUuid: '55555555-5555-4555-8555-555555555555', definitionUuid: definition.definitionUuid, metricKey: definition.metricKey, minutes: 75, date: '2026-07-12', priority: 'stat', modality: 'CT', patientClass: 'emergency', shift: 'weekend', sourceCutoffAt: at, startAssertion: assertion, stopAssertion: { ...assertion, milestoneUuid: '66666666-6666-4666-8666-666666666666', code: 'RAD_FINAL', occurredAt: '2026-07-12T13:15:00+00:00', receivedAt: '2026-07-12T13:16:00+00:00' } }] },
    privacy: { patientIdentifiersIncluded: false, clinicalReportTextIncluded: false, identifierPolicy: 'Only operational UUIDs are returned.' },
  });
}

describe('Radiology TAT Study', () => {
  it('renders bounded filters, percentile-first charts, clock/cutoff/cohort evidence, exclusions, benchmarks, and lineage', () => {
    render(<RadiologyTatPage radiologyTat={payload()} />);

    expect(screen.getByRole('heading', { name: 'Radiology TAT Study' })).toBeInTheDocument();
    expect(screen.getByLabelText('From')).toHaveValue('2026-07-01');
    expect(screen.getByLabelText('Through')).toHaveValue('2026-07-12');
    expect(screen.getByText('Order-to-final P90').closest('section')).toHaveTextContent('111 min');
    expect(screen.getByRole('img', { name: /governed segment waterfall/i })).toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Accessible Radiology TAT segment waterfall summary' })).toHaveTextContent('RAD_ORDERED → RAD_FINAL');
    expect(screen.getByRole('table', { name: 'Accessible daily Radiology TAT trend summary' })).toHaveTextContent('2026-07-12');
    expect(screen.getByRole('table', { name: 'Accessible Order-to-final by Priority summary' })).toHaveTextContent('P90');
    expect(screen.getByRole('img', { name: /persisted breach Pareto/i })).toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Radiology governed benchmark lines' })).toHaveTextContent('Local demo policy; not an external benchmark');
    expect(screen.getByRole('alert')).toHaveTextContent('corrected exams');
    fireEvent.click(screen.getByText(/Selected assertion and clock audit sample/));
    expect(screen.getByRole('table', { name: 'Radiology TAT selected assertion lineage sample' })).toHaveTextContent('demo.ris');
    expect(screen.getByRole('link', { name: 'Open Imaging Flow Board' })).toHaveAttribute('href', '/radiology');
    expect(screen.queryByText(/tat-patient/i)).not.toBeInTheDocument();
  });
});
