import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CockpitThresholds from '@/Pages/Admin/CockpitThresholds';

vi.mock('axios', () => ({
  default: {
    post: vi.fn(),
    isAxiosError: vi.fn(),
  },
}));

vi.mock('@inertiajs/react', () => ({
  Head: ({ title }: { title: string }) => <title>{title}</title>,
  Link: ({ href, children }: { href: string; children: React.ReactNode }) => <a href={href}>{children}</a>,
  router: { visit: vi.fn(), reload: vi.fn() },
}));

vi.mock('@/Components/Dashboard/DashboardLayout', () => ({
  default: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));

vi.mock('@/Components/Common/PageContentLayout', () => ({
  default: ({ title, subtitle, children }: { title: string; subtitle: string; children: React.ReactNode }) => (
    <div><h1>{title}</h1><p>{subtitle}</p>{children}</div>
  ),
}));

const policy = {
  metric_key: 'ed.door_to_provider',
  owner: 'ED Medical Director',
  scope: 'HOSP1',
  unit: 'min',
  direction: 'down' as const,
  target: 15,
  ok_edge: 15,
  warn_edge: 20,
  crit_edge: 30,
  refresh_secs: 300,
  alert_template: null,
  is_active: true,
};

const definition = {
  metricKey: 'ed.door_to_provider',
  label: 'Door to provider',
  domain: 'ed',
  scope: 'HOSP1',
  status: 'active' as const,
  policy,
  flagged: false,
  currentVersion: { versionNumber: 4, changeKind: 'governed_application', effectiveAtIso: '2026-07-13T12:00:00Z' },
};

const duplicateGroup = {
  normalizedKey: 'ed_lwbs_rate',
  kind: 'duplicate' as const,
  members: [
    { metricKey: 'ed.lwbs_rate', domain: 'ed', scope: 'HOSP1', active: true },
    { metricKey: 'ED.LWBS-RATE', domain: 'ed', scope: 'HOSP1', active: false },
  ],
};

const pendingChange = {
  changeRequestUuid: '019f0000-0000-7000-8000-000000000001',
  metricKey: 'ed.door_to_provider',
  reason: 'Retune the band after the winter surge review.',
  requestedAtIso: '2026-07-13T11:00:00Z',
  expiresAtIso: '2026-07-20T11:00:00Z',
  author: { id: 7, name: 'Policy Author', username: 'author' },
  authoredByCurrentUser: false,
  decision: null,
  changedFields: ['warn_edge'],
  proposalVersionNumber: 5,
  rollbackToVersionNumber: null,
};

const props = {
  definitions: [definition, { ...definition, metricKey: 'rtdc.boarding', label: 'Boarding', domain: 'rtdc', policy: { ...policy, metric_key: 'rtdc.boarding' } }],
  duplicates: [duplicateGroup],
  filters: { domains: ['ed', 'rtdc'], scopes: ['HOSP1'], statuses: ['active', 'inactive'] },
  selectedMetric: null,
  selectedMetricHistory: [],
  pendingChanges: [pendingChange],
  canManage: true,
};

describe('Cockpit threshold policy governance', () => {
  beforeEach(() => {
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.isAxiosError).mockReset();
  });

  it('renders versioned policies, duplicate detection, and governed workflow language', () => {
    render(<CockpitThresholds {...props} />);

    expect(screen.getByRole('heading', { level: 1, name: 'Cockpit Governance' })).toBeInTheDocument();
    // The key renders in both the policy table and the pending-changes queue.
    expect(screen.getAllByText('ed.door_to_provider').length).toBeGreaterThan(0);
    expect(screen.getAllByText('ED Medical Director').length).toBeGreaterThan(0);
    expect(screen.getAllByText('v4').length).toBeGreaterThan(0);
    expect(screen.getByText('ed_lwbs_rate')).toBeInTheDocument();
    expect(screen.getByText('duplicate')).toBeInTheDocument();
    expect(screen.getByText(/author can never approve their own change/i)).toBeInTheDocument();
  });

  it('filters the policy table by domain', () => {
    render(<CockpitThresholds {...props} />);

    fireEvent.change(screen.getByLabelText('Domain'), { target: { value: 'rtdc' } });

    // Labels render only in the definitions table, so they prove the filter.
    expect(screen.queryByText('Door to provider')).not.toBeInTheDocument();
    expect(screen.getByText('Boarding')).toBeInTheDocument();
    expect(screen.getByText('rtdc.boarding')).toBeInTheDocument();
  });

  it('previews a proposed policy against the governed preview endpoint', async () => {
    vi.mocked(axios.post).mockResolvedValue({
      data: { changedFields: ['warn_edge'], errors: [], policySha256: 'f'.repeat(64), proposed: policy },
    });
    render(<CockpitThresholds {...props} />);

    fireEvent.click(screen.getAllByRole('button', { name: 'Propose change' })[0]);
    fireEvent.change(screen.getByLabelText('Warn edge'), { target: { value: '25' } });
    fireEvent.click(screen.getByRole('button', { name: 'Preview' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalledWith(
      '/admin/cockpit/thresholds/ed.door_to_provider/preview',
      expect.objectContaining({ updates: expect.objectContaining({ warn_edge: 25 }) }),
    ));
    expect(await screen.findByText(/Changed fields: warn_edge/)).toBeInTheDocument();
  });

  it('blocks approving your own change client-side and submits decisions for others', async () => {
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
    const { rerender } = render(<CockpitThresholds {...props} />);

    fireEvent.change(screen.getByLabelText(`Decision reason for ${pendingChange.metricKey}`), {
      target: { value: 'Independent review completed with rollback evidence.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(axios.post).toHaveBeenCalledWith(
      `/admin/cockpit/threshold-changes/${pendingChange.changeRequestUuid}/decision`,
      expect.objectContaining({ approve: true }),
    ));

    rerender(<CockpitThresholds {...props} pendingChanges={[{ ...pendingChange, authoredByCurrentUser: true }]} />);
    expect(screen.getByRole('button', { name: 'Approve' })).toBeDisabled();
    expect(screen.getByText('You authored this change')).toBeInTheDocument();
  });

  it('hides every mutation control from read-only principals', () => {
    render(<CockpitThresholds {...props} canManage={false} />);

    expect(screen.queryByRole('button', { name: 'Propose change' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
  });
});
