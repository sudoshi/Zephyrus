import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AuthProviders from '@/Pages/Admin/AuthProviders';

vi.mock('axios', () => ({
  default: {
    put: vi.fn(),
    post: vi.fn(),
    isAxiosError: vi.fn(),
  },
}));

vi.mock('@/Components/Dashboard/DashboardLayout', () => ({
  default: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));

const props = {
  local: { enabled: true, registrationEnabled: false },
  oidc: {
    providerType: 'oidc' as const,
    stored: {
      exists: true,
      enabled: true,
      displayName: 'Enterprise SSO',
      settings: {
        client_id: 'zephyrus-web',
        allowed_groups: ['Zephyrus Users'],
      },
    },
    effective: {
      enabled: true,
      publiclyAvailable: true,
      displayName: 'Enterprise SSO',
      settings: {
        discovery_url: 'https://identity.example/.well-known/openid-configuration',
        client_id: 'zephyrus-web',
        redirect_uri: 'https://zephyrus.example/auth/oidc/callback',
        scopes: ['openid', 'profile', 'email'],
        allowed_groups: ['Zephyrus Users'],
        admin_groups: ['Zephyrus Admins'],
      },
      clientSecretConfigured: true,
    },
    networkPolicy: {
      allowedHosts: ['identity.example'],
      allowedRedirectUris: ['https://zephyrus.example/auth/oidc/callback'],
      privateNetworksAllowed: false,
    },
  },
};

describe('Authentication Providers administration', () => {
  beforeEach(() => {
    vi.mocked(axios.put).mockReset();
    vi.mocked(axios.post).mockReset();
  });

  it('shows effective readiness without rendering a client-secret input', () => {
    render(<AuthProviders {...props} />);

    expect(screen.getByRole('heading', { level: 1, name: 'Authentication Providers' })).toBeInTheDocument();
    expect(screen.getByText('Available on the login page')).toBeInTheDocument();
    expect(screen.getByText('Deployment-managed; never stored here')).toBeInTheDocument();
    expect(screen.queryByLabelText(/client secret/i)).not.toBeInTheDocument();
    expect(screen.getByText('identity.example')).toBeInTheDocument();
    expect(screen.getByText(/Private network identity providers:/)).toHaveTextContent('denied');
  });

  it('submits only governed non-secret settings', async () => {
    vi.mocked(axios.put).mockResolvedValue({ data: {} });
    render(<AuthProviders {...props} />);

    fireEvent.change(screen.getByLabelText('Client ID'), { target: { value: 'zephyrus-updated' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save provider settings' }));

    await waitFor(() => expect(axios.put).toHaveBeenCalledOnce());
    expect(axios.put).toHaveBeenCalledWith('/admin/auth-providers/oidc', expect.objectContaining({
      is_enabled: true,
      display_name: 'Enterprise SSO',
      settings: expect.objectContaining({ client_id: 'zephyrus-updated' }),
    }));
    expect(JSON.stringify(vi.mocked(axios.put).mock.calls[0])).not.toContain('client_secret');
  });

  it('runs bounded saved-configuration diagnostics and renders signing readiness', async () => {
    vi.mocked(axios.post).mockResolvedValue({
      data: {
        status: 'healthy',
        checkedAt: '2026-07-12T20:00:00-04:00',
        issuer: 'https://identity.example/tenant',
        jwks_uri: 'https://identity.example/jwks',
        signing_key_count: 2,
        signing_algorithms: ['RS256'],
        latency_ms: 42.5,
      },
    });
    render(<AuthProviders {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'Test discovery and JWKS' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalledWith('/admin/auth-providers/oidc/diagnostics'));
    expect(await screen.findByText('OIDC diagnostic: healthy')).toBeInTheDocument();
    expect(screen.getByText('https://identity.example/tenant')).toBeInTheDocument();
    expect(screen.getByText('2 (RS256)')).toBeInTheDocument();
    expect(screen.getByText(/No client secret or user token was transmitted/)).toBeInTheDocument();
  });

  it('requires unsaved changes to be saved before diagnostics', () => {
    render(<AuthProviders {...props} />);

    fireEvent.change(screen.getByLabelText('Client ID'), { target: { value: 'unsaved-client' } });

    expect(screen.getByRole('button', { name: 'Test discovery and JWKS' })).toBeDisabled();
  });
});
