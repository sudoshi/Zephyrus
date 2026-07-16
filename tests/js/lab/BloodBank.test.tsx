import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import BloodBank from '@/Pages/Lab/BloodBank';
import BloodBankGate from '@/Components/Lab/BloodBankGate';
import CareJourneyCard from '@/Components/Operations/CaseManagement/CareJourneyCard';
import { bloodBankReadinessSchema, type BloodBankCaseGate, type BloodBankReadiness } from '@/features/lab/schemas';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, ...props }: any) => <a href={href} {...props}>{children}</a>,
}));
vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: any) => <main>{children}</main> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ title, subtitle, headerContent, children }: any) => <><h1>{title}</h1><p>{subtitle}</p>{headerContent}{children}</> }));
vi.mock('@/features/lab/hooks', () => ({ useBloodBankReadiness: (data: BloodBankReadiness) => ({ data, refetch: vi.fn() }) }));

const fresh = { status: 'fresh' as const, asOf: '2026-07-11T14:00:00+00:00', sourceCutoffAt: '2026-07-11T13:58:00+00:00', lagMinutes: 2, sourceLabel: 'Blood Bank operational feeds', explanation: null };

function gate(overrides: Partial<BloodBankCaseGate> = {}): BloodBankCaseGate {
  const base = {
    caseId: 17, caseLabel: 'OR case 17', surgeryDate: '2026-07-11', scheduledStartAt: '2026-07-11T14:30:00+00:00',
    scheduledDurationMinutes: 90, minutesToStart: 30, startTiming: 'upcoming' as const,
    roomLabel: 'OR 2', serviceLabel: 'General Surgery', locationLabel: 'Main OR', required: true,
    state: 'blocked' as const, ready: false, blocking: true, mtpActive: false,
    explanation: 'OR start is blocked until requested units are crossmatch ready.', requestCount: 1,
    productClasses: ['red_cells'], units: { requested: 2, allocated: 0, issued: 0 },
    typeScreenState: 'ready' as const, crossmatchState: 'pending' as const, issueState: 'not_issued' as const,
    neededByAt: '2026-07-11T14:30:00+00:00', neededByAligned: true, sourceCutoffAt: '2026-07-11T13:58:00+00:00', freshness: fresh,
    coverage: { status: 'complete' as const, explanation: 'Every request needed-by time reconciles to the selected case schedule.' },
    requests: [{
      readinessUuid: '11111111-1111-4111-8111-111111111111', orderUuid: '22222222-2222-4222-8222-222222222222',
      productClass: 'red_cells', readinessState: 'type_screen_ready', typeScreenState: 'ready', crossmatchState: 'pending',
      unitsRequested: 2, unitsAllocated: 0, unitsIssued: 0, orderedAt: '2026-07-11T12:00:00+00:00', neededByAt: '2026-07-11T14:30:00+00:00',
      typeScreenReadyAt: '2026-07-11T13:00:00+00:00', crossmatchReadyAt: null, allocatedAt: null, issuedAt: null,
      expiresAt: '2026-07-14T13:00:00+00:00', mtpActivatedAt: null, sourceKey: 'demo.blood-bank',
    }],
    drillHref: '/lab/blood-bank?caseId=17',
  };

  return bloodBankReadinessSchema.shape.data.element.parse({ ...base, ...overrides });
}

function payload(overrides: Partial<BloodBankReadiness> = {}): BloodBankReadiness {
  const notApplicable = gate({
    caseId: 18, caseLabel: 'OR case 18', required: false, state: 'not_applicable', blocking: false,
    explanation: 'No active blood-product requirement is recorded for this case.', requestCount: 0, productClasses: [],
    units: { requested: 0, allocated: 0, issued: 0 }, typeScreenState: 'not_applicable', crossmatchState: 'not_applicable',
    issueState: 'not_applicable', neededByAt: null, neededByAligned: null, coverage: { status: 'not_applicable', explanation: 'No request coverage is required.' },
    requests: [], drillHref: '/lab/blood-bank?caseId=18',
  });
  const mtp = gate({
    caseId: 19, caseLabel: 'OR case 19', state: 'mtp_active', mtpActive: true,
    explanation: 'The active massive-transfusion response is blocked on continuous blood-product allocation.',
    productClasses: ['mixed'], units: { requested: 6, allocated: 0, issued: 0 }, typeScreenState: 'pending',
    requests: gate().requests.map((request) => ({ ...request, readinessUuid: '33333333-3333-4333-8333-333333333333', orderUuid: '44444444-4444-4444-8444-444444444444', productClass: 'mixed', readinessState: 'testing', typeScreenState: 'pending', unitsRequested: 6, mtpActivatedAt: '2026-07-11T13:50:00+00:00' })),
    drillHref: '/lab/blood-bank?caseId=19',
  });
  const base = {
    generatedAt: '2026-07-11T14:00:00+00:00', operatingDate: '2026-07-11', operatingDateMode: 'latest_operating_day' as const,
    state: 'normal' as const, stateMessage: 'Blood Bank requirements and case schedule facts are current.', freshness: fresh,
    filters: { state: 'all' as const, productClass: 'all' as const, service: null, room: null, caseId: null },
    filterOptions: { states: ['all', 'blocked', 'ready', 'not_applicable', 'mtp_active', 'unknown'], productClasses: ['all', 'red_cells', 'mixed'], services: ['General Surgery'], rooms: ['OR 2'] },
    summary: { cases: 3, required: 2, blocked: 2, ready: 0, notApplicable: 1, unknown: 0, mtpActive: 1 },
    data: [gate(), notApplicable, mtp],
    privacy: { directPatientIdentifiersIncluded: false as const, bloodProductAllocationControlIncluded: false as const, writebackIncluded: false as const, explanation: 'Operational readiness only; no patient identity, allocation control, or writeback.' },
  };

  return bloodBankReadinessSchema.parse({ ...base, ...overrides });
}

describe('Blood Bank Readiness', () => {
  it('renders required, not-applicable, and MTP operational states without control actions', () => {
    render(<BloodBank bloodBank={payload()} />);
    expect(screen.getByRole('heading', { name: 'Blood Bank Readiness' })).toBeInTheDocument();
    expect(screen.getByText(/OR start is blocked until requested units are crossmatch ready/)).toBeInTheDocument();
    expect(screen.getByText(/No active blood-product requirement is recorded/)).toBeInTheDocument();
    expect(screen.getByText('MTP operational state')).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent(/read-only operational signal, not an activation, allocation, or closure command/i);
    expect(screen.queryByRole('button', { name: /allocate|issue product|activate mtp|close mtp/i })).not.toBeInTheDocument();
  });

  it('shows request evidence, compatibility state, allocation counts, and signed time-to-start', () => {
    render(<BloodBank bloodBank={payload()} />);
    expect(screen.getAllByText('30 min to start').length).toBeGreaterThan(0);
    expect(screen.getByText('0/2 allocated · 0 issued · not issued')).toBeInTheDocument();
    fireEvent.click(screen.getAllByText(/Request evidence/)[0]);
    expect(screen.getByText(/Ordered .* needed by .* T&S ready · crossmatch pending/)).toBeInTheDocument();
  });

  it('renders the same compact gate in the Perioperative case journey', () => {
    const bloodBankGate = gate();
    render(<CareJourneyCard procedure={{ provider: 'Dr. Demo', status: 'Pre-Op', patient: 'Demo patient', specialty: 'General Surgery', location: 'OR 2', phase: 'Pre-Op', resourceStatus: 'On Time', type: 'Procedure', startTime: '14:30', expectedDuration: 90, journey: 20, bloodBankGate }} measurements={[]} onClose={vi.fn()} />);
    expect(screen.getByText('Blood Bank readiness')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Blood Bank gated/ })).toHaveAttribute('href', '/lab/blood-bank?caseId=17');
    expect(screen.queryByText('Labs')).not.toBeInTheDocument();
  });

  it('renders stale unknown and filtered-empty contracts honestly', () => {
    render(<BloodBank bloodBank={payload({
      state: 'stale', stateMessage: 'Blood Bank readiness is stale; gates are unknown until current source evidence arrives.',
      freshness: { ...fresh, status: 'stale', lagMinutes: 90, explanation: 'Source is stale.' },
      summary: { cases: 0, required: 0, blocked: 0, ready: 0, notApplicable: 0, unknown: 0, mtpActive: 0 }, data: [],
    })} />);
    expect(screen.getByText(/readiness is stale/)).toBeInTheDocument();
    expect(screen.getByText('No Blood Bank case gates match the selected filters.')).toBeInTheDocument();
  });
});
