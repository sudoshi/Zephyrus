import axios from 'axios';
import { z } from 'zod';
import { sourceFreshnessSchema } from '@/features/operations/sourceFreshness';
import type {
  AssignStaffingRequestInput,
  CreateStaffingRequestInput,
  OfferStaffingFulfillmentInput,
  StaffingCandidatePage,
  StaffingFulfillment,
  StaffingFulfillmentStatus,
  StaffingOverview,
  StaffingRequest,
  StaffingRequestStatus,
  StaffingWorkforceDirectory,
  StaffingWorkforceFilters,
} from './types';

const slaSchema = z.object({
  minutes_until_due: z.number().nullable(),
  at_risk: z.boolean(),
  label: z.string(),
});

const roleEnum = z.enum(['rn', 'lpn', 'tech', 'charge', 'provider', 'respiratory', 'unit_secretary']);
const shiftEnum = z.enum(['day', 'evening', 'night']);
const fulfillmentStatusEnum = z.enum(['offered', 'accepted', 'filled', 'released', 'canceled']);

const fulfillmentSchema = z.object({
  fulfillment_uuid: z.string().uuid(),
  staffing_request_id: z.number(),
  staff_member_id: z.number(),
  staff_member_name: z.string(),
  status: fulfillmentStatusEnum,
  source: z.enum(['float_pool', 'overtime', 'agency', 'on_call']),
  version: z.number(),
  role_code: z.string().nullable(),
  unit_id: z.number().nullable(),
  starts_at: z.string().nullable(),
  ends_at: z.string().nullable(),
  timezone: z.string().nullable(),
  validation: z.record(z.string(), z.unknown()),
  offered_at: z.string().nullable(),
  accepted_at: z.string().nullable(),
  filled_at: z.string().nullable(),
  released_at: z.string().nullable(),
  canceled_at: z.string().nullable(),
  actions: z.object({
    can_accept: z.boolean(), can_fill: z.boolean(), can_release: z.boolean(), can_cancel: z.boolean(),
  }),
});

const planSchema = z.object({
  staffing_plan_id: z.number(),
  plan_uuid: z.string(),
  unit_id: z.number().nullable(),
  unit_label: z.string(),
  role: roleEnum,
  role_label: z.string(),
  shift_date: z.string().nullable(),
  shift: shiftEnum,
  required_count: z.number(),
  scheduled_count: z.number(),
  actual_count: z.number(),
  minimum_safe_count: z.number(),
  census: z.number(),
  ratio_target: z.number().nullable(),
  gap_headcount: z.number(),
  below_minimum_safe: z.boolean(),
  status: z.string(),
  notes: z.string().nullable(),
  constraints: z.record(z.string(), z.unknown()),
});

const requestSchema = z.object({
  staffing_request_id: z.number(),
  request_uuid: z.string(),
  unit_id: z.number().nullable(),
  unit_label: z.string(),
  staffing_plan_id: z.number().nullable(),
  role: roleEnum,
  role_label: z.string(),
  shift_date: z.string().nullable(),
  shift: shiftEnum,
  request_type: z.enum(['fill_gap', 'float', 'overtime', 'agency', 'on_call', 'reassign']),
  priority: z.enum(['routine', 'urgent', 'stat']),
  status: z.enum(['requested', 'open', 'sourcing', 'assigned', 'filled', 'completed', 'canceled', 'escalated', 'unfilled']),
  headcount_needed: z.number(),
  hours_needed: z.number().nullable(),
  requested_by: z.string().nullable(),
  needed_by: z.string().nullable(),
  assigned_at: z.string().nullable(),
  filled_at: z.string().nullable(),
  completed_at: z.string().nullable(),
  assigned_source: z.enum(['float_pool', 'overtime', 'agency', 'on_call']).nullable(),
  assigned_staff_ref: z.string().nullable(),
  owner_name: z.string().nullable(),
  risk_flags: z.array(z.unknown()),
  resolution_payload: z.record(z.string(), z.unknown()),
  metadata: z.record(z.string(), z.unknown()),
  is_synthetic: z.boolean(),
  freshness_status: z.enum(['current', 'stale', 'expired']),
  sla: slaSchema,
  fulfillment: z.object({
    available: z.boolean(),
    state: z.enum(['unconfigured', 'unfilled', 'offer_pending', 'partially_fulfilled', 'filled']),
    offered_count: z.number(),
    accepted_count: z.number(),
    filled_count: z.number(),
    remaining_count: z.number(),
    latest: fulfillmentSchema.nullable(),
    active: z.array(fulfillmentSchema),
    actions: z.object({ can_offer: z.boolean() }),
  }),
});

const coverageSchema = z.object({
  required_count: z.number(),
  available_count: z.number(),
  total_gap_headcount: z.number(),
  coverage_pct: z.number().nullable(),
  below_minimum_safe: z.number(),
});

const unitAtRiskSchema = z.object({
  unit_id: z.number().nullable(),
  unit_label: z.string(),
  gap_headcount: z.number(),
  worst_role: roleEnum,
  worst_role_label: z.string(),
  status: z.string(),
  below_minimum_safe: z.boolean(),
  roles: z.array(planSchema),
});

const roleGapSchema = z.object({
  role: roleEnum,
  role_label: z.string(),
  gap_headcount: z.number(),
  required_count: z.number(),
  available_count: z.number(),
});

const workforceMetricsSchema = z.object({
  total_members: z.number(),
  active_members: z.number(),
  inactive_members: z.number(),
  active_fte: z.number(),
  role_count: z.number(),
  unit_count: z.number(),
  hospital_wide_members: z.number(),
  synthetic_members: z.number(),
  credential_attention: z.number(),
  unavailable_members: z.number(),
});

const workforceRoleSchema = z.object({
  role_code: z.string(),
  role_label: z.string(),
  role_category: z.string(),
  active_count: z.number(),
  fte: z.number(),
});

const workforceSummarySchema = z.object({
  available: z.boolean(),
  metrics: workforceMetricsSchema,
  by_role: z.array(workforceRoleSchema),
  by_employment: z.array(z.object({ key: z.string(), label: z.string(), count: z.number() })),
  by_shift: z.array(z.object({ shift: shiftEnum, label: z.string(), count: z.number() })),
  assumptions: z.object({
    roster_window: z.object({ start: z.string(), end: z.string() }).nullable(),
    annual_coverage_days: z.number(),
    shift_hours: z.number(),
    productive_hours_per_fte: z.number(),
    relief_factor: z.number(),
    not_a_regulatory_ratio: z.boolean(),
  }).nullable(),
});

const workforceMemberSchema = z.object({
  staff_member_id: z.number(),
  display_name: z.string(),
  role_code: z.string(),
  role_label: z.string(),
  role_category: z.string(),
  unit_id: z.number().nullable(),
  unit_label: z.string(),
  service_line_code: z.string(),
  employee_type: z.string().nullable(),
  employment_class: z.string(),
  fte: z.number(),
  coverage_model: z.string().nullable(),
  preferred_shift: shiftEnum.nullable(),
  availability: z.string(),
  credential_status: z.string(),
  credentials: z.array(z.string()),
  eligible_float_units: z.array(z.string()),
  is_active: z.boolean(),
  is_synthetic: z.boolean(),
  availability_source: z.string().nullable(),
});

const overviewSchema = z.object({
  permissions: z.object({ manage: z.boolean() }),
  source: sourceFreshnessSchema,
  metrics: z.object({
    open_requests: z.number(),
    at_risk_units: z.number(),
    critical_gaps: z.number(),
    unfilled_requests: z.number(),
    total_gap_headcount: z.number(),
    coverage_pct: z.number().nullable(),
    stat_requests: z.number(),
  }),
  coverage: coverageSchema,
  workforce: workforceSummarySchema,
  units_at_risk: z.array(unitAtRiskSchema),
  by_role: z.array(roleGapSchema),
  queue: z.array(requestSchema),
  resource_options: z.array(
    z.object({ key: z.string(), name: z.string(), type: z.string(), available: z.number().nullable() }),
  ),
});

const envelope = <T>(schema: z.ZodType<T>) => z.object({ data: schema });

export async function fetchStaffingOverview(): Promise<StaffingOverview> {
  const res = await axios.get('/api/staffing/overview');
  return envelope(overviewSchema).parse(res.data).data;
}

export async function fetchStaffingWorkforce(params: StaffingWorkforceFilters = {}): Promise<StaffingWorkforceDirectory> {
  const res = await axios.get('/api/staffing/workforce', { params });
  return z.object({
    data: z.array(workforceMemberSchema),
    meta: z.object({
      current_page: z.number(),
      last_page: z.number(),
      per_page: z.number(),
      total: z.number(),
    }),
  }).parse(res.data);
}

export async function fetchStaffingRequests(params: { status?: StaffingRequestStatus } = {}): Promise<StaffingRequest[]> {
  const res = await axios.get('/api/staffing/requests', { params });
  return envelope(z.array(requestSchema)).parse(res.data).data;
}

export async function createStaffingRequest(input: CreateStaffingRequestInput): Promise<StaffingRequest> {
  const res = await axios.post('/api/staffing/requests', input);
  return envelope(requestSchema).parse(res.data).data;
}

export async function assignStaffingRequest(id: number, input: AssignStaffingRequestInput): Promise<StaffingRequest> {
  const res = await axios.post(`/api/staffing/requests/${id}/assign`, input);
  return envelope(requestSchema).parse(res.data).data;
}

export async function updateStaffingStatus(id: number, status: StaffingRequestStatus): Promise<StaffingRequest> {
  const res = await axios.post(`/api/staffing/requests/${id}/status`, { status });
  return envelope(requestSchema).parse(res.data).data;
}

const candidateSchema = z.object({
  staff_member_id: z.number(),
  display_name: z.string(),
  role_code: z.string(),
  role_label: z.string(),
  unit_id: z.number().nullable(),
  coverage_model: z.string().nullable(),
  eligible: z.boolean(),
  eligibility_state: z.enum(['eligible', 'unqualified', 'unavailable', 'conflicted']),
  reason_codes: z.array(z.string()),
  qualification_requirements: z.array(z.object({
    qualification_code: z.string(), display_name: z.string(), verified: z.boolean(),
  })),
  availability: z.object({ covering_windows: z.number(), blocking_windows: z.number(), timezone: z.string() }),
  overlapping_assignments: z.number(),
  shift: z.object({ starts_at: z.string(), ends_at: z.string(), timezone: z.string() }),
});

export async function fetchStaffingCandidates(id: number): Promise<StaffingCandidatePage> {
  const res = await axios.get(`/api/staffing/requests/${id}/candidates`, {
    params: { per_page: 100 },
  });
  return z.object({
    data: z.array(candidateSchema),
    meta: z.object({ current_page: z.number(), last_page: z.number(), per_page: z.number(), total: z.number() }),
    shift: z.object({ starts_at: z.string(), ends_at: z.string(), timezone: z.string() }),
  }).parse(res.data);
}

export async function offerStaffingFulfillment(
  id: number,
  input: OfferStaffingFulfillmentInput,
  idempotencyKey: string,
): Promise<StaffingFulfillment> {
  const res = await axios.post(`/api/staffing/requests/${id}/fulfillments`, input, {
    headers: { 'Idempotency-Key': idempotencyKey },
  });
  return envelope(fulfillmentSchema).parse(res.data).data;
}

export async function transitionStaffingFulfillment(
  fulfillmentUuid: string,
  status: Exclude<StaffingFulfillmentStatus, 'offered'>,
  idempotencyKey: string,
): Promise<StaffingFulfillment> {
  const res = await axios.post(`/api/staffing/fulfillments/${fulfillmentUuid}/transition`, { status }, {
    headers: { 'Idempotency-Key': idempotencyKey },
  });
  return envelope(fulfillmentSchema).parse(res.data).data;
}
