import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  createIntegrationCredential,
  fetchIntegrationControlPlane,
  previewIntegrationReplay,
  queueEpicFhirPoll,
  queueIntegrationHealthCheck,
  queueIntegrationReplay,
  updateIntegrationCredential,
} from '@/features/integrations/api';

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
    protocolHealthySources: 0,
    protocolDegradedSources: 0,
    protocolFailedSources: 0,
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
    queuedJobs: 0,
    failedQueueJobs: 0,
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

  it('queues governed protocol, poll, and replay operations with their required contracts', async () => {
    mocked.post
      .mockResolvedValueOnce({ data: { data: { runId: 10, runUuid: 'run-health', status: 'queued', created: true } } })
      .mockResolvedValueOnce({ data: { data: { runId: 11, runUuid: 'run-poll', status: 'queued', created: true, resourceType: 'Encounter' } } })
      .mockResolvedValueOnce({ data: { data: {
        eligibleEvents: 2, totalMatchingEvents: 2, truncated: false,
        oldestAtIso: '2026-07-10T10:00:00Z', newestAtIso: '2026-07-10T11:00:00Z',
        byEventType: [{ eventType: 'EncounterStarted', count: 2 }],
        scope: {
          sourceId: 3, from: '2026-07-10T09:00:00Z', to: '2026-07-10T12:00:00Z',
          eventTypes: ['EncounterStarted'], projectionStatuses: ['pending', 'failed'], limit: 50,
        },
        mutation: false,
      } } })
      .mockResolvedValueOnce({ data: { data: { replayJobId: 12, replayUuid: 'replay-uuid', status: 'queued', created: true } } });

    await queueIntegrationHealthCheck(3);
    await queueEpicFhirPoll(3, 'Encounter');
    const input = { source_id: 3, from: '2026-07-10T09:00:00Z', to: '2026-07-10T12:00:00Z', limit: 50 };
    const preview = await previewIntegrationReplay(input);
    await queueIntegrationReplay(input, 'replay-key-1');

    expect(preview.mutation).toBe(false);
    expect(mocked.post).toHaveBeenNthCalledWith(1, '/api/admin/integrations/sources/3/health-check');
    expect(mocked.post).toHaveBeenNthCalledWith(2, '/api/admin/integrations/sources/3/fhir/poll', { resource_type: 'Encounter' });
    expect(mocked.post).toHaveBeenNthCalledWith(4, '/api/admin/integrations/enterprise/replays', input, {
      headers: { 'Idempotency-Key': 'replay-key-1' },
    });
  });
});
