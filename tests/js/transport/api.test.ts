import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import {
  createEnterpriseWritebackDraft,
  createRegionalTransferAgentDraft,
  createRegionalTransferDecision,
  discoverEnterpriseFhirCapabilities,
  fetchEnterpriseConnectorSummary,
  fetchRegionalTransferSummary,
  runRegionalRouteSimulation,
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

  it('fetches regional transfer summary with scored candidates', async () => {
    mocked.get.mockResolvedValue({
      data: {
        data: {
          generatedAtIso: '2026-06-26T12:00:00+00:00',
          counts: {
            networkFacilities: 5,
            internalFacilities: 4,
            externalFacilities: 1,
            acceptingFacilities: 5,
            availableBeds: 76,
            icuAvailableBeds: 9,
            activeTransfers: 1,
            pendingDecisions: 1,
            modelVersions: 3,
            routeScenarios: 4,
            agentDrafts: 0,
          },
          facilities: [{
            facilityCode: 'zephyrus_main',
            facilityName: 'Zephyrus Academic Medical Center',
            organizationKey: 'zephyrus-network',
            campusKey: 'main',
            buildingKey: 'main_tower',
            serviceAreaKey: 'tertiary_transfer_center',
            facilityType: 'academic_tertiary',
            status: 'active',
            isExternal: false,
            staffedBeds: 500,
            availableBeds: 18,
            icuAvailableBeds: 4,
            edBoarders: 9,
            transportMinutes: 0,
            acceptsTransfers: true,
            capabilities: ['adult_transfer', 'icu'],
            capacity: { transfer_center: true },
          }],
          modelVersions: [{
            versionKey: 'phase8-network-v1',
            label: 'Approved regional network v1',
            status: 'approved',
            approvedAt: '2026-06-26 08:00:00',
            assumptions: { transfer_policy: 'prefer highest safety-adjusted score' },
            facilityCount: 5,
          }],
          comparison: [{
            scopeKey: 'zephyrus_main',
            scopeLabel: 'Zephyrus Academic Medical Center',
            organizationKey: 'zephyrus-network',
            campusKey: 'main',
            buildingKey: 'main_tower',
            serviceAreaKey: 'tertiary_transfer_center',
            isExternal: false,
            facilityType: 'academic_tertiary',
            staffedBeds: 500,
            availableBeds: 18,
            icuAvailableBeds: 4,
            edBoarders: 9,
            transportMinutes: 0,
            acceptsTransfers: true,
            capabilityCoverage: 6,
            candidateCount: 1,
            topChoiceCount: 1,
            averageCandidateScore: 88,
            pressureScore: 69,
            status: 'constrained',
            modelDeltas: {
              'phase8-network-v1': {
                availableBedsDelta: 0,
                icuBedsDelta: 0,
                transportMinutesDelta: 0,
              },
            },
          }],
          routeSimulation: {
            generatedAtIso: '2026-06-26T12:00:00+00:00',
            modelVersionKey: 'phase8-network-v1',
            baseline: {
              activeTransfers: 1,
              networkAvailableBeds: 76,
              networkIcuAvailableBeds: 9,
              modelVersionKey: 'phase8-network-v1',
            },
            scenarioInputs: [{ scenarioKey: 'baseline_best_destination' }],
            scenarios: [{
              scenarioKey: 'baseline_best_destination',
              label: 'Best scored destination',
              modelVersionKey: 'phase8-network-v1',
              acceptedTransfers: 1,
              deferredTransfers: 0,
              netAvailableBeds: 75,
              netIcuAvailableBeds: 8,
              totalTransportMinutes: 0,
              averageScore: 88,
              routeRiskScore: 0,
              selections: [{
                transportRequestId: 7,
                facilityCode: 'zephyrus_main',
                facilityName: 'Zephyrus Academic Medical Center',
                adjustedScore: 88,
                transportMinutes: 0,
                accepted: true,
                icuRequired: true,
              }],
            }],
          },
          transferCenterAgent: {
            agentKey: 'transfer_center_agent',
            label: 'Transfer Center Agent',
            mode: 'rules_only',
            llmEnabled: false,
            guardrails: ['draft_only', 'human_approval_required'],
            draftRecommendations: [{
              transportRequestId: 7,
              patientRef: 'transfer-patient',
              recommendedDecision: 'accepted',
              selectedFacilityCode: 'zephyrus_main',
              selectedFacilityName: 'Zephyrus Academic Medical Center',
              confidence: 0.88,
              evidence: { score: 88 },
              guardrails: ['draft_only'],
            }],
          },
          recommendations: [{
            transportRequestId: 7,
            patientRef: 'transfer-patient',
            origin: 'Community ED',
            destination: 'Zephyrus ICU',
            priority: 'urgent',
            clinicalService: 'Critical Care',
            neededAt: '2026-06-26T13:00:00+00:00',
            currentStatus: 'requested',
            candidates: [{
              facilityCode: 'zephyrus_main',
              facilityName: 'Zephyrus Academic Medical Center',
              facilityType: 'academic_tertiary',
              score: 88,
              recommendation: 'accept',
              availableBeds: 18,
              icuAvailableBeds: 4,
              transportMinutes: 0,
              capabilities: ['adult_transfer', 'icu'],
              constraints: {
                accepts_transfers: true,
                missing_capabilities: [],
                ed_boarders: 9,
                transport_minutes: 0,
              },
              opportunityCost: {
                available_beds_after_acceptance: 17,
                icu_beds_after_acceptance: 3,
                ed_boarder_pressure: 9,
              },
              rationale: {
                matched_capabilities: ['adult_transfer', 'icu'],
                required_capabilities: ['adult_transfer', 'icu'],
                capacity_signal: '18 beds / 4 ICU',
                transport_signal: '0 min',
              },
            }],
          }],
        },
      },
    });

    const summary = await fetchRegionalTransferSummary();

    expect(summary.counts.networkFacilities).toBe(5);
    expect(summary.counts.routeScenarios).toBe(4);
    expect(summary.transferCenterAgent.agentKey).toBe('transfer_center_agent');
    expect(summary.recommendations[0].candidates[0].score).toBe(88);
    expect(mocked.get).toHaveBeenCalledWith('/api/transport/regional-summary');
  });

  it('posts regional transfer decision input', async () => {
    mocked.post.mockResolvedValue({
      data: {
        data: {
          decisionId: 4,
          transportRequestId: 7,
          decisionStatus: 'accepted',
          selectedFacility: {
            facilityCode: 'zephyrus_main',
            facilityName: 'Zephyrus Academic Medical Center',
            facilityType: 'academic_tertiary',
            score: 88,
            recommendation: 'accept',
            availableBeds: 18,
            icuAvailableBeds: 4,
            transportMinutes: 0,
            capabilities: ['adult_transfer', 'icu'],
            constraints: {
              accepts_transfers: true,
              missing_capabilities: [],
              ed_boarders: 9,
              transport_minutes: 0,
            },
            opportunityCost: {
              available_beds_after_acceptance: 17,
              icu_beds_after_acceptance: 3,
              ed_boarder_pressure: 9,
            },
            rationale: {
              matched_capabilities: ['adult_transfer', 'icu'],
              required_capabilities: ['adult_transfer', 'icu'],
              capacity_signal: '18 beds / 4 ICU',
              transport_signal: '0 min',
            },
          },
        },
      },
    });

    const decision = await createRegionalTransferDecision(7, {
      selected_facility_code: 'zephyrus_main',
      decision_status: 'accepted',
    });

    expect(decision.selectedFacility.facilityCode).toBe('zephyrus_main');
    expect(mocked.post).toHaveBeenCalledWith('/api/transport/requests/7/regional-decision', expect.objectContaining({
      selected_facility_code: 'zephyrus_main',
    }));
  });

  it('posts regional route simulation run input', async () => {
    mocked.post.mockResolvedValue({
      data: {
        data: {
          runId: 12,
          runUuid: 'run-uuid',
          modelVersionKey: 'transport-staffed-up-v1',
          generatedAtIso: '2026-06-26T12:05:00+00:00',
          scenarios: [{
            scenarioKey: 'transport_staffed_up',
            label: 'Transport staffed up',
            modelVersionKey: 'transport-staffed-up-v1',
            acceptedTransfers: 1,
            deferredTransfers: 0,
            netAvailableBeds: 75,
            netIcuAvailableBeds: 8,
            totalTransportMinutes: 0,
            averageScore: 92,
            routeRiskScore: 0,
            selections: [{
              transportRequestId: 7,
              facilityCode: 'zephyrus_main',
              facilityName: 'Zephyrus Academic Medical Center',
              adjustedScore: 92,
              transportMinutes: 0,
              accepted: true,
              icuRequired: true,
            }],
          }],
        },
      },
    });

    const run = await runRegionalRouteSimulation({ model_version_key: 'transport-staffed-up-v1' });

    expect(run.runId).toBe(12);
    expect(run.scenarios[0].scenarioKey).toBe('transport_staffed_up');
    expect(mocked.post).toHaveBeenCalledWith('/api/transport/regional-simulation', {
      model_version_key: 'transport-staffed-up-v1',
    });
  });

  it('posts transfer-center agent draft request', async () => {
    mocked.post.mockResolvedValue({
      data: {
        data: {
          decisionId: 5,
          transportRequestId: 7,
          decisionStatus: 'draft',
          recommendedDecision: 'accepted',
          confidence: 0.88,
          evidence: { score: 88 },
          guardrails: ['draft_only', 'human_approval_required'],
          selectedFacility: {
            facilityCode: 'zephyrus_main',
            facilityName: 'Zephyrus Academic Medical Center',
            facilityType: 'academic_tertiary',
            score: 88,
            recommendation: 'accept',
            availableBeds: 18,
            icuAvailableBeds: 4,
            transportMinutes: 0,
            capabilities: ['adult_transfer', 'icu'],
            constraints: {
              accepts_transfers: true,
              missing_capabilities: [],
              ed_boarders: 9,
              transport_minutes: 0,
            },
            opportunityCost: {
              available_beds_after_acceptance: 17,
              icu_beds_after_acceptance: 3,
              ed_boarder_pressure: 9,
            },
            rationale: {
              matched_capabilities: ['adult_transfer', 'icu'],
              required_capabilities: ['adult_transfer', 'icu'],
              capacity_signal: '18 beds / 4 ICU',
              transport_signal: '0 min',
            },
          },
        },
      },
    });

    const draft = await createRegionalTransferAgentDraft(7);

    expect(draft.recommendedDecision).toBe('accepted');
    expect(draft.selectedFacility.facilityCode).toBe('zephyrus_main');
    expect(mocked.post).toHaveBeenCalledWith('/api/transport/requests/7/regional-agent-draft');
  });
});
