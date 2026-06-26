import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import {
  createEnterpriseWritebackDraft,
  discoverEnterpriseFhirCapabilities,
  fetchEnterpriseConnectorSummary,
} from '@/features/transport/api';

vi.mock('axios');
const mocked = vi.mocked(axios, true);

describe('transport enterprise connector api', () => {
  beforeEach(() => vi.clearAllMocks());

  it('fetches and validates enterprise connector summary', async () => {
    mocked.get.mockResolvedValue({
      data: {
        data: {
          generatedAtIso: '2026-06-26T12:00:00+00:00',
          counts: {
            interfaceEngines: 1,
            fhirConnections: 1,
            smartCredentials: 1,
            connectorPlaybooks: 3,
            coexistenceAdapters: 3,
            writebackDrafts: 0,
          },
          playbooks: [{
            vendorKey: 'epic',
            label: 'Epic Connector Playbook',
            systemClass: 'ehr',
            status: 'ready',
            capabilities: { fhir_r4: true },
            implementationSteps: ['Discover FHIR capability statement'],
          }],
          coexistenceAdapters: [{
            adapterKey: 'teletracking_coexistence',
            label: 'TeleTracking Coexistence Adapter',
            vendorKey: 'teletracking',
            status: 'ready',
            coexistence: { mode: 'read_and_reconcile' },
          }],
        },
      },
    });

    const summary = await fetchEnterpriseConnectorSummary();

    expect(summary.counts.connectorPlaybooks).toBe(3);
    expect(summary.playbooks[0].vendorKey).toBe('epic');
    expect(mocked.get).toHaveBeenCalledWith('/api/admin/integrations/enterprise');
  });

  it('posts FHIR capability discovery input', async () => {
    mocked.post.mockResolvedValue({
      data: {
        data: {
          sourceId: 7,
          sourceKey: 'epic.fhir.sandbox',
          connectionId: 11,
          connectionStatus: 'discovered',
          fhirVersion: '4.0.1',
          capabilityStatement: { resourceType: 'CapabilityStatement' },
          smartCredentialStatus: 'planned',
        },
      },
    });

    const discovery = await discoverEnterpriseFhirCapabilities({
      source_key: 'epic.fhir.sandbox',
      vendor: 'Epic',
      fhir_version: '4.0.1',
    });

    expect(discovery.connectionStatus).toBe('discovered');
    expect(mocked.post).toHaveBeenCalledWith('/api/admin/integrations/enterprise/fhir/capability-discovery', expect.objectContaining({
      source_key: 'epic.fhir.sandbox',
    }));
  });

  it('posts approval-gated writeback draft input', async () => {
    mocked.post.mockResolvedValue({
      data: {
        data: {
          writebackDraftId: 3,
          resourceType: 'Task',
          targetSystem: 'epic',
          status: 'pending_approval',
          actionId: 9,
          approvalId: 10,
          approvalStatus: 'pending',
        },
      },
    });

    const draft = await createEnterpriseWritebackDraft({
      source_key: 'epic.fhir.sandbox',
      target_system: 'epic',
      resource_type: 'Task',
      resource_payload: { resourceType: 'Task', status: 'requested' },
    });

    expect(draft.approvalStatus).toBe('pending');
    expect(mocked.post).toHaveBeenCalledWith('/api/admin/integrations/enterprise/writeback-drafts', expect.objectContaining({
      resource_type: 'Task',
    }));
  });

  it('throws when summary payload shape drifts', async () => {
    mocked.get.mockResolvedValue({ data: { data: { counts: { connectorPlaybooks: 'three' } } } });
    await expect(fetchEnterpriseConnectorSummary()).rejects.toThrow();
  });
});
