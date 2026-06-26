import axios from 'axios';
import { z } from 'zod';
import type {
  AssignStaffingRequestInput,
  CreateStaffingRequestInput,
  StaffingOverview,
  StaffingRequest,
  StaffingRequestStatus,
} from './types';

const slaSchema = z.object({
  minutes_until_due: z.number().nullable(),
  at_risk: z.boolean(),
  label: z.string(),
});

const roleEnum = z.enum(['rn', 'lpn', 'tech', 'charge', 'provider', 'respiratory', 'unit_secretary']);
const shiftEnum = z.enum(['day', 'evening', 'night']);

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
  sla: slaSchema,
});

const coverageSchema = z.object({
  required_count: z.number(),
  available_count: z.number(),
  total_gap_headcount: z.number(),
  coverage_pct: z.number(),
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

const overviewSchema = z.object({
  metrics: z.object({
    open_requests: z.number(),
    at_risk_units: z.number(),
    critical_gaps: z.number(),
    unfilled_requests: z.number(),
    total_gap_headcount: z.number(),
    coverage_pct: z.number(),
    stat_requests: z.number(),
  }),
  coverage: coverageSchema,
  units_at_risk: z.array(unitAtRiskSchema),
  by_role: z.array(roleGapSchema),
  queue: z.array(requestSchema),
  resource_options: z.array(
    z.object({ key: z.string(), name: z.string(), type: z.string(), available: z.number() }),
  ),
});

const envelope = <T>(schema: z.ZodType<T>) => z.object({ data: schema });

export async function fetchStaffingOverview(): Promise<StaffingOverview> {
  const res = await axios.get('/api/staffing/overview');
  return envelope(overviewSchema).parse(res.data).data;
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
