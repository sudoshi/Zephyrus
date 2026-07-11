import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  assignTransportRequest,
  completeTransportHandoff,
  fetchTransportOverview,
  fetchTransportRequests,
  updateTransportStatus,
} from '@/features/transport/api';

vi.mock('axios');
const mocked = vi.mocked(axios, true);

const source = {
  key: 'prod.transport_operations',
  label: 'Transport operations data',
  status: 'stale',
  generated_at: '2026-07-09T12:00:00Z',
  last_observed_at: '2026-07-04T12:00:00Z',
  age_minutes: 7200,
  expected_cadence_minutes: 15,
  stale_after_minutes: 60,
  synthetic: true,
  message: 'Transport operations data is stale; the last observation was 7200 minutes ago.',
} as const;

const request = {
  transport_request_id: 22,
  request_uuid: '4ae0d480-e2c3-4fd6-8456-58a07188af62',
  request_type: 'inpatient',
  priority: 'urgent',
  status: 'assigned',
  patient_ref: 'sim-transport-1',
  encounter_ref: null,
  origin: '6 East',
  destination: 'CT 2',
  transport_mode: 'stretcher',
  clinical_service: 'Medicine',
  requested_by: 'demo-seeder',
  requested_at: '2026-07-04T12:00:00Z',
  needed_at: '2026-07-04T12:30:00Z',
  assigned_at: '2026-07-04T12:10:00Z',
  dispatched_at: null,
  completed_at: null,
  assigned_team: 'Summit Patient Transport',
  assigned_vendor: null,
  external_system: null,
  external_id: null,
  segments: [],
  risk_flags: [],
  handoff: {},
  handoff_required: true,
  handoff_evidence: null,
  active_assignment: {
    assignment_uuid: '0ce5ead6-2a5d-41f2-8141-b2ca75cd5961',
    resource_key: 'porter_pool',
    resource_type: 'team',
    resource_name: 'Summit Patient Transport',
    capacity_units: 1,
    reserved_from: '2026-07-04T12:10:00Z',
  },
  lifecycle_version: 2,
  allowed_transitions: ['dispatched', 'escalated', 'canceled', 'failed'],
  permissions: { can_assign: false, can_handoff: false },
  metadata: {},
  sla: { minutes_until_due: -7200, at_risk: true, label: '7200m overdue' },
} as const;

describe('transport operational API contract', () => {
  beforeEach(() => vi.clearAllMocks());

  it('parses production-shaped empty JSON maps in the worklist', async () => {
    mocked.get.mockResolvedValue({
      data: {
        data: [request],
        meta: { per_page: 25, count: 1, has_more: false, next_cursor: null, previous_cursor: null },
        links: { next: null, previous: null },
      },
    });

    const page = await fetchTransportRequests();

    expect(page.items[0].handoff).toEqual({});
    expect(page.items[0].metadata).toEqual({});
    expect(page.meta.has_more).toBe(false);
  });

  it('parses source freshness on the command overview', async () => {
    mocked.get.mockResolvedValue({
      data: {
        data: {
          source,
          metrics: { active: 1, at_risk: 1, completed_today: 0, stat: 0, transfer_backlog: 0, discharge_rides: 0, ems_inbound: 0 },
          by_type: { inpatient: 1 },
          by_status: { assigned: 1 },
          queue: [request],
          vendor_options: [],
          resource_options: [],
          measures: [],
        },
      },
    });

    const overview = await fetchTransportOverview();

    expect(overview.source.status).toBe('stale');
    expect(overview.queue).toHaveLength(1);
  });

  it('parses empty overview count maps without inventing queue data', async () => {
    mocked.get.mockResolvedValue({
      data: {
        data: {
          source: { ...source, status: 'missing', last_observed_at: null, age_minutes: null },
          metrics: { active: 0, at_risk: 0, completed_today: 0, stat: 0, transfer_backlog: 0, discharge_rides: 0, ems_inbound: 0 },
          by_type: {},
          by_status: {},
          queue: [],
          vendor_options: [],
          resource_options: [],
          measures: [],
        },
      },
    });

    const overview = await fetchTransportOverview();

    expect(overview.by_type).toEqual({});
    expect(overview.by_status).toEqual({});
    expect(overview.queue).toEqual([]);
  });

  it('rejects legacy array-shaped map fields', async () => {
    mocked.get.mockResolvedValue({
      data: {
        data: [{ ...request, handoff: [] }],
        meta: { per_page: 25, count: 1, has_more: false, next_cursor: null, previous_cursor: null },
        links: { next: null, previous: null },
      },
    });

    await expect(fetchTransportRequests()).rejects.toThrow();
  });

  it('sends idempotency headers for assignment, lifecycle, and structured handoff commands', async () => {
    mocked.post.mockResolvedValue({ data: { data: request } });

    await assignTransportRequest(22, { resource_key: 'porter_pool' }, 'web-assign-22');
    await updateTransportStatus(22, 'dispatched', undefined, 'web-dispatch-22');
    await completeTransportHandoff(22, {
      handoff_to: 'CT Charge RN',
      receiver_role: 'charge_nurse',
      acceptance_status: 'accepted',
    }, 'web-handoff-22');

    expect(mocked.post).toHaveBeenNthCalledWith(
      1,
      '/api/transport/requests/22/assign',
      { resource_key: 'porter_pool' },
      { headers: { 'Idempotency-Key': 'web-assign-22' } },
    );
    expect(mocked.post).toHaveBeenNthCalledWith(
      2,
      '/api/transport/requests/22/status',
      { status: 'dispatched', reason: undefined },
      { headers: { 'Idempotency-Key': 'web-dispatch-22' } },
    );
    expect(mocked.post).toHaveBeenNthCalledWith(
      3,
      '/api/transport/requests/22/handoff',
      expect.objectContaining({ receiver_role: 'charge_nurse', acceptance_status: 'accepted' }),
      { headers: { 'Idempotency-Key': 'web-handoff-22' } },
    );
  });
});
