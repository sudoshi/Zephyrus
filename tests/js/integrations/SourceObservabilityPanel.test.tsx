import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ObservabilityPanel } from '@/Pages/Integrations/Index';
import { useCollectSourceObservation, useSourceObservability } from '@/features/integrations/hooks';

const idleMutation = () => ({ mutate: vi.fn(), isPending: false });

vi.mock('@/features/integrations/hooks', () => ({
  useIntegrationControlPlane: vi.fn(),
  usePreviewIntegrationReplay: vi.fn(),
  useRequestIntegrationReplay: vi.fn(),
  useQueueEpicFhirPoll: vi.fn(),
  useQueueIntegrationHealthCheck: vi.fn(),
  useQueueIntegrationReplay: vi.fn(),
  useSourceObservability: vi.fn(),
  useCollectSourceObservation: vi.fn(),
  useAcknowledgeSloBreach: vi.fn(() => idleMutation()),
  useEscalateSloBreach: vi.fn(() => idleMutation()),
  useLinkSloBreachIncident: vi.fn(() => idleMutation()),
  useReviewSloBreach: vi.fn(() => idleMutation()),
}));

const snapshot = {
  sourceId: 7,
  current: {
    observationId: 12,
    observationUuid: '019f5bea-a90d-7078-8dc6-c8c3a54ea8f8',
    batchUuid: '019f5bea-a90d-7078-8dc6-c8c3a54ea8f9',
    correlationUuid: '019f5bea-a90d-7078-8dc6-c8c3a54ea8fa',
    sloDefinitionId: 3,
    status: 'failed',
    protocolStatus: 'healthy',
    protocolErrorCode: null,
    maintenanceActive: false,
    observedAtIso: '2026-07-13T14:00:00+00:00',
    freshUntilIso: '2026-07-13T14:03:00+00:00',
    origin: 'scheduled',
    recordedByUserId: null,
    summary: { met: 3, breached: 2, unknown: 2, not_applicable: 0 },
    queueState: { backpressureStatus: 'warning', sourceActiveRunDepth: 4 },
    runtimeState: {
      circuitBreaker: { state: 'unknown' },
      rateLimit: { state: 'unknown' },
      retryBudget: { state: 'unknown' },
    },
    evidenceSha256: 'a'.repeat(64),
    stale: false,
  },
  history: [],
  openBreaches: [{
    breachId: 1,
    breachUuid: '019f5bea-a90d-7078-8dc6-c8c3a54ea8fb',
    metricKey: 'freshness',
    status: 'open',
    notificationSuppressed: false,
    openedAtIso: '2026-07-13T13:55:00+00:00',
    lastObservedAtIso: '2026-07-13T14:00:00+00:00',
  }],
  contract: { appendOnly: true, externalCallsAllowed: false, missingEvidenceStatus: 'unknown' },
};

describe('source observability panel', () => {
  const refetch = vi.fn();
  const mutate = vi.fn();

  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(useSourceObservability).mockReturnValue({
      data: snapshot,
      isLoading: false,
      isError: false,
      refetch,
    } as unknown as ReturnType<typeof useSourceObservability>);
    vi.mocked(useCollectSourceObservation).mockReturnValue({
      isPending: false,
      mutate,
    } as unknown as ReturnType<typeof useCollectSourceObservation>);
  });

  it('renders explicit breached and unknown truth with PHI-safe operational state', () => {
    render(<ObservabilityPanel selectedSourceId={7} canOperateIntegrations />);

    expect(screen.getByText(/Missing measurements remain unknown/i)).toBeInTheDocument();
    expect(screen.getByText('Breached SLOs').nextElementSibling).toHaveTextContent('2');
    expect(screen.getByText('Unknown SLOs').nextElementSibling).toHaveTextContent('2');
    expect(screen.getByText('freshness')).toBeInTheDocument();
    expect(screen.getByText('Eligible for on-call alert delivery.')).toBeInTheDocument();
    expect(screen.getByText('warning')).toBeInTheDocument();
    expect(document.body).not.toHaveTextContent('ZPHI-');
  });

  it('gates and invokes manual observation through the operator capability', () => {
    const { rerender } = render(<ObservabilityPanel selectedSourceId={7} canOperateIntegrations={false} />);
    expect(screen.queryByRole('button', { name: 'Observe now' })).not.toBeInTheDocument();
    expect(screen.getAllByText(/operateIntegrations capability required/).length).toBeGreaterThan(0);

    rerender(<ObservabilityPanel selectedSourceId={7} canOperateIntegrations />);
    fireEvent.click(screen.getByRole('button', { name: 'Observe now' }));
    expect(mutate).toHaveBeenCalledWith(7, expect.objectContaining({ onSuccess: expect.any(Function) }));
  });

  it('exposes the INT-OBS 5 breach workflow controls to an operator', () => {
    render(<ObservabilityPanel selectedSourceId={7} canOperateIntegrations />);
    expect(screen.getByRole('button', { name: 'Acknowledge' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Escalate' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Record review' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Link incident' })).toBeInTheDocument();
  });
});
