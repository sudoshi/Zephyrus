import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import DischargeMeds from '@/Pages/Pharmacy/DischargeMeds';
import { pharmacyDischargeSchema, type PharmacyDischarge } from '@/features/pharmacy/discharge-schemas';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, preserveState: _preserveState, ...props }: any) => <a href={href} {...props}>{children}</a>,
}));
vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: any) => <main>{children}</main> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ title, subtitle, headerContent, children }: any) => <><h1>{title}</h1><p>{subtitle}</p>{headerContent}{children}</> }));
vi.mock('@/features/pharmacy/hooks', () => ({ usePharmacyDischargeReadiness: (data: PharmacyDischarge) => ({ data, refetch: vi.fn() }) }));

const freshFeeds = { status: 'fresh', asOf: '2026-07-11T14:00:00+00:00', sourceCutoffAt: '2026-07-11T14:00:00+00:00', lagMinutes: 0, sourceLabel: 'Pharmacy discharge feeds', explanation: null } as const;

function stage(status: string, label: string, blocking: boolean, count: number, oldest: number | null) {
  return { status, label, blocking, count, oldestAgeMinutes: oldest };
}

function payload(overrides: Partial<PharmacyDischarge> = {}): PharmacyDischarge {
  return pharmacyDischargeSchema.parse({
    generatedAt: '2026-07-11T14:00:00+00:00', sourceCutoffAt: '2026-07-11T14:00:00+00:00',
    freshnessStatus: 'fresh', degradedMode: false, state: 'normal', stateMessage: 'Discharge medication readiness is current.',
    freshness: freshFeeds,
    filters: { pipeline: null, encounterId: null, source: null },
    filterOptions: { pipeline: ['not_started', 'prior_auth_pending', 'verification', 'filling', 'ready', 'delivered', 'unknown'], sources: ['flow_board', 'ancillary_services', 'ed', 'rtdc', 'periop', 'cockpit'] },
    cohortDefinition: 'Today\'s planned discharges with at least one discharge medication queue row.',
    data: {
      summary: { candidates: 2, queueRows: 2, blocking: 1, satisfied: 1, overdueAgainstTarget: 0, priorAuthPending: 1, readyByTargetPercent: 50 },
      pipeline: [
        stage('not_started', 'Not started', true, 0, null),
        stage('prior_auth_pending', 'Prior authorization pending', true, 1, 80),
        stage('verification', 'Verification', true, 0, null),
        stage('filling', 'Filling / preparing', true, 0, null),
        stage('ready', 'Ready', false, 1, 45),
        stage('delivered', 'Delivered', false, 0, null),
        stage('unknown', 'Unknown', false, 0, null),
      ],
      items: [
        {
          queueUuid: '334c6110-be92-5bc5-93a9-73c0567cd8e8', orderUuid: '593c760d-5e17-53f0-a5e2-b6da619350f1', encounterId: 1080,
          patientRef: 'sim-hx-0087', medicationLabel: 'Warfarin 5 mg tablet', unitLabel: '4 East — Medical/Surgical',
          pipelineStatus: 'prior_auth_pending', pipelineLabel: 'Prior authorization pending', blocking: true, ageMinutes: 80,
          plannedDischargeAt: '2026-07-11T16:00:00+00:00', targetRelativeMinutes: -120, targetState: 'on_track', priorAuthPending: true,
          drillHref: '/pharmacy?lens=discharge&source=rtdc', rtdcHref: '/rtdc/discharge-priorities',
        },
        {
          queueUuid: 'b16c0f25-f6f2-5173-8ebc-49e467aa521a', orderUuid: 'fcb30d5e-9106-54c9-8f49-826438acd424', encounterId: 1084,
          patientRef: 'sim-hx-0109', medicationLabel: 'Warfarin 5 mg tablet', unitLabel: '6 East — Medical/Surgical',
          pipelineStatus: 'ready', pipelineLabel: 'Ready', blocking: false, ageMinutes: 45,
          plannedDischargeAt: '2026-07-11T17:00:00+00:00', targetRelativeMinutes: -180, targetState: 'met', priorAuthPending: false,
          drillHref: '/pharmacy?lens=discharge&source=rtdc', rtdcHref: '/rtdc/discharge-priorities',
        },
      ],
    },
    privacy: { directPatientIdentifiersIncluded: false, doseInstructionsIncluded: false, individualPerformanceIncluded: false, identifierPolicy: 'Pseudonymous display references only.' },
    canViewPatientDetail: true,
    ...overrides,
  });
}

function renderPage(value = payload()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<DischargeMeds discharge={value} />, { wrapper: ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider> });
}

describe('Discharge Medication Readiness', () => {
  it('renders the server-owned pipeline, target states, and ready-by-target compliance', () => {
    renderPage();
    expect(screen.getByRole('heading', { name: 'Discharge Medication Readiness' })).toBeInTheDocument();
    expect(screen.getByText('50%')).toBeInTheDocument();
    // Both pipeline rows are rendered with governed labels and a prior-auth-pending blocking state.
    expect(screen.getByText('Prior authorization pending')).toBeInTheDocument();
    expect(screen.getByText('Ready by target')).toBeInTheDocument();
    // The prior-auth row surfaces as a workflow state carried by icon + label, not color alone.
    expect(screen.getByText(/On track/)).toBeInTheDocument();
    // A bidirectional deep link back to the RTDC discharge board is present.
    expect(screen.getAllByRole('link', { name: 'Discharge board' }).length).toBe(2);
  });

  it('degrades honestly when the discharge source is stale', () => {
    const value = payload();
    renderPage(payload({
      state: 'stale', stateMessage: 'The Pharmacy discharge feed is stale; readiness is shown as-of the last source cutoff.',
      freshnessStatus: 'stale',
      data: { ...value.data, summary: { ...value.data.summary, readyByTargetPercent: null } },
    }));
    expect(screen.getByText(/readiness is shown as-of the last source cutoff/)).toBeInTheDocument();
    // A null compliance percentage renders as an em dash, never a fabricated number.
    expect(screen.getByText('—')).toBeInTheDocument();
  });

  it('renders the empty cohort without inventing queue rows', () => {
    const value = payload();
    renderPage(payload({
      state: 'no_data', stateMessage: 'No discharge medication work is queued for today\'s planned discharges.',
      data: {
        ...value.data,
        summary: { candidates: 0, queueRows: 0, blocking: 0, satisfied: 0, overdueAgainstTarget: 0, priorAuthPending: 0, readyByTargetPercent: null },
        pipeline: value.data.pipeline.map((row) => ({ ...row, count: 0, oldestAgeMinutes: null })),
        items: [],
      },
    }));
    expect(screen.getByText('No discharge medication work is queued for today\'s planned discharges.')).toBeInTheDocument();
    expect(screen.getByText('No discharge medication work matches the selected pipeline filter.')).toBeInTheDocument();
  });
});
