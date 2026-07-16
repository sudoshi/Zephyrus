import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  CredentialAuthorityConsole,
  NetworkRouteConfiguration,
} from '@/Components/Integrations/CredentialNetworkGovernance';
import type { IntegrationControlPlane } from '@/features/integrations/api';

vi.mock('axios');
const mocked = vi.mocked(axios, true);

const credential = {
  credentialId: 'source:9', sourceCredentialId: 9, sourceId: 3, sourceName: 'Epic Production',
  credentialKey: 'smart-backend', credentialType: 'smart_backend_services', status: 'active',
  credentialState: 'active', credentialVersionId: 14, credentialVersionNumber: 2,
  secretReferenceConfigured: true, secretProviderScheme: 'vault', certificateReferenceConfigured: true,
  certificateProviderScheme: 'vault', jwksConfigured: false, clientIdConfigured: false,
  tokenEndpointConfigured: false, rotatesAtIso: '2026-08-01T12:00:00Z', validFromIso: '2026-07-01T12:00:00Z',
  expiresAtIso: '2027-07-01T12:00:00Z', rotationOverlapEndsAtIso: null, revokedAtIso: null,
  lastUsedAtIso: '2026-07-13T12:00:00Z', validationStatus: 'ready', rotationState: 'due_30',
  validationErrorCode: null, validatedAtIso: '2026-07-13T12:00:00Z', providerVersion: '7',
  providerLeaseExpiresAtIso: '2026-07-13T13:00:00Z', certificateChainLength: 2,
  certificateExpiresAtIso: '2027-01-01T12:00:00Z', certificateFingerprintSha256: 'a'.repeat(64),
  owner: 'Security Engineering',
};
const route = {
  networkRouteId: 6, sourceId: 3, sourceName: 'Epic Production', endpointId: 8,
  routeKey: 'epic-fhir-primary', environment: 'production', transport: 'public_internet',
  hostname: 'fhir.vendor.example', port: 443, proxyConfigured: false, proxyOrigin: null,
  dnsPolicy: 'public_only', allowedIpCidrs: [], egressPolicyKey: 'integration-https-egress',
  mtlsRequired: false, clientCredentialId: null, serverName: 'fhir.vendor.example', status: 'validated',
  lastAddressCount: 2, lastErrorCode: null, lastObservedAtIso: '2026-07-13T12:00:00Z',
  policySha256: 'b'.repeat(64),
};
const data = {
  secretProviders: [
    { scheme: 'vault', enabled: true },
    { scheme: 'aws-secretsmanager', enabled: false },
  ],
  credentials: [credential],
  networkRoutes: [route],
  endpoints: [{
    endpointId: 8, sourceId: 3, sourceName: 'Epic Production', endpointType: 'fhir_base',
    authType: 'smart_backend', tlsMode: 'system_ca', isActive: true, urlConfigured: true,
    urlOrigin: 'https://fhir.vendor.example', owner: null, expectedCadenceMinutes: 5,
  }],
} as unknown as IntegrationControlPlane;

function renderWithQuery(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('credential and network governance console', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocked.isAxiosError.mockReturnValue(false);
  });

  it('shows truthful provider, version, lease, rotation, and certificate state without reference values', async () => {
    mocked.get.mockResolvedValue({ data: { data: [{
      credentialVersionId: 14, credentialVersionUuid: '019f0000-0000-7000-8000-000000000014',
      credentialId: 9, sourceId: 3, versionNumber: 2, previousVersionId: 12,
      credentialType: 'smart_backend_services', credentialState: 'active', secretReferenceConfigured: true,
      secretProviderScheme: 'vault', certificateReferenceConfigured: true, certificateProviderScheme: 'vault',
      jwksConfigured: false, validFromIso: '2026-07-01T12:00:00Z', expiresAtIso: '2027-07-01T12:00:00Z',
      rotatesAtIso: '2026-08-01T12:00:00Z', rotationOverlapEndsAtIso: null,
      authoritySha256: 'c'.repeat(64), changeReason: 'Rotate the managed credential authority.',
      governedChangeRequestUuid: null, createdAtIso: '2026-07-01T12:00:00Z',
    }] } });
    renderWithQuery(<CredentialAuthorityConsole data={data} selectedSourceId={3} />);

    expect(screen.getByText('vault://')).toBeInTheDocument();
    expect(screen.getByText('Provider configured')).toBeInTheDocument();
    expect(screen.getByText('Bootstrap configuration required')).toBeInTheDocument();
    expect(screen.getByText(/validation ready · rotation due_30/i)).toBeInTheDocument();
    expect(screen.getByText(/certificate chain 2/i)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /versions/i }));

    await waitFor(() => expect(screen.getByText(/Version 2 · active/i)).toBeInTheDocument());
    expect(mocked.get).toHaveBeenCalledWith('/api/admin/integrations/sources/3/credentials/9/versions');
    expect(screen.queryByText(/path\/to\/secret/i)).not.toBeInTheDocument();

    mocked.post.mockResolvedValue({ data: { data: {
      changeRequestUuid: '019f0000-0000-7000-8000-000000000099', action: 'rotate_integration_credential',
      subjectType: 'integration_credential', subjectId: '3:9', requestedAt: '2026-07-13T12:00:00Z',
      expiresAt: '2026-07-20T12:00:00Z', status: 'pending_approval',
    } } });
    fireEvent.click(screen.getByRole('button', { name: /request rotation/i }));
    fireEvent.change(screen.getByLabelText('New secret reference'), { target: { value: 'vault://clinical/backend-v2' } });
    fireEvent.click(screen.getByRole('button', { name: /request independent approval/i }));

    await waitFor(() => expect(mocked.post).toHaveBeenCalledWith(
      '/api/admin/integrations/sources/3/credentials/9/rotation-requests',
      expect.objectContaining({ secret_ref: 'vault://clinical/backend-v2' }),
    ));
  });

  it('revalidates a route while displaying counts and policy hashes instead of IP addresses', async () => {
    mocked.get.mockResolvedValue({ data: { data: [route] } });
    mocked.post.mockResolvedValue({ data: { data: route } });
    renderWithQuery(<NetworkRouteConfiguration data={data} selectedSourceId={3} />);

    expect(await screen.findByText(/epic-fhir-primary · fhir.vendor.example:443/i)).toBeInTheDocument();
    expect(screen.getByText(/2 resolved addresses/i)).toBeInTheDocument();
    expect(screen.queryByText(/8\.8\.8\.8/)).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /revalidate/i }));

    await waitFor(() => expect(mocked.post).toHaveBeenCalledWith('/api/admin/integrations/sources/3/network-routes/6/validations'));
  });
});
