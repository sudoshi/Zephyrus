import { act, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AnatomicPathology from '@/Pages/Lab/AnatomicPathology';
import FrozenSectionTimer from '@/Components/Lab/FrozenSectionTimer';
import CareJourneyCard from '@/Components/Operations/CaseManagement/CareJourneyCard';
import { anatomicPathologySchema, type AnatomicPathology as Contract, type AnatomicPathologyCase } from '@/features/lab/schemas';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, ...props }: any) => <a href={href} {...props}>{children}</a>,
}));
vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: any) => <main>{children}</main> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ title, subtitle, headerContent, children }: any) => <><h1>{title}</h1><p>{subtitle}</p>{headerContent}{children}</> }));
vi.mock('@/features/lab/hooks', () => ({ useAnatomicPathology: (data: Contract) => ({ data, refetch: vi.fn() }) }));

const fresh = { status: 'fresh' as const, asOf: '2026-07-11T14:00:00+00:00', sourceCutoffAt: '2026-07-11T13:58:00+00:00', lagMinutes: 2, sourceLabel: 'AP operational feeds', explanation: null };
const stages = [
  ['received', 'Received', '2026-07-10T08:00:00+00:00', 'complete'],
  ['grossed', 'Grossed', '2026-07-10T10:00:00+00:00', 'complete'],
  ['processing', 'Processing batch', '2026-07-10T18:00:00+00:00', 'current'],
  ['slides_ready', 'Slides ready', null, 'pending'],
  ['diagnosed', 'Diagnosed', null, 'pending'],
  ['signed_out', 'Signed out', null, 'pending'],
] as const;

function item(overrides: Partial<AnatomicPathologyCase> = {}): AnatomicPathologyCase {
  const base = {
    apCaseUuid: '11111111-1111-4111-8111-111111111111', orderUuid: '22222222-2222-4222-8222-222222222222',
    caseId: 17, caseLabel: 'OR case 17', sourceCaseKey: 'ap-case-17', sourceAccessionKey: 'ap-accession-17', sourceKey: 'demo.ap-lis',
    procedureLabel: 'Routine surgical pathology', caseType: 'surgical', cohort: 'routine' as const, cohortLabel: 'Routine',
    stage: 'processing', stageLabel: 'Processing', currentStageAt: '2026-07-10T18:00:00+00:00', stageAgeMinutes: 1200,
    totalAgeMinutes: 1800, ageBand: '8_to_24h' as const, terminal: false,
    timeline: stages.map(([stage, label, at, state]) => ({ stage, label, at, state })),
    structuralStage: { kind: 'overnight_batch' as const, label: 'Overnight histology batch', enteredAt: '2026-07-10T18:00:00+00:00', explanation: 'The histology batch is a declared structural workflow stage, not unexplained idle time.' },
    benchmarkKey: 'routine' as const,
    frozen: { applicable: false, status: 'not_applicable' as const, startedAt: null, resultedAt: null, elapsedMinutes: null, timerActive: false, timer: null },
    sourceCutoffAt: '2026-07-11T13:58:00+00:00', drillHref: '/lab/anatomic-path?caseId=17',
  };

  return anatomicPathologySchema.shape.data.element.parse({ ...base, ...overrides });
}

const timer = {
  caseId: 18, apCaseUuid: '33333333-3333-4333-8333-333333333333', label: 'Intraoperative frozen section',
  startedAt: '2026-07-11T13:48:00+00:00', elapsedMinutes: 12, blocking: true as const,
  explanation: 'Frozen-section interpretation is in progress for this active OR case.', sourceCutoffAt: '2026-07-11T13:58:00+00:00',
  drillHref: '/lab/anatomic-path?caseId=18',
};

function payload(overrides: Partial<Contract> = {}): Contract {
  const frozen = item({
    apCaseUuid: timer.apCaseUuid, orderUuid: '44444444-4444-4444-8444-444444444444', caseId: 18, caseLabel: 'OR case 18',
    sourceCaseKey: 'ap-case-18', sourceAccessionKey: 'ap-accession-18', procedureLabel: 'Intraoperative frozen section',
    caseType: 'frozen_section', cohort: 'frozen_section', cohortLabel: 'Frozen section', stage: 'received', stageLabel: 'Received',
    stageAgeMinutes: 12, totalAgeMinutes: 22, ageBand: 'under_4h',
    structuralStage: { kind: 'none', label: null, enteredAt: null, explanation: null }, benchmarkKey: 'frozen_single_block',
    frozen: { applicable: true, status: 'in_progress', startedAt: timer.startedAt, resultedAt: null, elapsedMinutes: 12, timerActive: true, timer },
    drillHref: timer.drillHref,
  });
  const base = {
    generatedAt: '2026-07-11T14:00:00+00:00', lookbackDays: 7, state: 'normal' as const,
    stateMessage: 'Anatomic-pathology stages and frozen-section evidence are current.', freshness: fresh,
    filters: { stage: 'all' as const, cohort: 'all' as const, status: 'all' as const, ageBand: 'all' as const, caseId: null, limit: 50 },
    filterOptions: { stages: ['all', 'received', 'processing'], cohorts: ['all', 'routine', 'complex', 'consult_send_out', 'frozen_section'], statuses: ['all', 'open', 'completed'], ageBands: ['all', 'under_4h', '8_to_24h', 'complete'] },
    summary: { visible: 2, matchingBeforeLimit: 2, open: 2, completed: 0, activeFrozen: 1, byStage: [{ stage: 'processing', label: 'Processing', count: 1 }, { stage: 'received', label: 'Received', count: 1 }], byCohort: [{ cohort: 'routine', label: 'Routine', count: 1 }, { cohort: 'frozen_section', label: 'Frozen section', count: 1 }] },
    benchmarkLines: [
      { key: 'routine' as const, label: 'Routine AP final', percentile: 90 as const, thresholdValue: 2, thresholdUnit: 'days' as const, evidenceLabel: 'Established CAP guidance summarized in ACUM-ENG-ANC-001 section 8.1; reference only, not universal or local policy.', applicability: 'Routine AP cases; working-day interpretation must be governed locally before scoring.' },
      { key: 'complex' as const, label: 'Complex AP final', percentile: 90 as const, thresholdValue: 3, thresholdUnit: 'days' as const, evidenceLabel: 'Established CAP guidance summarized in ACUM-ENG-ANC-001 section 8.1; reference only, not universal or local policy.', applicability: 'Complex AP cases; working-day interpretation must be governed locally before scoring.' },
      { key: 'frozen_single_block' as const, label: 'Single-block frozen section', percentile: 90 as const, thresholdValue: 20, thresholdUnit: 'minutes' as const, evidenceLabel: 'Established CAP guidance summarized in ACUM-ENG-ANC-001 section 8.1; reference only, not universal or local policy.', applicability: 'Single-block frozen sections only; displayed as an established reference, not a clinical command.' },
    ],
    coverage: { apLis: { status: 'available' as const, explanation: 'Every selected case is sourced from a governed AP-LIS feed.' }, backfill: { status: 'not_configured' as const, lastSuccessAt: null, explanation: 'No selected AP source declares bulk backfill support; no historical-completeness claim is made.' } },
    data: [item(), frozen],
    privacy: { directPatientIdentifiersIncluded: false as const, diagnosisOrNarrativeIncluded: false as const, writebackIncluded: false as const, explanation: 'Operational AP evidence only.' },
  };

  return anatomicPathologySchema.parse({ ...base, ...overrides });
}

afterEach(() => vi.useRealTimers());

describe('Anatomic Pathology Case Aging', () => {
  it('renders stage aging, cohort separation, structural overnight work, and evidence-labeled references', () => {
    render(<AnatomicPathology pathology={payload()} />);
    expect(screen.getByRole('heading', { name: 'Anatomic Pathology Case Aging' })).toBeInTheDocument();
    expect(screen.getByText('Routine surgical pathology')).toBeInTheDocument();
    expect(screen.getAllByText('Frozen section').length).toBeGreaterThan(0);
    expect(screen.getByText('Overnight histology batch')).toBeInTheDocument();
    expect(screen.getByText(/structural workflow stage, not unexplained idle time/i)).toBeInTheDocument();
    expect(screen.getByText('Routine AP final')).toBeInTheDocument();
    expect(screen.getAllByText(/not universal or local policy/i)).toHaveLength(3);
    expect(screen.getAllByText(/working-day interpretation must be governed locally/i)).toHaveLength(2);
  });

  it('advances the active frozen timer and renders the same contract in the Perioperative journey', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-07-11T14:00:00Z'));
    render(<FrozenSectionTimer timer={timer} />);
    expect(screen.getByRole('timer')).toHaveTextContent('12 min');
    act(() => vi.advanceTimersByTime(60_000));
    expect(screen.getByRole('timer')).toHaveTextContent('13 min');

    render(<CareJourneyCard procedure={{ provider: 'Dr. Demo', status: 'In Progress', patient: 'Demo patient', specialty: 'General Surgery', location: 'OR 2', phase: 'Procedure', resourceStatus: 'On Time', type: 'Procedure', startTime: '14:30', expectedDuration: 90, journey: 55, frozenSectionTimer: timer }} measurements={[]} onClose={vi.fn()} />);
    expect(screen.getByText('Active intraoperative interpretation timer')).toBeInTheDocument();
    expect(screen.getAllByRole('link', { name: /Frozen section active/ }).at(-1)).toHaveAttribute('href', '/lab/anatomic-path?caseId=18');
  });

  it('withholds a timer after result and exposes no diagnosis or writeback control', () => {
    const resulted = item({
      cohort: 'frozen_section', cohortLabel: 'Frozen section', caseType: 'frozen_section', benchmarkKey: 'frozen_single_block',
      frozen: { applicable: true, status: 'resulted', startedAt: timer.startedAt, resultedAt: '2026-07-11T13:58:00+00:00', elapsedMinutes: 10, timerActive: false, timer: null },
      structuralStage: { kind: 'none', label: null, enteredAt: null, explanation: null },
    });
    render(<AnatomicPathology pathology={payload({ summary: { ...payload().summary, activeFrozen: 0 }, data: [resulted] })} />);
    expect(screen.queryByRole('timer')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /sign out|result|diagnose|write back/i })).not.toBeInTheDocument();
    expect(screen.queryByText(/patient name|diagnosis narrative/i)).not.toBeInTheDocument();
  });

  it('renders degraded AP-LIS/backfill and filtered-empty states honestly', () => {
    render(<AnatomicPathology pathology={payload({
      state: 'degraded', stateMessage: 'AP-LIS or governed backfill detail is incomplete; available stages remain visible without inventing missing evidence.',
      coverage: { apLis: { status: 'missing', explanation: 'AP-LIS source classification is missing.' }, backfill: { status: 'missing', lastSuccessAt: null, explanation: 'Historical completeness is uncertain.' } },
      summary: { visible: 0, matchingBeforeLimit: 0, open: 0, completed: 0, activeFrozen: 0, byStage: [], byCohort: [] }, data: [],
    })} />);
    expect(screen.getByText(/available stages remain visible without inventing missing evidence/i)).toBeInTheDocument();
    expect(screen.getByText('AP-LIS source classification is missing.')).toBeInTheDocument();
    expect(screen.getByText('Historical completeness is uncertain.')).toBeInTheDocument();
    expect(screen.getByText('No AP cases match the selected filters.')).toBeInTheDocument();
  });
});
