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
  open_barriers?: number;
  ambient_signals?: number;
  ambient_confidence?: number;
  ambient_confidence_level?: string;
  facility_code: string;
  model_url: string;
  tileset_url?: string;
  generated_at: string;
}

// An open operational barrier for the Navigator overlay (GET /api/patient-flow/barriers).
// unit_id anchors it to a unit's location centroid; a null unit_id is house-level.
// encounter_ref stays lens-redacted (null) until the lens-aware follow-up lands.
export interface NavigatorBarrier {
  barrier_id: number;
  unit_id: number | null;
  unit_label: string | null;
  category: 'medical' | 'logistical' | 'placement' | 'social';
  reason_code: string | null;
  description: string | null;
  owner: string | null;
  status: 'open' | 'resolved';
  opened_at: string | null;
  encounter_ref: string | null;
}

export interface PatientFlowBarriersResponse {
  generated_at: string;
  count: number;
  open_barriers: NavigatorBarrier[];
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
  /** Projection ghost layer (future half of the 48h window). */
  ghosts: boolean;
}

export interface PatientVisibleState {
  patientId: string;
  event: PatientFlowEvent;
  position: { x: number; y: number; z: number };
  recent: PatientFlowEvent[];
}

// ---------------------------------------------------------------------------
// 48h Flow Window — projections + persona lens (FLOW-WINDOW-PLAN §7.3)
// ---------------------------------------------------------------------------

export type ProjectionKind =
  | 'expected_discharge'
  | 'predicted_census'
  | 'predicted_arrivals'
  | 'scheduled_or_case'
  | 'transport_due'
  | 'evs_due'
  | 'staffing_shift_gap'
  | 'surge_probability';

export type ProjectionConfidence = 'definite' | 'probable' | 'possible';

export interface ProjectionProvenance {
  service: string;
  reliability: number | null;
}

export interface ProjectionEntity {
  type: string;
  ref: string;
}

export interface ProjectionItem {
  t: string;
  kind: ProjectionKind;
  confidence: ProjectionConfidence;
  unit_id: number | null;
  bed_id: number | null;
  room: string | null;
  entity: ProjectionEntity | null;
  patient_context_ref: string | null;
  label: string;
  value: number | null;
  band: { lower: number; upper: number } | null;
  ends_at: string | null;
  derived: boolean;
  provenance: ProjectionProvenance;
}

export interface PatientFlowProjectionsResponse {
  window: { from: string; to: string };
  lens: { role_id: string; projection_kinds: string[]; patient_dots: string };
  projections: ProjectionItem[];
  generated_at: string;
}

export type FlowPatientDots = 'full' | 'unit' | 'task' | 'none';

/** Resolved persona lens (config/hummingbird/flow_lens.php), passed as an Inertia prop. */
export interface FlowLens {
  role_id: string;
  scope_default: string;
  scopes_allowed: string[];
  layers: string[];
  event_kinds: string[];
  projection_kinds: string[];
  patient_dots: FlowPatientDots;
  actions: string[];
  default_zoom_hours: number;
}

/** unit_id ↔ unit_code ↔ floor bridge for joining projections to /locations. */
export interface FlowUnitSummary {
  unit_id: number;
  unit_code: string | null;
  name: string | null;
  floor: number | null;
}
