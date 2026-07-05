// tests/js/cockpit/PatientLens.test.tsx
//
// P8 WS-3 acceptance in miniature: the A2P patient lens renders the operational
// context (header, status spine, open dependencies, flow timeline,
// recommendations, actions) from /api/cockpit/patient/{ptok}; a 403 renders the
// deliberate "access limited" boundary (no retry — the CMIO A2P matrix); a
// contract break renders a retryable error card that recovers — never a crash.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { PatientLens } from '@/Components/cockpit/PatientLens';
import { fetchCockpitPatient } from '@/features/cockpit/api';

vi.mock('@/features/cockpit/api', () => ({
  fetchCockpitSnapshot: vi.fn(),
  fetchCockpitDrill: vi.fn(),
  fetchCockpitFace: vi.fn(),
  fetchCockpitPatient: vi.fn(),
}));
const mockedFetch = vi.mocked(fetchCockpitPatient);

const lensPayload = {
  altitude: 'A2P',
  persona: { role_id: 'bed_manager', title: 'Bed Manager / Flow' },
  patient: {
    patient_context_ref: 'ptok_abc',
    display: 'Authorized operational patient context',
    detail_authorized: true,
    phi_minimized: true,
  },
  header: {
    current_location: '3 West',
    target_location: 'MICU',
    service: 'Medicine',
    isolation_required: true,
    responsible_team: 'Capacity',
    as_of: '2026-07-04T12:00:00+00:00',
  },
  status_spine: [
    { domain: 'ed', label: 'ED visit', status: 'boarding', at: '2026-07-04T08:00:00+00:00' },
    { domain: 'rtdc', label: 'Bed request', status: 'pending', at: '2026-07-04T09:00:00+00:00' },
  ],
  timeline: [
    {
      event_type: 'bed_request.created',
      domain: 'rtdc',
      actor_role: 'bed_manager',
      status_after: 'pending',
      occurred_at: '2026-07-04T09:00:00+00:00',
      patient_context_ref: 'ptok_abc',
    },
  ],
  dependencies: [
    { dependency_type: 'bed_request', owner_role: 'bed_manager', status: 'pending', label: 'Pending bed placement', entity_ref: '42' },
  ],
  recommendations: [
    { recommendation_uuid: 'rec-1', source: 'eddy', title: 'Expedite MICU placement', status: 'open', risk_level: 'medium', rationale: 'ED boarding > 4h' },
  ],
  actions: [
    { kind: 'acknowledge', label: 'Acknowledge', requires_online: true },
    { kind: 'place', label: 'Place bed', requires_online: true },
  ],
  web: { href: '/rtdc/bed-tracking', label: 'Open in Zephyrus', altitude: 'A3' },
  phi_policy: { list_safe: false, push_safe: false, requires_detail_auth: true },
};

function renderLens(contextRef = 'ptok_abc') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <PatientLens contextRef={contextRef} />
    </QueryClientProvider>,
  );
}

describe('PatientLens', () => {
  beforeEach(() => vi.clearAllMocks());

  it('renders the A2P context: header, spine, dependencies, timeline, recommendations, actions', async () => {
    mockedFetch.mockResolvedValue(lensPayload);
    renderLens();

    await waitFor(() => expect(screen.getByText('3 West')).toBeInTheDocument());
    // Header sub: service + target + responsible team.
    expect(screen.getByText('Medicine')).toBeInTheDocument();
    expect(screen.getByText('MICU')).toBeInTheDocument();

    // Status spine chips (label per domain step).
    const spine = screen.getByTestId('patient-lens-spine');
    expect(within(spine).getByText('ED visit')).toBeInTheDocument();
    expect(within(spine).getByText('Bed request')).toBeInTheDocument();

    // Open dependency + flow timeline + recommendation + action affordances.
    expect(within(screen.getByTestId('patient-lens-dependencies')).getByText('Pending bed placement')).toBeInTheDocument();
    expect(within(screen.getByTestId('patient-lens-timeline')).getByText(/bed_request\.created/)).toBeInTheDocument();
    expect(within(screen.getByTestId('patient-lens-recommendations')).getByText('Expedite MICU placement')).toBeInTheDocument();
    const actions = screen.getByTestId('patient-lens-actions');
    expect(within(actions).getByText('Acknowledge')).toBeInTheDocument();
    expect(within(actions).getByText('Place bed')).toBeInTheDocument();

    // Earned accent: the worst OPEN dependency (pending) drives the header hue.
    expect(screen.getByText('3 West').closest('header')?.dataset.accent).toBe('warning');
  });

  it('renders the access-limited boundary on a 403 — no retry (a deliberate denial)', async () => {
    mockedFetch.mockRejectedValue({
      isAxiosError: true,
      response: { status: 403, data: { error: { message: 'This persona has no patient-level flow access.' } } },
      message: 'Request failed with status code 403',
    });
    renderLens();

    await waitFor(() => expect(screen.getByText('Access limited for this patient context.')).toBeInTheDocument());
    expect(screen.getByText('This persona has no patient-level flow access.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Retry' })).not.toBeInTheDocument();
  });

  it('degrades to a retryable error card when the payload breaks contract, then recovers', async () => {
    mockedFetch.mockResolvedValue({ altitude: 'A2P', persona: { role_id: 'x', title: 'X' }, patient: { patient_context_ref: 'ptok_abc' } });
    renderLens();

    await waitFor(() => expect(screen.getByText('Could not load this patient context.')).toBeInTheDocument());

    mockedFetch.mockResolvedValue(lensPayload);
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByText('3 West')).toBeInTheDocument());
  });

  it('treats a non-403 failure as a retryable error, not an access boundary', async () => {
    mockedFetch.mockRejectedValue(new Error('Network Error'));
    renderLens();

    await waitFor(() => expect(screen.getByText('Could not load this patient context.')).toBeInTheDocument());
    expect(screen.queryByText('Access limited for this patient context.')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
  });
});
