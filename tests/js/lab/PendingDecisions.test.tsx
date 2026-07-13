import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import PendingDecisions from '@/Pages/Lab/PendingDecisions';
import { labDecisionPendingSchema, type LabDecisionPending } from '@/features/lab/schemas';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, ...props }: any) => <a href={href} {...props}>{children}</a>,
}));
vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: any) => <main>{children}</main> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ title, subtitle, headerContent, children }: any) => <><h1>{title}</h1><p>{subtitle}</p>{headerContent}{children}</> }));
vi.mock('@/features/lab/hooks', () => ({ useLabDecisionPending: (data: LabDecisionPending) => ({ data, refetch: vi.fn() }) }));

const orderUuid = '11111111-1111-4111-8111-111111111111';
const resultUuid = '22222222-2222-4222-8222-222222222222';
const specimenUuid = '33333333-3333-4333-8333-333333333333';

const definition = {
  definitionUuid: '44444444-4444-4444-8444-444444444444', department: 'lab' as const, metricKey: 'lab.stat_tat', label: 'STAT lab order to verified result',
  startMilestoneCode: 'LAB_ORDERED', stopMilestoneCode: 'LAB_VERIFIED', priority: 'stat', patientClass: null, scope: {}, statistic: 'item_clock' as const,
  warningMinutes: 45, breachMinutes: 60, targetValue: 90, direction: 'lower_is_better' as const, unit: 'minutes',
  effectiveFrom: '2026-01-01T00:00:00+00:00', effectiveTo: null, version: 1, active: true,
  definitionText: 'Order to selected verified result.', sourceReferenceId: 'demo_local_policy',
};

function payload(overrides: Partial<LabDecisionPending> = {}): LabDecisionPending {
  const item = {
    pendingKey: `${orderUuid}|catalog`, orderUuid, resultUuid, specimenUuid,
    label: 'Prothrombin time and INR', testFamily: 'coagulation', catalogKey: 'lab.pt_inr',
    patientRef: 'demo-periop-patient', patientClass: 'perioperative', priority: 'stat', locationLabel: 'OR Holding', encounterLinked: true,
    currentStage: 'LAB_PRELIM', resultState: { status: 'preliminary', stage: 'preliminary', critical: false, abnormalFlag: 'unknown' },
    ageMinutes: 55, sourceCutoffAt: '2026-07-11T13:58:00+00:00', decisionClass: 'or_gate' as const,
    decisionContext: { decision_class: 'or_gate', blocked_object_type: 'or_case', blocked_object_id: 17, explanation: 'Operating-room start readiness is blocked until the coagulation result is verified.' },
    destination: { objectType: 'or_case' as const, id: 17, label: 'OR case 17 · OR 2 · General Surgery', active: true as const, href: '/operations/cases?caseId=17', scheduledAt: '2026-07-11T14:30:00+00:00', expectedDischargeDate: null, bedImpact: 0, rankReason: 'A live OR start gate is the highest impact class.' },
    gateEvidence: { catalogDecisionClass: 'or_gate', identitySource: 'result_decision_context' as const, validated: true as const, explanation: 'The gate is selected from the governed test catalog and a validated source-linked downstream object; the test label is not used to infer impact.' },
    sla: { definition, startAt: '2026-07-11T13:05:00+00:00', elapsedMinutes: 55, urgency: 'warning' as const, explanation: 'LAB_ORDERED to LAB_VERIFIED.' },
    ranking: { impactRank: 0, priorityRank: 0, sortKey: '0|9999999944|0|1', reasons: ['A live OR start gate is the highest impact class.', 'Older work ranks first, then governed priority.', 'Stable order identity is the tie-breaker.'], position: 1 },
    drill: { specimenHref: `/lab/specimens?orderUuid=${orderUuid}`, destinationHref: '/operations/cases?caseId=17' }, barrierCount: 0,
  };
  const base = {
    generatedAt: '2026-07-11T14:00:00+00:00', state: 'normal', stateMessage: 'Decision-pending Laboratory facts and downstream links are current.',
    freshness: { status: 'fresh', asOf: '2026-07-11T14:00:00+00:00', sourceCutoffAt: '2026-07-11T13:58:00+00:00', lagMinutes: 2, sourceLabel: 'Laboratory operational feeds', explanation: null },
    filters: { decisionClass: 'all', priority: null, unitId: null, urgency: 'all', orderUuid: null, source: null, limit: 50 },
    filterOptions: { decisionClasses: ['all', 'or_gate', 'discharge_gate', 'ed_disposition'], priorities: ['stat', 'urgent', 'routine'], units: [{ unitId: 2, label: 'OR Holding' }], urgencies: ['all', 'breach', 'warning', 'normal', 'unconfigured', 'degraded', 'stale'] },
    rankingRule: 'Live OR gate, discharge bed impact, ED disposition, then descending age, governed priority, and stable order identity.',
    summary: { visible: 1, resolvedBeforeLimit: 1, orGates: 1, dischargeGates: 0, edDispositions: 0, unresolvedDestinations: 0, breached: 0 },
    exclusions: { noGateCatalog: 7, completedOrCancelled: 2, unresolved: [], explanation: 'Non-gating, completed, and unresolved work is excluded.' },
    data: [item],
    destinationAggregates: [{ decisionClass: 'or_gate', destinationId: 17, destinationHref: '/operations/cases?caseId=17', pendingCount: 1, oldestAgeMinutes: 55, topOrderUuid: orderUuid, resultUuids: [resultUuid] }],
    privacy: { patientContextIncluded: true, directPatientIdentifiersIncluded: false, resultContentIncluded: false, identifierPolicy: 'Pseudonymous operational context only.' },
    canAnnotateBarriers: true, barrierReasons: [{ reasonCode: 'LAB_RECOLLECT_REQUIRED', category: 'medical', label: 'Specimen recollection required' }],
  };

  return labDecisionPendingSchema.parse({ ...base, ...overrides });
}

function renderPage(value = payload()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<PendingDecisions pendingDecisions={value} />, { wrapper: ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider> });
}

describe('Laboratory Decision-Pending Results', () => {
  it('renders server-ranked impact, operational result state, SLA, destinations, and exact drills', () => {
    renderPage();
    expect(screen.getByRole('heading', { name: 'Decision-Pending Results' })).toBeInTheDocument();
    expect(screen.getByText('Prothrombin time and INR')).toBeInTheDocument();
    expect(screen.getByText(/Operating-room start readiness is blocked/)).toBeInTheDocument();
    expect(screen.getByText(/55 min elapsed · warn 45 · breach 60/)).toBeInTheDocument();
    expect(screen.getByText(/result values and narratives are excluded/i)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open specimen chain' })).toHaveAttribute('href', `/lab/specimens?orderUuid=${orderUuid}`);
    expect(screen.getByRole('link', { name: 'Open destination' })).toHaveAttribute('href', '/operations/cases?caseId=17');
    fireEvent.click(screen.getByText('Why this rank and gate?'));
    expect(screen.getByText(/test label is not used to infer impact/i)).toBeInTheDocument();
  });

  it('retains only the governed barrier mutation and opens its audited drawer', () => {
    renderPage();
    fireEvent.click(screen.getByRole('button', { name: 'Add barrier' }));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Annotate Laboratory barrier' })).toBeInTheDocument();
    expect(screen.getByText(/action is audited/i)).toBeInTheDocument();
  });

  it('explains unresolved destinations and renders the intentional empty queue', () => {
    renderPage(payload({
      state: 'degraded', stateMessage: 'One decision candidate has no validated live downstream destination.',
      summary: { visible: 0, resolvedBeforeLimit: 0, orGates: 0, dischargeGates: 0, edDispositions: 0, unresolvedDestinations: 1, breached: 0 },
      exclusions: { noGateCatalog: 7, completedOrCancelled: 2, unresolved: [{ orderUuid, decisionClass: 'or_gate', destinationId: 17, reason: 'Destination absent.' }], explanation: 'Non-gating, completed, and unresolved work is excluded.' },
      data: [], destinationAggregates: [], canAnnotateBarriers: false,
    }));
    expect(screen.getByRole('alert')).toHaveTextContent(/withheld because their downstream object could not be validated/i);
    expect(screen.getByText(/No validated decision-pending Laboratory results match/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Add barrier' })).not.toBeInTheDocument();
  });
});
