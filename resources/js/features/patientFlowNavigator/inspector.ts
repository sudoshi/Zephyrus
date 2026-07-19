import type { OccupancyInsight, PatientVisibleState } from './types';
import { formatDurationMinutes, formatRelativeDurationMinutes } from '@/lib/duration';

/**
 * Patient-token payload — ONE builder for the scene's mesh userData and the
 * non-pointer selection path (search list / feed), so the inspector shows the
 * same fields regardless of how the patient was selected (H1.2/H1.3).
 */
export function patientTokenInspectorData(
  state: PatientVisibleState,
  redactIdentity: boolean,
): Record<string, unknown> {
  return {
    kind: 'patient-token',
    ...(redactIdentity
      ? {}
      : {
          patient_id: state.patientId,
          patient_display_id: state.event.patient_display_id,
          encounter_id: state.event.encounter_id,
        }),
    current_location: state.event.to_location,
    service_line: state.event.service_line,
    event_type: state.event.event_type,
    event_category: state.event.event_category,
    last_event_at: state.event.occurred_at,
    recent_event_count: state.recent.length,
  };
}

export function occupancyInspectorData(insight: OccupancyInsight): Record<string, unknown> {
  return {
    kind: 'occupancy-delay-detail',
    location: insight.location,
    location_name: insight.locationName,
    unit: insight.unitCode,
    service_line: insight.serviceLine,
    patient_display_id: insight.patientDisplayId,
    patient_id: insight.patientId,
    encounter_id: insight.encounterId,
    status: insight.primaryStatus,
    stay_duration: formatDurationMinutes(insight.stayMinutes),
    arrived_at: insight.arrivedAt,
    came_from: insight.cameFrom,
    next_move: insight.nextMove,
    next_move_at: insight.nextMoveAt,
    barrier_codes: insight.barrierCodes?.join(', '),
    barrier_reasons: insight.barrierReasons?.join(' | '),
    barrier_owners: insight.ownerRoles?.join(', '),
    delay_impacts: insight.delayImpacts?.join(' | '),
    blockers: insight.blockers.join(', '),
    timers: insight.timers.map((timer) => ({
      label: timer.label,
      status: timer.status,
      due_at: timer.dueAt,
      time_to_target: timer.minutesRemaining === null
        ? 'No target'
        : formatRelativeDurationMinutes(timer.minutesRemaining),
      source: timer.source,
      reason: timer.reason,
      owner_role: timer.ownerRole,
      classification: timer.classification,
      verified: timer.verified ?? false,
      verification_status: timer.verification?.status,
      verification_assertion: timer.verification?.assertion,
      match_basis: timer.verification?.matchedBy,
      source_table: timer.provenance?.sourceTable,
      source_record: timer.provenance?.sourceRecordId,
      source_record_type: timer.provenance?.recordType,
      risk_code: timer.riskCode,
    })),
  };
}
