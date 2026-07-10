import axios from 'axios';
import { z } from 'zod';
import { sourceFreshnessSchema } from '@/features/operations/sourceFreshness';
import type {
  CreateEnterpriseWritebackDraftInput,
  CreateRegionalTransferDecisionInput,
  CreateTransportRequestInput,
  DiscoverEnterpriseFhirInput,
  EnterpriseConnectorSummary,
  EnterpriseFhirDiscovery,
  EnterpriseWritebackDraft,
  RegionalTransferDecision,
  RegionalTransferAgentDraft,
  RegionalTransferSummary,
  RegionalRouteSimulationRun,
  TransportOverview,
  TransportRequest,
  TransportStatus,
} from './types';

const requestSchema = z.object({
  transport_request_id: z.number(),
  request_uuid: z.string(),
  request_type: z.enum(['inpatient', 'transfer', 'discharge', 'ems', 'care_transition']),
  priority: z.enum(['routine', 'urgent', 'stat']),
  status: z.enum([
    'requested',
    'accepted',
    'queued',
    'assigned',
    'dispatched',
    'arrived_pickup',
    'patient_ready',
    'patient_not_ready',
    'picked_up',
    'en_route',
    'arrived_destination',
    'handoff_started',
    'handoff_complete',
    'completed',
    'canceled',
    'escalated',
    'failed',
  ]),
  patient_ref: z.string(),
  encounter_ref: z.string().nullable(),
  origin: z.string(),
  destination: z.string(),
  transport_mode: z.string(),
  clinical_service: z.string().nullable(),
  requested_by: z.string().nullable(),
  requested_at: z.string().nullable(),
  needed_at: z.string().nullable(),
  assigned_at: z.string().nullable(),
  dispatched_at: z.string().nullable(),
  completed_at: z.string().nullable(),
  assigned_team: z.string().nullable(),
  assigned_vendor: z.string().nullable(),
  external_system: z.string().nullable(),
  external_id: z.string().nullable(),
  segments: z.array(z.record(z.string(), z.unknown())),
  risk_flags: z.union([z.array(z.string()), z.record(z.string(), z.unknown())]),
  handoff: z.record(z.string(), z.unknown()),
  metadata: z.record(z.string(), z.unknown()),
  sla: z.object({
    minutes_until_due: z.number().nullable(),
    at_risk: z.boolean(),
    label: z.string(),
  }),
});

const optionSchema = z.object({
  key: z.string(),
  name: z.string(),
  type: z.string().optional(),
  available: z.number().optional(),
  capacity: z.number().optional(),
  busy: z.number().optional(),
  capabilities: z.array(z.string()).optional(),
});

const overviewSchema = z.object({
  source: sourceFreshnessSchema,
  metrics: z.object({
    active: z.number(),
    at_risk: z.number(),
    completed_today: z.number(),
    stat: z.number(),
    transfer_backlog: z.number(),
    discharge_rides: z.number(),
    ems_inbound: z.number(),
  }),
  by_type: z.record(z.string(), z.number()),
  by_status: z.record(z.string(), z.number()),
  queue: z.array(requestSchema),
  vendor_options: z.array(optionSchema),
  resource_options: z.array(optionSchema),
  measures: z.array(z.object({
    key: z.string(),
    label: z.string(),
    value: z.number().nullable(),
    unit: z.string(),
    caption: z.string(),
  })),
});

const enterpriseConnectorSummarySchema = z.object({
  generatedAtIso: z.string(),
  counts: z.object({
    interfaceEngines: z.number(),
    fhirConnections: z.number(),
    smartCredentials: z.number(),
    connectorPlaybooks: z.number(),
    coexistenceAdapters: z.number(),
    writebackDrafts: z.number(),
  }),
  playbooks: z.array(z.object({
    vendorKey: z.string(),
    label: z.string(),
    systemClass: z.string(),
    status: z.string(),
    capabilities: z.record(z.string(), z.unknown()),
    implementationSteps: z.array(z.string()),
  })),
  coexistenceAdapters: z.array(z.object({
    adapterKey: z.string(),
    label: z.string(),
    vendorKey: z.string(),
    status: z.string(),
    coexistence: z.record(z.string(), z.unknown()),
  })),
});

const enterpriseFhirDiscoverySchema = z.object({
  sourceId: z.number(),
  sourceKey: z.string(),
  connectionId: z.number().nullable(),
  connectionStatus: z.string().nullable(),
  fhirVersion: z.string().nullable(),
  capabilityStatement: z.record(z.string(), z.unknown()),
  smartCredentialStatus: z.string(),
});

const enterpriseWritebackDraftSchema = z.object({
  writebackDraftId: z.number(),
  resourceType: z.string(),
  targetSystem: z.string(),
  status: z.string(),
  actionId: z.number(),
  approvalId: z.number(),
  approvalStatus: z.string(),
});

const regionalTransferCandidateSchema = z.object({
  facilityCode: z.string(),
  facilityName: z.string(),
  facilityType: z.string(),
  score: z.number(),
  recommendation: z.enum(['accept', 'conditional', 'defer']),
  availableBeds: z.number(),
  icuAvailableBeds: z.number(),
  transportMinutes: z.number(),
  capabilities: z.array(z.string()),
  constraints: z.object({
    accepts_transfers: z.boolean(),
    missing_capabilities: z.array(z.string()),
    ed_boarders: z.number(),
    transport_minutes: z.number(),
  }),
  opportunityCost: z.object({
    available_beds_after_acceptance: z.number(),
    icu_beds_after_acceptance: z.number(),
    ed_boarder_pressure: z.number(),
  }),
  rationale: z.object({
    matched_capabilities: z.array(z.string()),
    required_capabilities: z.array(z.string()),
    capacity_signal: z.string(),
    transport_signal: z.string(),
  }),
});

const regionalFacilitySchema = z.object({
  facilityCode: z.string(),
  facilityName: z.string(),
  organizationKey: z.string(),
  campusKey: z.string().nullable(),
  buildingKey: z.string().nullable(),
  serviceAreaKey: z.string().nullable(),
  facilityType: z.string(),
  status: z.string(),
  isExternal: z.boolean(),
  staffedBeds: z.number(),
  availableBeds: z.number(),
  icuAvailableBeds: z.number(),
  edBoarders: z.number(),
  transportMinutes: z.number(),
  acceptsTransfers: z.boolean(),
  capabilities: z.array(z.string()),
  capacity: z.record(z.string(), z.unknown()),
});

const regionalModelVersionSchema = z.object({
  versionKey: z.string(),
  label: z.string(),
  status: z.string(),
  approvedAt: z.string().nullable(),
  assumptions: z.record(z.string(), z.unknown()),
  facilityCount: z.number(),
});

const regionalComparisonSchema = z.object({
  scopeKey: z.string(),
  scopeLabel: z.string(),
  organizationKey: z.string(),
  campusKey: z.string().nullable(),
  buildingKey: z.string().nullable(),
  serviceAreaKey: z.string().nullable(),
  isExternal: z.boolean(),
  facilityType: z.string(),
  staffedBeds: z.number(),
  availableBeds: z.number(),
  icuAvailableBeds: z.number(),
  edBoarders: z.number(),
  transportMinutes: z.number(),
  acceptsTransfers: z.boolean(),
  capabilityCoverage: z.number(),
  candidateCount: z.number(),
  topChoiceCount: z.number(),
  averageCandidateScore: z.number().nullable(),
  pressureScore: z.number(),
  status: z.string(),
  modelDeltas: z.record(z.string(), z.object({
    availableBedsDelta: z.number(),
    icuBedsDelta: z.number(),
    transportMinutesDelta: z.number(),
  })),
});

const regionalRouteSelectionSchema = z.object({
  transportRequestId: z.number(),
  facilityCode: z.string(),
  facilityName: z.string(),
  adjustedScore: z.number(),
  transportMinutes: z.number(),
  accepted: z.boolean(),
  icuRequired: z.boolean(),
});

const regionalRouteScenarioSchema = z.object({
  scenarioKey: z.string(),
  label: z.string(),
  modelVersionKey: z.string(),
  acceptedTransfers: z.number(),
  deferredTransfers: z.number(),
  netAvailableBeds: z.number(),
  netIcuAvailableBeds: z.number(),
  totalTransportMinutes: z.number(),
  averageScore: z.number(),
  routeRiskScore: z.number(),
  selections: z.array(regionalRouteSelectionSchema),
});

const regionalRouteSimulationSchema = z.object({
  generatedAtIso: z.string(),
  modelVersionKey: z.string(),
  baseline: z.object({
    activeTransfers: z.number(),
    networkAvailableBeds: z.number(),
    networkIcuAvailableBeds: z.number(),
    modelVersionKey: z.string(),
  }),
  scenarioInputs: z.array(z.record(z.string(), z.unknown())),
  scenarios: z.array(regionalRouteScenarioSchema),
});

const regionalTransferAgentDraftRecommendationSchema = z.object({
  transportRequestId: z.number(),
  patientRef: z.string(),
  recommendedDecision: z.enum(['accepted', 'redirected', 'deferred']),
  selectedFacilityCode: z.string().nullable(),
  selectedFacilityName: z.string().nullable(),
  confidence: z.number(),
  evidence: z.record(z.string(), z.unknown()),
  guardrails: z.array(z.string()),
});

const regionalTransferAgentSummarySchema = z.object({
  agentKey: z.string(),
  label: z.string(),
  mode: z.string(),
  llmEnabled: z.boolean(),
  guardrails: z.array(z.string()),
  draftRecommendations: z.array(regionalTransferAgentDraftRecommendationSchema),
});

const regionalTransferSummarySchema = z.object({
  generatedAtIso: z.string(),
  counts: z.object({
    networkFacilities: z.number(),
    internalFacilities: z.number(),
    externalFacilities: z.number(),
    acceptingFacilities: z.number(),
    availableBeds: z.number(),
    icuAvailableBeds: z.number(),
    activeTransfers: z.number(),
    pendingDecisions: z.number(),
    modelVersions: z.number(),
    routeScenarios: z.number(),
    agentDrafts: z.number(),
  }),
  facilities: z.array(regionalFacilitySchema),
  modelVersions: z.array(regionalModelVersionSchema),
  comparison: z.array(regionalComparisonSchema),
  routeSimulation: regionalRouteSimulationSchema,
  transferCenterAgent: regionalTransferAgentSummarySchema,
  recommendations: z.array(z.object({
    transportRequestId: z.number(),
    patientRef: z.string(),
    origin: z.string(),
    destination: z.string(),
    priority: z.enum(['routine', 'urgent', 'stat']),
    clinicalService: z.string().nullable(),
    neededAt: z.string().nullable(),
    currentStatus: z.enum([
      'requested',
      'accepted',
      'queued',
      'assigned',
      'dispatched',
      'arrived_pickup',
      'patient_ready',
      'patient_not_ready',
      'picked_up',
      'en_route',
      'arrived_destination',
      'handoff_started',
      'handoff_complete',
      'completed',
      'canceled',
      'escalated',
      'failed',
    ]),
    candidates: z.array(regionalTransferCandidateSchema),
  })),
});

const regionalTransferDecisionSchema = z.object({
  decisionId: z.number(),
  transportRequestId: z.number(),
  decisionStatus: z.string(),
  selectedFacility: regionalTransferCandidateSchema,
});

const regionalRouteSimulationRunSchema = z.object({
  runId: z.number(),
  runUuid: z.string(),
  modelVersionKey: z.string(),
  generatedAtIso: z.string(),
  scenarios: z.array(regionalRouteScenarioSchema),
});

const regionalTransferAgentDraftSchema = z.object({
  decisionId: z.number(),
  transportRequestId: z.number(),
  decisionStatus: z.string(),
  recommendedDecision: z.enum(['accepted', 'redirected', 'deferred']),
  confidence: z.number(),
  selectedFacility: regionalTransferCandidateSchema,
  evidence: z.record(z.string(), z.unknown()),
  guardrails: z.array(z.string()),
});

const envelope = <T>(schema: z.ZodType<T>) => z.object({ data: schema });

export async function fetchTransportOverview(): Promise<TransportOverview> {
  const res = await axios.get('/api/transport/overview');
  return envelope(overviewSchema).parse(res.data).data;
}

export async function fetchTransportRequests(params: Record<string, string | undefined> = {}): Promise<TransportRequest[]> {
  const res = await axios.get('/api/transport/requests', { params });
  return envelope(z.array(requestSchema)).parse(res.data).data;
}

export async function createTransportRequest(input: CreateTransportRequestInput): Promise<TransportRequest> {
  const res = await axios.post('/api/transport/requests', input);
  return envelope(requestSchema).parse(res.data).data;
}

export async function assignTransportRequest(id: number, input: { assigned_team?: string; assigned_vendor?: string; note?: string }): Promise<TransportRequest> {
  const res = await axios.post(`/api/transport/requests/${id}/assign`, input);
  return envelope(requestSchema).parse(res.data).data;
}

export async function updateTransportStatus(id: number, status: TransportStatus, note?: string): Promise<TransportRequest> {
  const res = await axios.post(`/api/transport/requests/${id}/status`, { status, note });
  return envelope(requestSchema).parse(res.data).data;
}

export async function fetchTransportResources() {
  const res = await axios.get('/api/transport/resources');
  return envelope(z.array(optionSchema)).parse(res.data).data;
}

export async function fetchTransportVendors() {
  const res = await axios.get('/api/transport/vendors');
  return envelope(z.array(optionSchema)).parse(res.data).data;
}

export async function fetchEnterpriseConnectorSummary(): Promise<EnterpriseConnectorSummary> {
  const res = await axios.get('/api/admin/integrations/enterprise');
  return envelope(enterpriseConnectorSummarySchema).parse(res.data).data;
}

export async function discoverEnterpriseFhirCapabilities(input: DiscoverEnterpriseFhirInput): Promise<EnterpriseFhirDiscovery> {
  const res = await axios.post('/api/admin/integrations/enterprise/fhir/capability-discovery', input);
  return envelope(enterpriseFhirDiscoverySchema).parse(res.data).data;
}

export async function createEnterpriseWritebackDraft(input: CreateEnterpriseWritebackDraftInput): Promise<EnterpriseWritebackDraft> {
  const res = await axios.post('/api/admin/integrations/enterprise/writeback-drafts', input);
  return envelope(enterpriseWritebackDraftSchema).parse(res.data).data;
}

export async function fetchRegionalTransferSummary(): Promise<RegionalTransferSummary> {
  const res = await axios.get('/api/transport/regional-summary');
  return envelope(regionalTransferSummarySchema).parse(res.data).data;
}

export async function runRegionalRouteSimulation(input: { model_version_key?: string } = {}): Promise<RegionalRouteSimulationRun> {
  const res = await axios.post('/api/transport/regional-simulation', input);
  return envelope(regionalRouteSimulationRunSchema).parse(res.data).data;
}

export async function createRegionalTransferDecision(transportRequestId: number, input: CreateRegionalTransferDecisionInput): Promise<RegionalTransferDecision> {
  const res = await axios.post(`/api/transport/requests/${transportRequestId}/regional-decision`, input);
  return envelope(regionalTransferDecisionSchema).parse(res.data).data;
}

export async function createRegionalTransferAgentDraft(transportRequestId: number): Promise<RegionalTransferAgentDraft> {
  const res = await axios.post(`/api/transport/requests/${transportRequestId}/regional-agent-draft`);
  return envelope(regionalTransferAgentDraftSchema).parse(res.data).data;
}
