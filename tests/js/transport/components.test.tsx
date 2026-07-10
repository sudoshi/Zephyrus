import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { TransportRequestRow } from '@/Pages/Transport/components';
import type { TransportRequest } from '@/features/transport/types';

const request: TransportRequest = {
  transport_request_id: 12,
  request_uuid: 'request-12',
  request_type: 'inpatient',
  priority: 'urgent',
  status: 'assigned',
  patient_ref: 'SYN-TX-12',
  encounter_ref: 'SYN-ENC-12',
  origin: 'Medical ICU',
  destination: 'CT Scanner 2',
  transport_mode: 'stretcher',
  clinical_service: 'Critical Care',
  requested_by: 'operations-demo',
  requested_at: '2026-07-09T18:00:00Z',
  needed_at: '2026-07-09T18:30:00Z',
  assigned_at: '2026-07-09T18:05:00Z',
  dispatched_at: null,
  completed_at: null,
  assigned_team: 'Patient Transport',
  assigned_vendor: null,
  external_system: 'synthetic-operations-scenario',
  external_id: 'transport-12',
  segments: [],
  risk_flags: [],
  handoff: {},
  metadata: {},
  sla: {
    minutes_until_due: -61.516666666666666,
    at_risk: true,
    label: '61.516666666666666m overdue',
  },
};

describe('TransportRequestRow', () => {
  it('renders fractional SLA minutes as hours, minutes, and seconds', () => {
    render(<TransportRequestRow request={request} />);

    expect(screen.getByText('1 hr 1 min 31 sec overdue')).toBeInTheDocument();
    expect(screen.queryByText(/61\.516666/)).not.toBeInTheDocument();
  });
});
