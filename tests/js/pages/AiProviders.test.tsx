import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AiProviders from '@/Pages/Admin/AiProviders';

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

const profile = {
  profile_id: 'local-medgemma',
  display_name: 'Local MedGemma',
  provider_type: 'ollama',
  transport: 'ollama_chat',
  entitlement_type: 'local',
  model: 'medgemma-27b',
  base_url: 'http://127.0.0.1:11434',
  region: null,
  is_enabled: true,
  capabilities: ['chat', 'streaming', 'tool_calling'],
  safety: { patient_level_context_allowed: true },
  limits: { timeout: 120, max_output_tokens: null, monthly_budget_usd: null },
  fallback_profile_ids: [],
};

const surface = {
  surface: 'chat',
  provider_mode: 'local_only',
  default_profile_id: 'local-medgemma',
  fallback_profile_ids: [],
  allow_cloud: false,
  never_send_phi_to_cloud: true,
  required_capabilities: [],
};

const props = {
  document: { profiles: [profile], surfaces: [surface] },
  catalog: {
    surfaces: ['chat', 'rtdc', 'eddy_agent'],
    modes: ['local_only', 'cloud_first', 'disabled'],
    capabilities: ['chat', 'streaming', 'tool_calling'],
    entitlements: ['local', 'org_api_key'],
    transports: ['ollama_chat', 'anthropic_messages'],
    surfaceRequirements: { chat: ['chat', 'streaming'] },
  },
  readiness: [{ profile_id: 'local-medgemma', provider_type: 'ollama', transport: 'ollama_chat', entitlement_type: 'local', state: 'ready', agent_capable: true }],
  currentVersion: { versionNumber: 3, changeKind: 'governed_application', policySha256: 'a'.repeat(64), effectiveAtIso: '2026-07-13T12:00:00Z' },
  versions: [{
    versionId: 3,
    versionNumber: 3,
    changeKind: 'governed_application',
    changeReason: 'Adopted the local-only clinical posture.',
    policySha256: 'a'.repeat(64),
    profileCount: 1,
    surfaceCount: 1,
    rolledBackToVersionId: null,
    effectiveAtIso: '2026-07-13T12:00:00Z',
    createdBy: { id: 1, name: 'Steward', username: 'steward' },
  }],
  drift: false,
  guardrails: {
    cloudKillSwitchEnabled: true,
    monthlyBudgetUsd: 500,
    budgetCutoffThreshold: 0.95,
    phiDetectionEnabled: true,
    phiBlockOnDetection: true,
  },
  pendingChanges: [],
  canManage: true,
};

describe('Eddy AI provider governance', () => {
  beforeEach(() => {
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.isAxiosError).mockReset();
  });

  it('renders governed provider policy, PHI posture, cost limits, and version ledger', () => {
    render(<AiProviders {...props} />);

    expect(screen.getByRole('heading', { level: 1, name: 'Eddy AI Providers' })).toBeInTheDocument();
    // The profile id renders in the profiles table, routing select, and readiness card.
    expect(screen.getAllByText('local-medgemma').length).toBeGreaterThan(0);
    expect(screen.getByText('Blocked')).toBeInTheDocument();
    expect(screen.getByText('$500')).toBeInTheDocument();
    expect(screen.getByText('never send PHI to cloud')).toBeInTheDocument();
    // The version renders in the metric strip and the ledger table.
    expect(screen.getAllByText('v3').length).toBeGreaterThan(0);
    expect(screen.getByText(/never stored or shown here/i)).toBeInTheDocument();
  });

  it('runs the dry-run simulator with a surface descriptor only — no prompt field exists', async () => {
    vi.mocked(axios.post).mockResolvedValue({
      data: {
        configured: true,
        surface: 'chat',
        provider_mode: 'local_only',
        reason: 'local_only',
        blocked_reasons: [],
        fallback_used: false,
        will_call_paid_provider: false,
        selected_profile: { profile_id: 'local-medgemma', display_name: 'Local MedGemma', provider_type: 'ollama', transport: 'ollama_chat', entitlement_type: 'local', model: 'medgemma-27b', is_enabled: true },
        phi_posture: { never_send_phi_to_cloud: true, allow_cloud: false, cloud_kill_switch_enabled: true },
      },
    });
    render(<AiProviders {...props} />);

    expect(screen.getByText(/No prompt or patient content is sent or stored/)).toBeInTheDocument();
    expect(screen.queryByLabelText(/prompt/i)).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/message/i)).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Simulate route/ }));

    await waitFor(() => expect(axios.post).toHaveBeenCalledWith('/admin/ai-providers/simulate', { surface: 'chat' }));
    expect(await screen.findByText('local-medgemma (medgemma-27b)')).toBeInTheDocument();
    expect(screen.getByText('No — zero API cost')).toBeInTheDocument();
    expect(screen.getByText(/PHI never leaves local/)).toBeInTheDocument();
  });

  it('submits an edited policy for independent approval with a required reason', async () => {
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
    render(<AiProviders {...props} />);

    const submit = screen.getByRole('button', { name: 'Submit for approval' });
    expect(submit).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Provider mode for chat'), { target: { value: 'disabled' } });
    fireEvent.change(screen.getByLabelText(/Change reason/), {
      target: { value: 'Disable chat routing while the model is re-evaluated.' },
    });
    expect(submit).toBeEnabled();
    fireEvent.click(submit);

    await waitFor(() => expect(axios.post).toHaveBeenCalledWith('/admin/ai-providers/changes', expect.objectContaining({
      change_reason: 'Disable chat routing while the model is re-evaluated.',
      document: expect.objectContaining({
        surfaces: [expect.objectContaining({ surface: 'chat', provider_mode: 'disabled' })],
      }),
    })));
  });

  it('renders drift as an explicit alert and hides mutation controls from read-only principals', () => {
    render(<AiProviders {...props} drift canManage={false} />);

    expect(screen.getByRole('alert')).toHaveTextContent(/no longer match the last applied policy version/);
    expect(screen.queryByRole('button', { name: 'Submit for approval' })).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Provider mode for chat')).not.toBeInTheDocument();
    expect(screen.getByText('Detected')).toBeInTheDocument();
  });
});
