export interface Vector3Payload {
  x: number;
  y?: number;
  z: number;
  level?: number;
}

export type PatientFlowFreshness = 'fresh' | 'stale' | 'missing' | 'degraded';

export interface PatientFlowSource {
  mode: 'live' | 'synthetic' | 'seeded' | 'derived' | 'fallback';
  system: string;
  scenario_id: string | null;
  generated_at: string;
  last_event_at: string | null;
  expected_cadence_seconds: number;
  freshness: PatientFlowFreshness;
  stale_after_seconds: number;
  lineage: string[];
}

export interface PatientFlowDataExtent {
  first_event_at: string | null;
  last_event_at: string | null;
  event_count: number;
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
  source: PatientFlowSource;
  data_extent: PatientFlowDataExtent;
  suggested_initial_time: string | null;
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
  /** Projection ghost layer (future half of the 48h window). */
  ghosts: boolean;
}

export interface PatientVisibleState {
  patientId: string;
  event: PatientFlowEvent;
  position: { x: number; y: number; z: number };
  recent: PatientFlowEvent[];
  arrivedAt: string;
  cameFrom?: string | null;
  nextEvent?: PatientFlowEvent | null;
}

export type OccupancyTimerKind = 'stay' | 'arrival_transport' | 'next_transport' | 'evs' | 'readiness';
export type OccupancyTimerStatus = 'ok' | 'watch' | 'delayed';
export type OccupancyTimerClassification = 'duration_risk' | 'projected_risk' | 'projected_barrier' | 'verified_barrier';

export interface OccupancyTimerVerification {
  status: 'verified' | 'inferred' | 'unverified' | string;
  assertion?: string | null;
  sourceStatus?: string | null;
  assertedAt?: string | null;
  observedAt?: string | null;
  matchedBy?: string | null;
}

export interface OccupancyTimerProvenance {
  sourceTable?: string | null;
  sourceRecordId?: string | null;
  recordType?: string | null;
}

export interface OccupancyTimer {
  kind: OccupancyTimerKind;
  label: string;
  dueAt: string | null;
  minutesRemaining: number | null;
  status: OccupancyTimerStatus;
  source: string;
  reason?: string | null;
  barrierCode?: string | null;
  barrierLabel?: string | null;
  barrierCategory?: string | null;
  ownerRole?: string | null;
  blocks?: string | null;
  impact?: string | null;
  rtdcMetrics?: string[];
  eddySummary?: string | null;
  recommendedFocus?: string | null;
  sourceReasonCode?: string | null;
  owner?: string | null;
  riskCode?: string | null;
  riskLabel?: string | null;
  riskCategory?: string | null;
  classification?: OccupancyTimerClassification;
  verified?: boolean;
  verification?: OccupancyTimerVerification;
  provenance?: OccupancyTimerProvenance;
}

export interface OccupancyInsight {
  key: string;
  location: string;
  locationName?: string | null;
  unitCode?: string | null;
  serviceLine?: string | null;
  patientDisplayId?: string | null;
  patientId?: string | null;
  encounterId?: string | null;
  patientContextRef?: string | null;
  position: { x: number; y: number; z: number };
  stayMinutes: number;
  arrivedAt?: string | null;
  cameFrom?: string | null;
  nextMove?: string | null;
  nextMoveAt?: string | null;
  primaryStatus: OccupancyTimerStatus;
  timers: OccupancyTimer[];
  blockers: string[];
  barrierReasons?: string[];
  barrierCodes?: string[];
  barrierLabels?: string[];
  ownerRoles?: string[];
  delayImpacts?: string[];
  rtdcMetrics?: string[];
  eddySummaries?: string[];
  barrierOwnerMap?: Record<string, { label?: string | null; ownerRole?: string | null }>;
}

export interface OccupancyServiceLineSummary {
  serviceLine: string;
  occupied: number;
  delayed: number;
  watch: number;
  avgStayMinutes: number;
}

export interface OccupancyPersonaSummary {
  transport: number;
  evs: number;
  bedManager: number;
  capacity: number;
}

export interface OccupancySummary {
  active: number;
  delayed: number;
  watch: number;
  transportDelays: number;
  evsDelays: number;
  readyToMove: number;
  durationRisks?: number;
  verifiedBarriers?: number;
  avgStayMinutes: number;
  serviceLines: OccupancyServiceLineSummary[];
  persona: OccupancyPersonaSummary;
  topBarriers?: Array<{
    barrierCode?: string | null;
    label: string;
    reason?: string | null;
    ownerRole?: string | null;
    barrierCategory?: string | null;
    rtdcMetrics?: string[];
    eddySummary?: string | null;
    recommendedFocus?: string | null;
    verifiedCount?: number;
    sources?: string[];
    count: number;
    serviceLines: string[];
  }>;
}

export interface OccupancyEddyContext {
  surface: 'patient_flow_4d';
  role: {
    id: string;
    patient_dots: FlowPatientDots;
    scope_default: string;
  };
  scope: {
    type: string;
    filters: {
      floor?: string | null;
      service_line?: string | null;
      category?: string | null;
    };
  };
  as_of: string;
  redaction: {
    patient_dots: FlowPatientDots;
    patient_identifiers_included: boolean;
    aggregate_only: boolean;
  };
  current_metrics: Record<string, unknown>;
  affected_service_lines: OccupancyServiceLineSummary[];
  top_barriers: Array<{
    barrier_code?: string | null;
    label: string;
    category?: string | null;
    reason?: string | null;
    owner_role?: string | null;
    count: number;
    service_lines: string[];
    rtdc_metrics: string[];
    eddy_summary?: string | null;
    recommended_focus?: string | null;
  }>;
  barrier_owner_map: Record<string, { label?: string | null; owner_role?: string | null; count: number }>;
  recommended_focus_areas: string[];
  source_lineage: {
    timer_sources: Record<string, number>;
    demo_scenario?: Record<string, unknown> | null;
    generated_from: string;
  };
  action_allowlist: string[];
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
  timer_kind?: OccupancyTimerKind | null;
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
  reason?: string | null;
  barrier_code?: string | null;
  owner_role?: string | null;
  blocks?: string | null;
  impact?: string | null;
  _patient_ref?: string | null;
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
