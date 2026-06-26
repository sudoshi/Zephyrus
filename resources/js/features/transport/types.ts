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
}

export interface TransportOption {
  key: string;
  name: string;
  type?: string;
  available?: number;
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
