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
