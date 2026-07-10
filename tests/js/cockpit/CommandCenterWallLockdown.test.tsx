import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

const mocks = vi.hoisted(() => ({
  snapshot: vi.fn(),
  face: vi.fn(),
  inbox: vi.fn(),
  overview: vi.fn(),
}));

vi.mock('@/features/cockpit/hooks', () => ({
  COCKPIT_REFRESH_MS: 45_000,
  useCockpitSnapshot: (...args: unknown[]) => mocks.snapshot(...args),
  useCockpitFace: (...args: unknown[]) => mocks.face(...args),
}));

vi.mock('@/features/cockpit/live', () => ({ useLiveCockpit: () => undefined }));
vi.mock('@/features/cockpit/useCockpitStream', () => ({ useCockpitStream: () => undefined }));
vi.mock('@/features/ops/hooks', () => ({ useAgentInbox: (enabled?: boolean) => mocks.inbox(enabled) }));

vi.mock('@/stores/eddyStore', () => ({
  useEddyStore: (selector: (state: { openWithPrefill: ReturnType<typeof vi.fn> }) => unknown) =>
    selector({ openWithPrefill: vi.fn() }),
}));
vi.mock('@/stores/commandCenterStore', () => ({
  useCommandCenterStore: (selector: (state: { role: 'command' }) => unknown) => selector({ role: 'command' }),
}));

vi.mock('@/Components/Dashboard/DashboardLayout', () => ({
  default: ({ children, wall }: { children: ReactNode; wall?: boolean }) => (
    <div data-testid="layout" data-wall={wall ? 'true' : 'false'}>{children}</div>
  ),
}));
vi.mock('@/Components/Common/PageContentLayout', () => ({
  default: ({ children, headerContent }: { children: ReactNode; headerContent?: ReactNode }) => (
    <main>{headerContent}{children}</main>
  ),
}));
vi.mock('@/Components/ErrorBoundary', () => ({
  default: ({ children }: { children: ReactNode }) => children,
}));
vi.mock('@/Components/CommandCenter/states', () => ({
  CommandCenterError: () => <div data-testid="command-center-error" />,
  relativeTimeFrom: () => 'just now',
}));
vi.mock('@/Components/CommandCenter/CommandCenterView', () => ({
  CommandCenterView: () => <div data-testid="classic-command-center" />,
}));
vi.mock('@/Components/cockpit/CockpitOverview', () => ({
  CockpitOverview: (props: Record<string, unknown>) => {
    mocks.overview(props);
    return <div data-testid="cockpit-overview" />;
  },
}));
vi.mock('@/Components/cockpit/ScopedFaceView', () => ({
  ScopedFaceView: () => <div data-testid="scoped-face" />,
}));
vi.mock('@/Components/cockpit/ScopePicker', () => ({
  ScopePicker: () => <button type="button">Scope picker</button>,
}));
vi.mock('@/Components/cockpit/StaleDataBanner', () => ({
  StaleDataBanner: () => <div data-testid="stale-banner" />,
}));
vi.mock('@/Components/cockpit/DrillModal', () => ({
  DrillModal: ({ domain }: { domain: string | null }) => (
    <div data-testid="drill-modal" data-domain={domain ?? undefined} />
  ),
}));
vi.mock('@/Components/cockpit/PatientLensModal', () => ({
  PatientLensModal: ({ contextRef }: { contextRef: string | null }) => (
    <div data-testid="patient-modal" data-context={contextRef ?? undefined} />
  ),
}));
vi.mock('@/Components/cockpit/ActionInboxModal', () => ({
  ActionInboxModal: () => <div data-testid="inbox-modal" />,
}));
vi.mock('@/Components/cockpit/ExecutiveBriefPanel', () => ({
  ExecutiveBriefPanel: () => <button type="button">Executive brief</button>,
}));

import CommandCenter from '@/Pages/Dashboard/CommandCenter';

const metric = {
  key: 'rtdc.occupancy',
  label: 'Occupancy',
  value: 88,
  display: '88%',
  unit: '%',
  sub: null,
  status: 'warn',
  target: 85,
  direction: 'down',
  trend: [84, 86, 88],
  trendLabel: null,
  updatedAt: '2026-07-10T12:00:00+00:00',
};

const payload = {
  asOf: '2026-07-10T12:00:00+00:00',
  facility: { name: 'Summit Regional Medical Center', licensedBeds: 500, level: 'Academic Medical Center' },
  capacityStatus: { level: 'Surge Level 2', code: 'yellow', status: 'warn' },
  census: [metric],
  alerts: [{ key: 'rtdc.occupancy', status: 'warn', text: 'House occupancy above safe zone' }],
  okrs: [],
  domains: { rtdc: { provenance: 'live', gaugeKey: 'rtdc.occupancy', tiles: [metric] } },
};

describe('CommandCenter wall interaction boundary', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    window.history.replaceState({}, '', '/dashboard');
    vi.mocked(usePage).mockReturnValue({
      props: { auth: { user: { id: 1 } }, eddy: { enabled: true }, flash: {} },
    } as never);
    mocks.snapshot.mockImplementation((data: unknown) => ({
      data,
      refetch: vi.fn(),
      isFetching: false,
    }));
    mocks.face.mockReturnValue({ dataUpdatedAt: 0, isFetching: false, refetch: vi.fn() });
    mocks.inbox.mockReturnValue({ data: { summary: { pendingApprovals: 2 } } });
  });

  it('strips interaction deep links, disables inbox fetch, and does not mount overlays on a wall', async () => {
    window.history.replaceState(
      {},
      '',
      '/dashboard?display=wall&scope=house&drill=ed&patient=ptok_abc123',
    );

    render(<CommandCenter data={payload} cockpitEnabled />);

    await waitFor(() => {
      expect(window.location.search).toBe('?display=wall&scope=house');
    });
    expect(mocks.inbox).toHaveBeenCalledWith(false);
    expect(screen.getByTestId('layout')).toHaveAttribute('data-wall', 'true');
    expect(screen.queryByTestId('drill-modal')).not.toBeInTheDocument();
    expect(screen.queryByTestId('patient-modal')).not.toBeInTheDocument();
    expect(screen.queryByTestId('inbox-modal')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Scope picker' })).not.toBeInTheDocument();

    const props = mocks.overview.mock.calls.at(-1)?.[0] as Record<string, unknown>;
    expect(props.wall).toBe(true);
    expect(props.activeDrill).toBeNull();
    expect(props.onAlertEngage).toBeUndefined();
    expect(props.onOpenInbox).toBeUndefined();
    expect(props.briefPanel).toBeUndefined();
  });

  it('keeps deep links, inbox data, and overlay mounts available on a staffed desk', () => {
    window.history.replaceState({}, '', '/dashboard?drill=ed&patient=ptok_abc123');

    render(<CommandCenter data={payload} cockpitEnabled />);

    expect(mocks.inbox).toHaveBeenCalledWith(true);
    expect(screen.getByTestId('layout')).toHaveAttribute('data-wall', 'false');
    expect(screen.getByTestId('drill-modal')).toHaveAttribute('data-domain', 'ed');
    expect(screen.getByTestId('patient-modal')).toHaveAttribute('data-context', 'ptok_abc123');
    expect(screen.getByTestId('inbox-modal')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Scope picker' })).toBeInTheDocument();

    const props = mocks.overview.mock.calls.at(-1)?.[0] as Record<string, unknown>;
    expect(props.wall).toBe(false);
    expect(props.activeDrill).toBe('ed');
    expect(props.onAlertEngage).toEqual(expect.any(Function));
    expect(props.onOpenInbox).toEqual(expect.any(Function));
  });
});
