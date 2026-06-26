export type StaffingRole = 'rn' | 'lpn' | 'tech' | 'charge' | 'provider' | 'respiratory' | 'unit_secretary';
export type StaffingShift = 'day' | 'evening' | 'night';
export type StaffingPriority = 'routine' | 'urgent' | 'stat';
export type StaffingRequestType = 'fill_gap' | 'float' | 'overtime' | 'agency' | 'on_call' | 'reassign';
export type StaffingRequestStatus =
  | 'requested'
  | 'open'
  | 'sourcing'
  | 'assigned'
  | 'filled'
  | 'completed'
  | 'canceled'
  | 'escalated'
  | 'unfilled';
export type StaffingAssignedSource = 'float_pool' | 'overtime' | 'agency' | 'on_call';

export interface StaffingSla {
  minutes_until_due: number | null;
  at_risk: boolean;
  label: string;
}

export interface StaffingPlan {
  staffing_plan_id: number;
  plan_uuid: string;
  unit_id: number | null;
  unit_label: string;
  role: StaffingRole;
  role_label: string;
  shift_date: string | null;
  shift: StaffingShift;
  required_count: number;
  scheduled_count: number;
  actual_count: number;
  minimum_safe_count: number;
  census: number;
  ratio_target: number | null;
  gap_headcount: number;
  below_minimum_safe: boolean;
  status: string;
  notes: string | null;
  constraints: Record<string, unknown>;
}

export interface StaffingRequest {
  staffing_request_id: number;
  request_uuid: string;
  unit_id: number | null;
  unit_label: string;
  staffing_plan_id: number | null;
  role: StaffingRole;
  role_label: string;
  shift_date: string | null;
  shift: StaffingShift;
  request_type: StaffingRequestType;
  priority: StaffingPriority;
  status: StaffingRequestStatus;
  headcount_needed: number;
  hours_needed: number | null;
  requested_by: string | null;
  needed_by: string | null;
  assigned_at: string | null;
  filled_at: string | null;
  completed_at: string | null;
  assigned_source: StaffingAssignedSource | null;
  assigned_staff_ref: string | null;
  owner_name: string | null;
  risk_flags: unknown[];
  resolution_payload: Record<string, unknown>;
  metadata: Record<string, unknown>;
  sla: StaffingSla;
}

export interface StaffingCoverage {
  required_count: number;
  available_count: number;
  total_gap_headcount: number;
  coverage_pct: number;
  below_minimum_safe: number;
}

export interface StaffingUnitAtRisk {
  unit_id: number | null;
  unit_label: string;
  gap_headcount: number;
  worst_role: StaffingRole;
  worst_role_label: string;
  status: string;
  below_minimum_safe: boolean;
  roles: StaffingPlan[];
}

export interface StaffingRoleGap {
  role: StaffingRole;
  role_label: string;
  gap_headcount: number;
  required_count: number;
  available_count: number;
}

export interface StaffingResourceOption {
  key: string;
  name: string;
  type: string;
  available: number;
}

export interface StaffingOverview {
  metrics: {
    open_requests: number;
    at_risk_units: number;
    critical_gaps: number;
    unfilled_requests: number;
    total_gap_headcount: number;
    coverage_pct: number;
    stat_requests: number;
  };
  coverage: StaffingCoverage;
  units_at_risk: StaffingUnitAtRisk[];
  by_role: StaffingRoleGap[];
  queue: StaffingRequest[];
  resource_options: StaffingResourceOption[];
}

export interface CreateStaffingRequestInput {
  unit_id?: number | null;
  unit_label: string;
  role: StaffingRole;
  shift: StaffingShift;
  request_type: StaffingRequestType;
  priority: StaffingPriority;
  headcount_needed: number;
  hours_needed?: number;
  needed_by?: string;
  owner_name?: string;
}

export interface AssignStaffingRequestInput {
  assigned_source: StaffingAssignedSource;
  assigned_staff_ref?: string;
  owner_name?: string;
}
