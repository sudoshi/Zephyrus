import type { SourceFreshness } from '@/features/operations/sourceFreshness';

export type TransportRequestType = 'inpatient' | 'transfer' | 'discharge' | 'ems' | 'care_transition';
export type TransportPriority = 'routine' | 'urgent' | 'stat';
export type TransportStatus =
  | 'requested'
  | 'accepted'
  | 'queued'
  | 'assigned'
  | 'dispatched'
  | 'arrived_pickup'
  | 'patient_ready'
  | 'patient_not_ready'
  | 'picked_up'
  | 'en_route'
  | 'arrived_destination'
  | 'handoff_started'
  | 'handoff_complete'
  | 'completed'
  | 'canceled'
  | 'escalated'
  | 'failed';

export interface TransportSla {
  minutes_until_due: number | null;
  at_risk: boolean;
  label: string;
}

export interface TransportRequest {
  transport_request_id: number;
  request_uuid: string;
  request_type: TransportRequestType;
  priority: TransportPriority;
  status: TransportStatus;
  patient_ref: string;
  encounter_ref: string | null;
  origin: string;
  destination: string;
  transport_mode: string;
  clinical_service: string | null;
  requested_by: string | null;
  requested_at: string | null;
  needed_at: string | null;
  assigned_at: string | null;
  dispatched_at: string | null;
  completed_at: string | null;
  assigned_team: string | null;
  assigned_vendor: string | null;
  external_system: string | null;
  external_id: string | null;
  segments: Record<string, unknown>[];
  risk_flags: string[] | Record<string, unknown>;
  handoff: Record<string, unknown>;
  metadata: Record<string, unknown>;
  sla: TransportSla;
}

export interface TransportOverview {
  source: SourceFreshness;
  metrics: {
    active: number;
    at_risk: number;
    completed_today: number;
    stat: number;
    transfer_backlog: number;
    discharge_rides: number;
    ems_inbound: number;
  };
  by_type: Record<string, number>;
  by_status: Record<string, number>;
  queue: TransportRequest[];
  vendor_options: TransportOption[];
  resource_options: TransportOption[];
  measures: TransportMeasure[];
}

export interface TransportMeasure {
  key: string;
  label: string;
  value: number | null;
  unit: string;
  caption: string;
}

export interface TransportOption {
  key: string;
  name: string;
  type?: string;
  available?: number;
  capacity?: number;
  busy?: number;
  capabilities?: string[];
}

export interface CreateTransportRequestInput {
  request_type: TransportRequestType;
  priority: TransportPriority;
  patient_ref: string;
  encounter_ref?: string | null;
  origin: string;
  destination: string;
  transport_mode: string;
  clinical_service?: string | null;
  requested_by?: string | null;
  needed_at?: string | null;
  assigned_team?: string | null;
  assigned_vendor?: string | null;
  risk_flags?: string[] | Record<string, unknown>;
  metadata?: Record<string, unknown>;
}

export interface EnterpriseConnectorPlaybook {
  vendorKey: string;
  label: string;
  systemClass: string;
  status: string;
  capabilities: Record<string, unknown>;
  implementationSteps: string[];
}

export interface EnterpriseCoexistenceAdapter {
  adapterKey: string;
  label: string;
  vendorKey: string;
  status: string;
  coexistence: Record<string, unknown>;
}

export interface EnterpriseConnectorSummary {
  generatedAtIso: string;
  counts: {
    interfaceEngines: number;
    fhirConnections: number;
    smartCredentials: number;
    connectorPlaybooks: number;
    coexistenceAdapters: number;
    writebackDrafts: number;
  };
  playbooks: EnterpriseConnectorPlaybook[];
  coexistenceAdapters: EnterpriseCoexistenceAdapter[];
}

export interface DiscoverEnterpriseFhirInput {
  source_key?: string;
  vendor?: string;
  base_url?: string;
  fhir_version?: string;
  client_id?: string;
  jwks_secret_ref?: string;
  token_url?: string;
}

export interface EnterpriseFhirDiscovery {
  sourceId: number;
  sourceKey: string;
  connectionId: number | null;
  connectionStatus: string | null;
  fhirVersion: string | null;
  capabilityStatement: Record<string, unknown>;
  smartCredentialStatus: string;
}

export interface CreateEnterpriseWritebackDraftInput {
  source_key?: string;
  vendor?: string;
  target_system?: string;
  resource_type: 'Task' | 'ServiceRequest' | 'TransportRequest' | 'EvsRequest' | 'SecureMessage';
  draft_type?: string;
  resource_payload?: Record<string, unknown>;
}

export interface EnterpriseWritebackDraft {
  writebackDraftId: number;
  resourceType: string;
  targetSystem: string;
  status: string;
  actionId: number;
  approvalId: number;
  approvalStatus: string;
}

export interface RegionalFacility {
  facilityCode: string;
  facilityName: string;
  organizationKey: string;
  campusKey: string | null;
  buildingKey: string | null;
  serviceAreaKey: string | null;
  facilityType: string;
  status: string;
  isExternal: boolean;
  staffedBeds: number;
  availableBeds: number;
  icuAvailableBeds: number;
  edBoarders: number;
  transportMinutes: number;
  acceptsTransfers: boolean;
  capabilities: string[];
  capacity: Record<string, unknown>;
}

export interface RegionalModelVersion {
  versionKey: string;
  label: string;
  status: string;
  approvedAt: string | null;
  assumptions: Record<string, unknown>;
  facilityCount: number;
}

export interface RegionalComparisonRow {
  scopeKey: string;
  scopeLabel: string;
  organizationKey: string;
  campusKey: string | null;
  buildingKey: string | null;
  serviceAreaKey: string | null;
  isExternal: boolean;
  facilityType: string;
  staffedBeds: number;
  availableBeds: number;
  icuAvailableBeds: number;
  edBoarders: number;
  transportMinutes: number;
  acceptsTransfers: boolean;
  capabilityCoverage: number;
  candidateCount: number;
  topChoiceCount: number;
  averageCandidateScore: number | null;
  pressureScore: number;
  status: string;
  modelDeltas: Record<string, {
    availableBedsDelta: number;
    icuBedsDelta: number;
    transportMinutesDelta: number;
  }>;
}

export interface RegionalRouteSelection {
  transportRequestId: number;
  facilityCode: string;
  facilityName: string;
  adjustedScore: number;
  transportMinutes: number;
  accepted: boolean;
  icuRequired: boolean;
}

export interface RegionalRouteScenario {
  scenarioKey: string;
  label: string;
  modelVersionKey: string;
  acceptedTransfers: number;
  deferredTransfers: number;
  netAvailableBeds: number;
  netIcuAvailableBeds: number;
  totalTransportMinutes: number;
  averageScore: number;
  routeRiskScore: number;
  selections: RegionalRouteSelection[];
}

export interface RegionalRouteSimulation {
  generatedAtIso: string;
  modelVersionKey: string;
  baseline: {
    activeTransfers: number;
    networkAvailableBeds: number;
    networkIcuAvailableBeds: number;
    modelVersionKey: string;
  };
  scenarioInputs: Record<string, unknown>[];
  scenarios: RegionalRouteScenario[];
}

export interface RegionalTransferAgentDraftRecommendation {
  transportRequestId: number;
  patientRef: string;
  recommendedDecision: 'accepted' | 'redirected' | 'deferred';
  selectedFacilityCode: string | null;
  selectedFacilityName: string | null;
  confidence: number;
  evidence: Record<string, unknown>;
  guardrails: string[];
}

export interface RegionalTransferAgentSummary {
  agentKey: string;
  label: string;
  mode: string;
  llmEnabled: boolean;
  guardrails: string[];
  draftRecommendations: RegionalTransferAgentDraftRecommendation[];
}

export interface RegionalTransferCandidate {
  facilityCode: string;
  facilityName: string;
  facilityType: string;
  score: number;
  recommendation: 'accept' | 'conditional' | 'defer';
  availableBeds: number;
  icuAvailableBeds: number;
  transportMinutes: number;
  capabilities: string[];
  constraints: {
    accepts_transfers: boolean;
    missing_capabilities: string[];
    ed_boarders: number;
    transport_minutes: number;
  };
  opportunityCost: {
    available_beds_after_acceptance: number;
    icu_beds_after_acceptance: number;
    ed_boarder_pressure: number;
  };
  rationale: {
    matched_capabilities: string[];
    required_capabilities: string[];
    capacity_signal: string;
    transport_signal: string;
  };
}

export interface RegionalTransferRecommendation {
  transportRequestId: number;
  patientRef: string;
  origin: string;
  destination: string;
  priority: TransportPriority;
  clinicalService: string | null;
  neededAt: string | null;
  currentStatus: TransportStatus;
  candidates: RegionalTransferCandidate[];
}

export interface RegionalTransferSummary {
  generatedAtIso: string;
  counts: {
    networkFacilities: number;
    acceptingFacilities: number;
    availableBeds: number;
    icuAvailableBeds: number;
    activeTransfers: number;
    pendingDecisions: number;
    internalFacilities: number;
    externalFacilities: number;
    modelVersions: number;
    routeScenarios: number;
    agentDrafts: number;
  };
  facilities: RegionalFacility[];
  modelVersions: RegionalModelVersion[];
  comparison: RegionalComparisonRow[];
  routeSimulation: RegionalRouteSimulation;
  transferCenterAgent: RegionalTransferAgentSummary;
  recommendations: RegionalTransferRecommendation[];
}

export interface CreateRegionalTransferDecisionInput {
  selected_facility_code: string;
  decision_status: 'draft' | 'accepted' | 'redirected' | 'deferred';
  note?: string;
}

export interface RegionalTransferDecision {
  decisionId: number;
  transportRequestId: number;
  decisionStatus: string;
  selectedFacility: RegionalTransferCandidate;
}

export interface RegionalRouteSimulationRun {
  runId: number;
  runUuid: string;
  modelVersionKey: string;
  generatedAtIso: string;
  scenarios: RegionalRouteScenario[];
}

export interface RegionalTransferAgentDraft {
  decisionId: number;
  transportRequestId: number;
  decisionStatus: string;
  recommendedDecision: 'accepted' | 'redirected' | 'deferred';
  confidence: number;
  selectedFacility: RegionalTransferCandidate;
  evidence: Record<string, unknown>;
  guardrails: string[];
}
