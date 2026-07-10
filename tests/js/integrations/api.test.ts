import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createIntegrationCredential, fetchIntegrationControlPlane, updateIntegrationCredential } from '@/features/integrations/api';

vi.mock('axios');
const mocked = vi.mocked(axios, true);

const emptySnapshot = {
  generatedAtIso: '2026-07-09T12:00:00+00:00',
  status: 'not_configured',
  freshnessPolicy: { freshAfterMinutes: 15, staleAfterMinutes: 60 },
  counts: {
    sources: 0,
    activeSources: 0,
    healthySources: 0,
    degradedSources: 0,
    staleSources: 0,
    failedSources: 0,
    endpoints: 0,
    capabilities: 0,
    credentials: 0,
    interfaceEngines: 0,
    fhirConnections: 0,
    smartCredentials: 0,
    connectorTemplates: 0,
    coexistenceAdapters: 0,
    ingestRuns: 0,
    inboundMessages: 0,
    canonicalEvents: 0,
    pendingProjectionEvents: 0,
    openDeadLetters: 0,
    openProjectionErrors: 0,
    replayJobs: 0,
    terminologyMaps: 0,
    writebackDrafts: 0,
    configurationAudits: 0,
  },
  sources: [],
  endpoints: [],
  fhirConnections: [],
  hl7Interfaces: [],
  connectorFamilies: [],
  mappings: { approved: 0, pendingReview: 0, byType: [] },
  runs: [],
  watermarks: [],
  deadLetters: [],
  projectionErrors: [],
  replayJobs: [],
  writebackDrafts: [],
  credentials: [],
  templates: { playbooks: [], coexistenceAdapters: [] },
  configurationAudits: [],
  audit: {
    provenanceRecords: 0,
    identityLinks: 0,
    patientMergeEvents: 0,
    changeAuditAvailable: false,
  },
};

describe('integration control-plane API', () => {
  beforeEach(() => vi.clearAllMocks());

  it('validates the monitoring response', async () => {
    mocked.get.mockResolvedValue({ data: { data: emptySnapshot } });

    const snapshot = await fetchIntegrationControlPlane();

    expect(snapshot.status).toBe('not_configured');
    expect(snapshot.counts.sources).toBe(0);
    expect(mocked.get).toHaveBeenCalledWith('/api/admin/integrations/control-plane');
  });

  it('rejects a drifted security-sensitive contract', async () => {
    mocked.get.mockResolvedValue({
      data: {
        data: {
          ...emptySnapshot,
          credentials: [{
            credentialId: 'source:1',
            sourceName: 'Epic',
            credentialKey: 'backend',
            credentialType: 'smart_backend_services',
            status: 'configured',
            secretReferenceConfigured: 'vault://raw-secret',
          }],
        },
      },
    });

    await expect(fetchIntegrationControlPlane()).rejects.toThrow();
  });

  it('validates canonical SMART credential mutation responses', async () => {
    const configured = {
      credentialId: 9,
      sourceId: 3,
      credentialKey: 'smart-backend',
      credentialType: 'smart_backend_services',
      status: 'configured',
      secretReferenceConfigured: true,
      certificateReferenceConfigured: false,
      jwksConfigured: false,
      rotatesAtIso: null,
      owner: 'Security',
    };
    mocked.post.mockResolvedValue({ data: { data: configured } });
    mocked.patch.mockResolvedValue({ data: { data: { ...configured, status: 'disabled' } } });

    const created = await createIntegrationCredential(3, {
      credential_key: 'smart-backend',
      credential_type: 'smart_backend_services',
      secret_ref: 'vault://zephyrus/epic/key',
    });
    const updated = await updateIntegrationCredential(3, 9, { is_active: false });

    expect(created.credentialType).toBe('smart_backend_services');
    expect(updated.status).toBe('disabled');
    expect(mocked.patch).toHaveBeenCalledWith('/api/admin/integrations/sources/3/credentials/9', { is_active: false });
  });
});
