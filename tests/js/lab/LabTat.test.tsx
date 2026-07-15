import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import LabTatPage from '@/Pages/Analytics/LabTat';
import { labTatSchema, type LabTat } from '@/features/lab/schemas';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, ...props }: any) => <a href={href} {...props}>{children}</a>,
}));
vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: any) => <main>{children}</main> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ title, subtitle, headerContent, children }: any) => <><h1>{title}</h1><p>{subtitle}</p>{headerContent}{children}</> }));
vi.mock('@/Components/Ancillary', () => ({ SourceFreshnessBadge: () => <span>Fresh Laboratory source</span> }));
vi.mock('@/features/lab/hooks', () => ({ useLabTat: (data: LabTat) => ({ data }) }));
vi.mock('recharts', () => ({
  ResponsiveContainer: ({ children }: any) => <div>{children}</div>,
  BarChart: ({ children }: any) => <div>{children}</div>, LineChart: ({ children }: any) => <div>{children}</div>,
  Bar: ({ name }: any) => <span>{name}</span>, Line: ({ name }: any) => <span>{name}</span>,
  CartesianGrid: () => null, Legend: () => null, Tooltip: () => null, XAxis: () => null, YAxis: () => null,
}));

const at = '2026-07-12T14:00:00+00:00';

function definition(metricKey: string, department: 'lab' | 'pathology' | 'blood_bank' = 'lab', start = 'LAB_ORDERED', stop = 'LAB_VERIFIED', scope: Record<string, unknown> = {}) {
  const suffix = metricKey === 'lab.study.order_verify' ? '111111111111' : metricKey === 'lab.ap_routine' ? '222222222222' : metricKey === 'lab.frozen' ? '333333333333' : '444444444444';
  return {
    definitionUuid: `11111111-1111-4111-8111-${suffix}`, department, metricKey, label: metricKey.replaceAll('.', ' '),
    startMilestoneCode: start, stopMilestoneCode: stop, priority: null, patientClass: null, scope,
    statistic: 'median' as const, warningMinutes: null, breachMinutes: null, targetValue: null,
    direction: 'lower_is_better' as const, unit: 'minutes', effectiveFrom: '2026-01-01T00:00:00+00:00', effectiveTo: null,
    version: 1, active: false, definitionText: `${start} to ${stop} for the bounded cohort.`, sourceReferenceId: 'study_clock_no_numeric_benchmark',
  };
}

const primary = definition('lab.study.order_verify', 'lab', 'LAB_ORDERED', 'LAB_VERIFIED', { study_segment: true, phase: 'end_to_end', sequence: 50 });
const ap = definition('lab.ap_routine', 'pathology', 'AP_RECEIVED', 'AP_SIGNED_OUT');
const frozen = definition('lab.frozen', 'pathology', 'AP_FROZEN_STARTED', 'AP_FROZEN_RESULTED');
const distribution = { count: 2, medianMinutes: 75, p90Minutes: 111, meanMinutes: 80 };
const point = { key: 'stat', label: 'Stat', ...distribution };
const chart = { clockDefinition: primary, cohortCount: 2, sourceCutoffAt: at, benchmarkSourceLabel: 'Reference clock only; no governed numeric benchmark' };
const assertion = {
  milestoneUuid: '55555555-5555-4555-8555-555555555555', code: 'LAB_ORDERED', occurredAt: '2026-07-12T12:00:00+00:00',
  receivedAt: '2026-07-12T12:01:00+00:00', sourceKey: 'demo.lis', sourceRank: 10, assertionCount: 1,
};

function payload(): LabTat {
  return labTatSchema.parse({
    generatedAt: at, sourceCutoffAt: at, freshnessStatus: 'fresh', degradedMode: true, state: 'degraded',
    stateMessage: 'Study results are partial; inspect coverage and cohort-window labels.',
    freshness: { status: 'fresh', asOf: at, sourceCutoffAt: at, lagMinutes: 0, sourceLabel: 'Laboratory milestone feeds', explanation: null },
    filters: { dateFrom: '2026-07-01', dateTo: '2026-07-12', priority: null, testFamily: null, patientClass: null, shift: null, limit: 1000 },
    filterOptions: { priorities: ['stat', 'urgent', 'routine', 'timed', 'discharge'], testFamilies: ['metabolic_panel', 'blood_count'], patientClasses: ['emergency', 'inpatient'], shifts: ['day', 'evening', 'night', 'weekend'], maxRangeDays: 90, maxLimit: 2000 },
    appliedSlaDefinitions: [primary, ap, frozen],
    summary: { ...distribution, candidateOrderCount: 4, includedOrderCount: 2, clockDefinition: primary },
    waterfall: [
      { phase: 'collection', definition: { ...primary, definitionUuid: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', metricKey: 'lab.study.order_collect', startMilestoneCode: 'LAB_ORDERED', stopMilestoneCode: 'LAB_COLLECTED' }, cohortCount: 2, ...distribution, missingIntervalCount: 1, excludedNegativeCount: 0, invalidTimestampCount: 0, sourceCutoffAt: at, benchmarkSourceLabel: chart.benchmarkSourceLabel },
      { phase: 'transport', definition: { ...primary, definitionUuid: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', metricKey: 'lab.study.collect_receive', startMilestoneCode: 'LAB_COLLECTED', stopMilestoneCode: 'LAB_RECEIVED' }, cohortCount: 2, ...distribution, missingIntervalCount: 0, excludedNegativeCount: 0, invalidTimestampCount: 0, sourceCutoffAt: at, benchmarkSourceLabel: 'Established reference clock; reference is not local policy' },
      { phase: 'analytic', definition: { ...primary, definitionUuid: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', metricKey: 'lab.study.receive_result', startMilestoneCode: 'LAB_RECEIVED', stopMilestoneCode: 'LAB_RESULTED' }, cohortCount: 2, ...distribution, missingIntervalCount: 0, excludedNegativeCount: 0, invalidTimestampCount: 0, sourceCutoffAt: at, benchmarkSourceLabel: chart.benchmarkSourceLabel },
      { phase: 'post_analytic', definition: { ...primary, definitionUuid: 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', metricKey: 'lab.study.result_verify', startMilestoneCode: 'LAB_RESULTED', stopMilestoneCode: 'LAB_VERIFIED' }, cohortCount: 2, ...distribution, missingIntervalCount: 0, excludedNegativeCount: 0, invalidTimestampCount: 0, sourceCutoffAt: at, benchmarkSourceLabel: chart.benchmarkSourceLabel },
      { phase: 'end_to_end', definition: primary, cohortCount: 2, ...distribution, missingIntervalCount: 1, excludedNegativeCount: 0, invalidTimestampCount: 0, sourceCutoffAt: at, benchmarkSourceLabel: chart.benchmarkSourceLabel },
    ],
    dailyTrend: { ...chart, label: 'Daily order-to-verification trend', points: [{ ...point, key: '2026-07-12', label: 'Jul 12' }] },
    breakdowns: {
      test: { ...chart, label: 'Order-to-verification by Test family', dimension: 'testFamily', points: [{ ...point, key: 'troponin', label: 'Troponin' }] },
      priority: { ...chart, label: 'Order-to-verification by Priority', dimension: 'priority', points: [point] },
      patientClass: { ...chart, label: 'Order-to-verification by Patient class', dimension: 'patientClass', points: [{ ...point, key: 'emergency', label: 'Emergency' }] },
      shift: { ...chart, label: 'Order-to-verification by Shift', dimension: 'shift', points: [{ ...point, key: 'weekend', label: 'Weekend' }] },
    },
    amReadiness: { clockDefinition: primary, populationDefinition: 'AM draw wave verified by local clock hour.', cohortCount: 2, sourceCutoffAt: at, points: [{ hour: 5, label: '5 AM', eligibleCount: 2, readyCount: 1, readyPercent: 50 }] },
    autoVerification: { clockDefinition: 'Latest verified result version.', populationDefinition: 'Clinical Laboratory only.', cohortCount: 2, sourceCutoffAt: at, points: [{ date: '2026-07-12', label: 'Jul 12', verifiedCount: 2, autoVerifiedCount: 1, ratePercent: 50 }] },
    specimenQuality: { clockDefinition: 'Original specimen quality events.', populationDefinition: 'Original specimens form the denominator.', denominator: 4, rejectedCount: 1, rejectionRatePercent: 25, recollectCount: 1, recollectRatePercent: 25, reasonCounts: [{ key: 'HEMOLYZED', label: 'Hemolyzed', count: 1 }] },
    criticalCallbacks: { clockDefinition: primary, populationDefinition: 'Critical callback loops only.', cohortCount: 2, openCount: 1, ...distribution, invalidIntervalCount: 0, sourceCutoffAt: at, stateCounts: [{ key: 'acknowledged', label: 'Acknowledged', count: 1 }, { key: 'pending', label: 'Pending', count: 1 }] },
    barrierPareto: { cohortCount: 4, sourceCutoffAt: at, clockDefinition: 'Persisted Laboratory SLA breaches.', points: [{ key: 'LAB_TRANSPORT_DELAY', label: 'Transport delay', count: 1, percent: 100, cumulativePercent: 100 }] },
    cohorts: {
      clinicalLab: { label: 'Clinical Laboratory', windowClass: 'current_operational', populationDefinition: 'Short-cycle clinical Laboratory only.', candidateCount: 4, includedCount: 2, primaryClockMetricKey: 'lab.study.order_verify' },
      microbiology: { label: 'Microbiology progression', windowClass: 'historical_study_only', windowLabel: 'Historical microbiology progression is outside the live operational window.', populationDefinition: 'Stage progression, not clinical TAT.', candidateCount: 1, historicalCount: 1, currentCount: 0, stageCounts: [{ key: 'susceptibility', label: 'Susceptibility', count: 1 }] },
      anatomicPathology: { label: 'Anatomic Pathology', windowClass: 'mixed_current_and_historical', windowLabel: 'Historical AP sign-out examples are outside the live operational window.', populationDefinition: 'AP receipt-to-sign-out and frozen clocks stay separate.', candidateCount: 2, historicalCount: 1, currentCount: 1, stageCounts: [{ key: 'signed_out', label: 'Signed Out', count: 1 }], signOut: { clockDefinition: ap, count: 1, medianMinutes: 2130, p90Minutes: 2130, meanMinutes: 2130, invalidIntervalCount: 0 }, frozen: { clockDefinition: frozen, count: 1, medianMinutes: 18, p90Minutes: 18, meanMinutes: 18, invalidIntervalCount: 0 } },
      bloodBank: { label: 'Blood Bank readiness', windowClass: 'current_operational', populationDefinition: 'Blood-bank readiness clocks stay separate.', candidateCount: 2, stateCounts: [{ key: 'crossmatch_ready', label: 'Crossmatch Ready', count: 1 }], typeScreen: { clockDefinition: 'BB_ORDERED → BB_TNS_READY', count: 1, medianMinutes: 60, p90Minutes: 60, meanMinutes: 60, invalidIntervalCount: 0 }, crossmatch: { clockDefinition: 'BB_ORDERED → BB_CROSSMATCH_READY', count: 1, medianMinutes: 125, p90Minutes: 125, meanMinutes: 125, invalidIntervalCount: 0 }, issue: { clockDefinition: 'BB_ORDERED → BB_UNIT_ISSUED', count: 1, medianMinutes: 230, p90Minutes: 230, meanMinutes: 230, invalidIntervalCount: 0 } },
    },
    benchmarkReferences: [{ definitionUuid: primary.definitionUuid, metricKey: 'lab.stat_tat', label: 'STAT lab TAT', sourceReferenceId: 'demo_local_policy', sourceLabel: 'Local demo policy; not an external benchmark', classification: 'local_policy', numericLines: [{ kind: 'warning', value: 45, unit: 'minutes' }] }],
    coverage: { candidateOrderCount: 4, analyzedOrderCount: 4, includedOrderCount: 2, possibleIntervalCount: 20, includedIntervalCount: 16, percent: 80, missingAssertionIntervalCount: 4, excludedNegativeIntervalCount: 0, invalidTimestampIntervalCount: 0, selectedAssertionConflictCount: 1, truncated: false, unanalyzedCandidateCount: 0, definition: 'Coverage ledger definition.', auxiliaryInvalidIntervalCount: 0 },
    lineage: { count: 16, truncated: false, definition: 'Each interval references its exact clock and assertions.', items: [{ orderUuid: '66666666-6666-4666-8666-666666666666', definitionUuid: primary.definitionUuid, metricKey: primary.metricKey, minutes: 75, date: '2026-07-12', priority: 'stat', testFamily: 'troponin', testLabel: 'Troponin I', patientClass: 'emergency', shift: 'weekend', sourceCutoffAt: at, startAssertion: assertion, stopAssertion: { ...assertion, milestoneUuid: '77777777-7777-4777-8777-777777777777', code: 'LAB_VERIFIED', occurredAt: '2026-07-12T13:15:00+00:00', receivedAt: '2026-07-12T13:16:00+00:00' } }] },
    privacy: { patientIdentifiersIncluded: false, clinicalResultContentIncluded: false, sourceResultKeysIncluded: false, identifierPolicy: 'Only operational UUIDs are returned.' },
  });
}

describe('Laboratory TAT Study', () => {
  it('renders every governed chart, clock/population evidence, cohort boundary, benchmark, coverage, and lineage', () => {
    render(<LabTatPage labTat={payload()} />);

    expect(screen.getByRole('heading', { name: 'Laboratory TAT Study' })).toBeInTheDocument();
    expect(screen.getByLabelText('From')).toHaveValue('2026-07-01');
    expect(screen.getByLabelText('Through')).toHaveValue('2026-07-12');
    expect(screen.getByText('Order-to-verification P90').closest('section')).toHaveTextContent('111 min');
    expect(screen.getByRole('table', { name: 'Accessible Laboratory TAT segment waterfall summary' })).toHaveTextContent('LAB_COLLECTED → LAB_RECEIVED');
    expect(screen.getByRole('table', { name: 'Accessible daily Laboratory TAT trend summary' })).toHaveTextContent('2026-07-12');
    expect(screen.getByRole('table', { name: 'Accessible Order-to-verification by Test family summary' })).toHaveTextContent('Troponin');
    expect(screen.getByRole('table', { name: 'Accessible AM Laboratory readiness curve summary' })).toHaveTextContent('50%');
    expect(screen.getByRole('table', { name: 'Accessible Laboratory auto-verification trend summary' })).toHaveTextContent('Auto-verified');
    expect(screen.getByRole('table', { name: 'Accessible Laboratory specimen-quality rate summary' })).toHaveTextContent('Recollect');
    expect(screen.getByRole('img', { name: /critical callback state/i })).toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Accessible Laboratory breach Pareto summary' })).toHaveTextContent('Transport delay');
    expect(screen.getByRole('table', { name: 'Microbiology result-stage progression' })).toHaveTextContent('Susceptibility');
    expect(screen.getAllByText(/outside the live operational window/i)).toHaveLength(2);
    expect(screen.getByRole('table', { name: 'Blood Bank readiness states' })).toHaveTextContent('Crossmatch Ready');
    expect(screen.getByRole('table', { name: 'Laboratory governed benchmark references' })).toHaveTextContent('Local demo policy; not an external benchmark');
    expect(screen.getByRole('alert')).toHaveTextContent('missing pairs');
    fireEvent.click(screen.getByText(/Selected assertion and clock audit sample/));
    expect(screen.getByRole('table', { name: 'Laboratory TAT selected assertion lineage sample' })).toHaveTextContent('demo.lis');
    expect(screen.getByRole('link', { name: 'Open Laboratory Flow Board' })).toHaveAttribute('href', '/lab');
    expect(screen.queryByText(/source_result_key|specimen_uuid|patient_ref/i)).not.toBeInTheDocument();
  });
});
