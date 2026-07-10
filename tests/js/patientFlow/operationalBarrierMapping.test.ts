import { describe, expect, it } from 'vitest';
import { mapOccupancyTimer } from '@/features/patientFlowNavigator/api';
import { occupancyInspectorData } from '@/features/patientFlowNavigator/inspector';
import type { OccupancyInsight } from '@/features/patientFlowNavigator/types';

describe('Patient Flow operational barrier mapping', () => {
  it('preserves verification and source-record provenance for marker details', () => {
    const timer = mapOccupancyTimer({
      kind: 'next_transport',
      label: 'Transport request overdue',
      due_at: '2026-06-25T01:40:00Z',
      minutes_remaining: -61.516666666666666,
      status: 'delayed',
      source: 'prod.transport_requests',
      barrier_code: 'transport_request_overdue',
      classification: 'verified_barrier',
      verified: true,
      verification: {
        status: 'verified',
        assertion: 'active_and_overdue_as_of',
        source_status: 'assigned',
        matched_by: 'encounter_ref',
      },
      provenance: {
        source_table: 'prod.transport_requests',
        source_record_id: '42',
        record_type: 'transport_delay',
      },
    });

    expect(timer).toMatchObject({
      verified: true,
      classification: 'verified_barrier',
      verification: {
        status: 'verified',
        assertion: 'active_and_overdue_as_of',
        sourceStatus: 'assigned',
        matchedBy: 'encounter_ref',
      },
      provenance: {
        sourceTable: 'prod.transport_requests',
        sourceRecordId: '42',
        recordType: 'transport_delay',
      },
    });

    const insight: OccupancyInsight = {
      key: 'patient:bed',
      location: 'TICU-B001',
      position: { x: 0, y: 0, z: 0 },
      stayMinutes: 90.50833333333334,
      primaryStatus: 'delayed',
      timers: [timer],
      blockers: ['Transport request overdue'],
    };
    const detail = occupancyInspectorData(insight);

    expect(detail.stay_duration).toBe('1 hr 30 min 31 sec');

    expect(detail.timers).toEqual([
      expect.objectContaining({
        verified: true,
        verification_status: 'verified',
        match_basis: 'encounter_ref',
        source_table: 'prod.transport_requests',
        source_record: '42',
        source_record_type: 'transport_delay',
        time_to_target: '1 hr 1 min 31 sec overdue',
      }),
    ]);
  });

  it('keeps elapsed duration risk explicitly non-verified', () => {
    const timer = mapOccupancyTimer({
      kind: 'stay',
      label: 'Stay',
      due_at: null,
      minutes_remaining: null,
      status: 'delayed',
      source: 'elapsed occupancy',
      barrier_code: null,
      risk_code: 'long_stay_capacity_risk',
      classification: 'duration_risk',
      verified: false,
      verification: {
        status: 'inferred',
        assertion: 'elapsed_duration_threshold',
        matched_by: 'occupancy_elapsed_time',
      },
    });

    expect(timer.barrierCode).toBeNull();
    expect(timer.riskCode).toBe('long_stay_capacity_risk');
    expect(timer.classification).toBe('duration_risk');
    expect(timer.verified).toBe(false);
    expect(timer.verification?.status).toBe('inferred');
  });
});
