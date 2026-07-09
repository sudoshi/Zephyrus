import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fetchStaffingOverview, fetchStaffingRequests, fetchStaffingWorkforce } from '@/features/staffing/api';

vi.mock('axios');
const mocked = vi.mocked(axios, true);

const source = {
  key: 'prod.staffing_operations',
  label: 'Staffing operations data',
  status: 'stale',
  generated_at: '2026-07-09T12:00:00Z',
  last_observed_at: '2026-07-04T12:00:00Z',
  age_minutes: 7200,
  expected_cadence_minutes: 60,
  stale_after_minutes: 240,
  synthetic: true,
  message: 'Staffing operations data is stale; the last observation was 7200 minutes ago.',
} as const;

const request = {
  staffing_request_id: 1,
  request_uuid: 'a5c201d8-7003-4423-bc5d-ce594a169cb3',
  unit_id: 7,
  unit_label: '6 East',
  staffing_plan_id: null,
  role: 'rn',
  role_label: 'Registered Nurse',
  shift_date: '2026-07-04',
  shift: 'day',
  request_type: 'fill_gap',
  priority: 'urgent',
  status: 'open',
  headcount_needed: 2,
  hours_needed: 12,
  requested_by: 'demo-seeder',
  needed_by: '2026-07-04T14:00:00Z',
  assigned_at: null,
  filled_at: null,
  completed_at: null,
  assigned_source: null,
  assigned_staff_ref: null,
  owner_name: 'Staffing office',
  risk_flags: [],
  resolution_payload: {},
  metadata: {},
  is_synthetic: true,
  freshness_status: 'expired',
  sla: { minutes_until_due: null, at_risk: false, label: 'Expired synthetic request' },
} as const;

describe('staffing operational API contract', () => {
  beforeEach(() => vi.clearAllMocks());

  it('parses production-shaped empty JSON maps and unknown coverage', async () => {
    mocked.get.mockResolvedValue({
      data: {
        data: {
          source,
          metrics: {
            open_requests: 1,
            at_risk_units: 0,
            critical_gaps: 0,
            unfilled_requests: 0,
            total_gap_headcount: 0,
            coverage_pct: null,
            stat_requests: 0,
          },
          coverage: {
            required_count: 0,
            available_count: 0,
            total_gap_headcount: 0,
            coverage_pct: null,
            below_minimum_safe: 0,
          },
          workforce: {
            available: true,
            metrics: {
              total_members: 0,
              active_members: 0,
              inactive_members: 0,
              active_fte: 0,
              role_count: 0,
              unit_count: 0,
              hospital_wide_members: 0,
              synthetic_members: 0,
              credential_attention: 0,
              unavailable_members: 0,
            },
            by_role: [],
            by_employment: [],
            by_shift: [
              { shift: 'day', label: 'Day', count: 0 },
              { shift: 'evening', label: 'Evening', count: 0 },
              { shift: 'night', label: 'Night', count: 0 },
            ],
            assumptions: null,
          },
          units_at_risk: [],
          by_role: [],
          queue: [request],
          resource_options: [{ key: 'float_pool', name: 'Float Pool', type: 'internal_pool', available: null }],
        },
      },
    });

    const overview = await fetchStaffingOverview();

    expect(overview.coverage.coverage_pct).toBeNull();
    expect(overview.queue[0].metadata).toEqual({});
    expect(overview.queue[0].freshness_status).toBe('expired');
    expect(overview.resource_options[0].available).toBeNull();
  });

  it('parses the paginated canonical workforce directory', async () => {
    mocked.get.mockResolvedValue({
      data: {
        data: [{
          staff_member_id: 42,
          display_name: 'Avery A. Adams',
          role_code: 'critical_care_nurse',
          role_label: 'Critical Care Nurse',
          role_category: 'nursing',
          unit_id: 3,
          unit_label: 'Medical ICU',
          service_line_code: 'critical_care',
          employee_type: 'employed',
          employment_class: 'full_time',
          fte: 1,
          coverage_model: 'in_house',
          preferred_shift: 'night',
          availability: 'available',
          credential_status: 'valid',
          credentials: ['RN', 'BLS'],
          eligible_float_units: ['Medical ICU', 'Surgical ICU'],
          is_active: true,
          is_synthetic: true,
        }],
        meta: { current_page: 1, last_page: 4, per_page: 25, total: 87 },
      },
    });

    const directory = await fetchStaffingWorkforce({ role: 'critical_care_nurse', shift: 'night' });

    expect(directory.meta.total).toBe(87);
    expect(directory.data[0]).toMatchObject({ role_code: 'critical_care_nurse', preferred_shift: 'night' });
  });

  it('keeps map contracts strict instead of accepting legacy empty arrays', async () => {
    mocked.get.mockResolvedValue({ data: { data: [{ ...request, metadata: [] }] } });

    await expect(fetchStaffingRequests()).rejects.toThrow();
  });
});
