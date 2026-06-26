export interface Vector3Payload {
  x: number;
  y?: number;
  z: number;
  level?: number;
}

export interface PatientFlowSummary {
  messages: number;
  normalized_events: number;
  patients: number;
  locations: number;
  movement_events: number;
  clinical_context_events: number;
  min_occurred_at: string | null;
  max_occurred_at: string | null;
  live_events: number;
  ambient_signals?: number;
  ambient_confidence?: number;
  ambient_confidence_level?: string;
  facility_code: string;
  model_url: string;
  tileset_url?: string;
  generated_at: string;
}

export interface PatientFlowLocation {
  facility_space_id: number;
  location_code: string;
  source_location_code: string;
  name: string;
  category: string;
  floor: number | null;
  unit_code?: string | null;
  service_line?: string | null;
  acuity?: string | null;
  position_ft?: Vector3Payload | null;
  position_m?: Vector3Payload | null;
  ops_graph_nodes?: Array<{
    graphNodeId: number;
    nodeUuid: string;
    nodeType: string;
    canonicalKey: string;
    displayName: string;
    status?: string | null;
    currentState?: Record<string, unknown>;
  }>;
  metadata?: Record<string, unknown>;
}

export interface PatientFlowEvent {
  event_id: string;
  event_category: string;
  event_type: string;
  message_type?: string | null;
  trigger_event?: string | null;
  patient_id: string;
  patient_display_id: string;
  encounter_id: string;
  occurred_at: string;
  recorded_at?: string | null;
  from_location?: string | null;
  to_location?: string | null;
  point_of_care?: string | null;
  room?: string | null;
  bed?: string | null;
  patient_class?: string | null;
  fhir_encounter_status?: string | null;
  fhir_encounter_class?: string | null;
  service_line?: string | null;
  priority?: string | null;
  diagnosis_codes?: string[];
  order_codes?: string[];
  observation_codes?: string[];
  medication_codes?: string[];
  facility_space_id?: number | null;
  location_name?: string | null;
  location_category?: string | null;
  location_floor?: number | null;
  location_service_line?: string | null;
  position_ft?: Vector3Payload | null;
  position_m?: Vector3Payload | null;
  unit_code?: string | null;
  metadata?: Record<string, unknown>;
}

export type PatientFlowLocations = Record<string, PatientFlowLocation>;
export type PatientFlowTracks = Record<string, PatientFlowEvent[]>;

export interface AmbientSignalAdapterSummary {
  adapterId: number;
  adapterUuid: string;
  key: string;
  label: string;
  sourceType: string;
  enabled: boolean;
  baseConfidence: number;
  minimumRole?: string | null;
  capabilities: Record<string, unknown> | string[];
  eventCount: number;
}

export interface AmbientSignalEvent {
  ambientSignalEventId: number;
  eventUuid: string;
  adapterKey: string;
  adapterLabel: string;
  sourceType: string;
  signalType: string;
  occurredAtIso: string;
  locationCode?: string | null;
  facilitySpaceId?: number | null;
  confidenceScore: number;
  confidenceLevel: string;
  payload: Record<string, unknown>;
  linkedFlowEventId?: string | null;
}

export interface PatientFlowAmbientSummary {
  adapterCount: number;
  enabledAdapterCount: number;
  eventCount: number;
  averageConfidence: number;
  confidenceLevel: string;
}

export interface PatientFlowAmbient {
  generated_at: string;
  summary: PatientFlowAmbientSummary;
  adapters: AmbientSignalAdapterSummary[];
  events: AmbientSignalEvent[];
}

export interface PatientFlowStatePatient {
  patient_id: string;
  patient_display_id: string;
  encounter_id: string;
  location: string;
  event_type: string;
  patient_class?: string | null;
  service_line?: string | null;
  last_event_at: string;
  facility_space_id?: number | null;
  location_name?: string | null;
  location_floor?: number | null;
}

export interface PatientFlowState {
  asOf: string | null;
  activePatients: number;
  patients: PatientFlowStatePatient[];
  occupancy: Record<string, number>;
}

export interface PatientFlowFilters {
  floor: string;
  serviceLine: string;
  category: string;
  search: string;
}

export interface PatientLayerState {
  base: boolean;
  tokens: boolean;
  trails: boolean;
  heat: boolean;
}

export interface PatientVisibleState {
  patientId: string;
  event: PatientFlowEvent;
  position: { x: number; y: number; z: number };
  recent: PatientFlowEvent[];
}
