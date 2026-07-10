import type { OccupancyInsight } from './types';
import { formatDurationMinutes, formatRelativeDurationMinutes } from '@/lib/duration';

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
