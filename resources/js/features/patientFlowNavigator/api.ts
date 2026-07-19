import axios from 'axios';
import type {
  PatientFlowAmbient,
  OccupancyEddyContext,
  PatientFlowBarriersResponse,
  PatientFlowEvent,
  PatientFlowLocations,
  OccupancyInsight,
  OccupancySummary,
  OccupancyTimer,
  PatientFlowProjectionsResponse,
  PatientFlowState,
  PatientFlowSummary,
  PatientFlowTracks,
} from './types';

export interface PatientFlowEventQuery {
  from?: string;
  to?: string;
  patient?: string;
  category?: string;
  service_line?: string;
  floor?: string;
  limit?: number;
  /** F-1 ruling: forward the page persona so EnforceFlowLens resolves the
   * SAME lens the page was rendered with, never the caller's default. */
  persona?: string;
}

export async function fetchPatientFlowSummary(persona?: string): Promise<PatientFlowSummary> {
  const response = await axios.get<PatientFlowSummary>('/api/patient-flow/summary', {
    params: persona ? { persona } : {},
  });
  return response.data;
}

export async function fetchPatientFlowLocations(persona?: string): Promise<PatientFlowLocations> {
  const response = await axios.get<PatientFlowLocations>('/api/patient-flow/locations', {
    params: persona ? { persona } : {},
  });
  return response.data;
}

// Open operational barriers overlay. Aggregate + patient-free, so no lens is
// required; pass a unit_id to scope to one unit.
export async function fetchPatientFlowBarriers(unitId?: number): Promise<PatientFlowBarriersResponse> {
  const response = await axios.get<PatientFlowBarriersResponse>('/api/patient-flow/barriers', {
    params: { unit_id: unitId },
  });
  return response.data;
}

export async function fetchPatientFlowEvents(query: PatientFlowEventQuery = {}): Promise<PatientFlowEvent[]> {
  const response = await axios.get<PatientFlowEvent[]>('/api/patient-flow/events', { params: query });
  return response.data;
}

export async function fetchPatientFlowTracks(query: PatientFlowEventQuery = {}): Promise<PatientFlowTracks> {
  const response = await axios.get<PatientFlowTracks>('/api/patient-flow/tracks', { params: query });
  return response.data;
}

export async function fetchPatientFlowState(asOf?: string): Promise<PatientFlowState> {
  const response = await axios.get<PatientFlowState>('/api/patient-flow/state', {
    params: asOf ? { asOf } : {},
  });
  return response.data;
}

export async function fetchPatientFlowAmbient(persona?: string): Promise<PatientFlowAmbient> {
  const response = await axios.get<PatientFlowAmbient>('/api/patient-flow/ambient', {
    params: persona ? { persona } : {},
  });
  return response.data;
}

export interface RawOccupancyTimer {
  kind: OccupancyTimer['kind'];
  label: string;
  due_at: string | null;
  minutes_remaining: number | null;
  status: OccupancyTimer['status'];
  source: string;
  reason?: string | null;
  barrier_code?: string | null;
  barrier_label?: string | null;
  barrier_category?: string | null;
  owner_role?: string | null;
  blocks?: string | null;
  impact?: string | null;
  rtdc_metrics?: string[];
  eddy_summary?: string | null;
  recommended_focus?: string | null;
  source_reason_code?: string | null;
  owner?: string | null;
  risk_code?: string | null;
  risk_label?: string | null;
  risk_category?: string | null;
  classification?: OccupancyTimer['classification'];
  verified?: boolean;
  verification?: {
    status: string;
    assertion?: string | null;
    source_status?: string | null;
    asserted_at?: string | null;
    observed_at?: string | null;
    matched_by?: string | null;
  };
  provenance?: {
    source_table?: string | null;
    source_record_id?: string | null;
    record_type?: string | null;
  };
}

interface RawOccupancyInsight {
  key: string;
  location: string;
  location_name?: string | null;
  unit_code?: string | null;
  service_line?: string | null;
  patient_display_id?: string | null;
  patient_id?: string | null;
  encounter_id?: string | null;
  patient_context_ref?: string | null;
  position_m?: { x: number; y?: number; z: number } | null;
  stay_minutes: number;
  arrived_at?: string | null;
  came_from?: string | null;
  next_move?: string | null;
  next_move_at?: string | null;
  primary_status: OccupancyInsight['primaryStatus'];
  timers: RawOccupancyTimer[];
  blockers: string[];
  barrier_reasons?: string[];
  barrier_codes?: string[];
  barrier_labels?: string[];
  owner_roles?: string[];
  delay_impacts?: string[];
  rtdc_metrics?: string[];
  eddy_summaries?: string[];
  barrier_owner_map?: Record<string, { label?: string | null; owner_role?: string | null }>;
}

interface RawOccupancySummary {
  active: number;
  delayed: number;
  watch: number;
  transport_delays: number;
  evs_delays: number;
  ready_to_move: number;
  duration_risks?: number;
  verified_barriers?: number;
  avg_stay_minutes: number;
  service_lines: Array<{
    service_line: string;
    occupied: number;
    delayed: number;
    watch: number;
    avg_stay_minutes: number;
  }>;
  persona: {
    transport: number;
    evs: number;
    bed_manager: number;
    capacity: number;
  };
  top_barriers?: Array<{
    barrier_code?: string | null;
    label: string;
    reason?: string | null;
    owner_role?: string | null;
    barrier_category?: string | null;
    rtdc_metrics?: string[];
    eddy_summary?: string | null;
    recommended_focus?: string | null;
    verified_count?: number;
    sources?: string[];
    count: number;
    service_lines: string[];
  }>;
}

interface RawOccupancyResponse {
  asOf: string;
  occupancy: RawOccupancyInsight[];
  summary: RawOccupancySummary;
  eddy_context?: OccupancyEddyContext;
}

export function mapOccupancyTimer(timer: RawOccupancyTimer): OccupancyTimer {
  return {
    kind: timer.kind,
    label: timer.label,
    dueAt: timer.due_at,
    minutesRemaining: timer.minutes_remaining,
    status: timer.status,
    source: timer.source,
    reason: timer.reason,
    barrierCode: timer.barrier_code,
    barrierLabel: timer.barrier_label,
    barrierCategory: timer.barrier_category,
    ownerRole: timer.owner_role,
    blocks: timer.blocks,
    impact: timer.impact,
    rtdcMetrics: timer.rtdc_metrics ?? [],
    eddySummary: timer.eddy_summary,
    recommendedFocus: timer.recommended_focus,
    sourceReasonCode: timer.source_reason_code,
    owner: timer.owner,
    riskCode: timer.risk_code,
    riskLabel: timer.risk_label,
    riskCategory: timer.risk_category,
    classification: timer.classification,
    verified: timer.verified ?? false,
    verification: timer.verification
      ? {
          status: timer.verification.status,
          assertion: timer.verification.assertion,
          sourceStatus: timer.verification.source_status,
          assertedAt: timer.verification.asserted_at,
          observedAt: timer.verification.observed_at,
          matchedBy: timer.verification.matched_by,
        }
      : undefined,
    provenance: timer.provenance
      ? {
          sourceTable: timer.provenance.source_table,
          sourceRecordId: timer.provenance.source_record_id,
          recordType: timer.provenance.record_type,
        }
      : undefined,
  };
}

function mapOccupancy(item: RawOccupancyInsight): OccupancyInsight | null {
  if (!item.position_m) return null;
  return {
    key: item.key,
    location: item.location,
    locationName: item.location_name,
    unitCode: item.unit_code,
    serviceLine: item.service_line,
    patientDisplayId: item.patient_display_id,
    patientId: item.patient_id,
    encounterId: item.encounter_id,
    patientContextRef: item.patient_context_ref,
    position: { x: item.position_m.x, y: (item.position_m.y ?? 0) + 1.7, z: item.position_m.z },
    stayMinutes: item.stay_minutes,
    arrivedAt: item.arrived_at,
    cameFrom: item.came_from,
    nextMove: item.next_move,
    nextMoveAt: item.next_move_at,
    primaryStatus: item.primary_status,
    timers: item.timers.map(mapOccupancyTimer),
    blockers: item.blockers,
    barrierReasons: item.barrier_reasons ?? [],
    barrierCodes: item.barrier_codes ?? [],
    barrierLabels: item.barrier_labels ?? [],
    ownerRoles: item.owner_roles ?? [],
    delayImpacts: item.delay_impacts ?? [],
    rtdcMetrics: item.rtdc_metrics ?? [],
    eddySummaries: item.eddy_summaries ?? [],
    barrierOwnerMap: Object.fromEntries(
      Object.entries(item.barrier_owner_map ?? {}).map(([code, owner]) => [
        code,
        { label: owner.label, ownerRole: owner.owner_role },
      ]),
    ),
  };
}

function mapSummary(summary: RawOccupancySummary): OccupancySummary {
  return {
    active: summary.active,
    delayed: summary.delayed,
    watch: summary.watch,
    transportDelays: summary.transport_delays,
    evsDelays: summary.evs_delays,
    readyToMove: summary.ready_to_move,
    durationRisks: summary.duration_risks ?? 0,
    verifiedBarriers: summary.verified_barriers ?? 0,
    avgStayMinutes: summary.avg_stay_minutes,
    serviceLines: summary.service_lines.map((item) => ({
      serviceLine: item.service_line,
      occupied: item.occupied,
      delayed: item.delayed,
      watch: item.watch,
      avgStayMinutes: item.avg_stay_minutes,
    })),
    persona: {
      transport: summary.persona.transport,
      evs: summary.persona.evs,
      bedManager: summary.persona.bed_manager,
      capacity: summary.persona.capacity,
    },
    topBarriers: (summary.top_barriers ?? []).map((item) => ({
      barrierCode: item.barrier_code,
      label: item.label,
      reason: item.reason,
      ownerRole: item.owner_role,
      barrierCategory: item.barrier_category,
      rtdcMetrics: item.rtdc_metrics ?? [],
      eddySummary: item.eddy_summary,
      recommendedFocus: item.recommended_focus,
      verifiedCount: item.verified_count ?? 0,
      sources: item.sources ?? [],
      count: item.count,
      serviceLines: item.service_lines,
    })),
  };
}

export async function fetchPatientFlowOccupancy(query: PatientFlowEventQuery & { asOf?: string; include?: string } = {}): Promise<{
  asOf: string;
  occupancy: OccupancyInsight[];
  summary: OccupancySummary;
  eddyContext?: OccupancyEddyContext;
}> {
  const response = await axios.get<RawOccupancyResponse>('/api/patient-flow/occupancy', { params: query });
  return {
    asOf: response.data.asOf,
    occupancy: response.data.occupancy.map(mapOccupancy).filter((item): item is OccupancyInsight => item !== null),
    summary: mapSummary(response.data.summary),
    eddyContext: response.data.eddy_context,
  };
}

/**
 * The +24h projection stream for the ghost layer (FLOW-WINDOW-PLAN §7.3).
 * Persona is optional: when set it is forwarded so EnforceFlowLens resolves
 * the same lens the page was rendered with; the server clamps kinds and
 * redacts identity regardless.
 */
export async function fetchPatientFlowProjections(
  options: { persona?: string } = {},
): Promise<PatientFlowProjectionsResponse> {
  const response = await axios.get<PatientFlowProjectionsResponse>('/api/patient-flow/projections', {
    params: options.persona ? { persona: options.persona } : {},
  });
  return response.data;
}

export async function fetchPatientFlowFhirBundle(eventId: string): Promise<Record<string, unknown>> {
  const response = await axios.get<Record<string, unknown>>('/api/patient-flow/fhir/bundle', {
    params: { event_id: eventId },
  });
  return response.data;
}

export function createPatientFlowEventSource(
  options: { replay?: number; interval?: number; persona?: string } = {},
): EventSource {
  const params = new URLSearchParams();
  if (options.replay) params.set('replay', String(options.replay));
  if (options.interval) params.set('interval', String(options.interval));
  if (options.persona) params.set('persona', options.persona);
  const suffix = params.toString() ? `?${params.toString()}` : '';

  return new EventSource(`/api/patient-flow/stream/adt${suffix}`);
}
