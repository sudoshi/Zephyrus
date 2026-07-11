import type { SourceFreshness } from '@/features/operations/sourceFreshness';

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
export type StaffingFulfillmentStatus = 'offered' | 'accepted' | 'filled' | 'released' | 'canceled';

export interface StaffingFulfillment {
  fulfillment_uuid: string;
  staffing_request_id: number;
  staff_member_id: number;
  staff_member_name: string;
  status: StaffingFulfillmentStatus;
  source: StaffingAssignedSource;
  version: number;
  role_code: string | null;
  unit_id: number | null;
  starts_at: string | null;
  ends_at: string | null;
  timezone: string | null;
  validation: Record<string, unknown>;
  offered_at: string | null;
  accepted_at: string | null;
  filled_at: string | null;
  released_at: string | null;
  canceled_at: string | null;
  actions: {
    can_accept: boolean;
    can_fill: boolean;
    can_release: boolean;
    can_cancel: boolean;
  };
}

export interface StaffingCandidate {
  staff_member_id: number;
  display_name: string;
  role_code: string;
  role_label: string;
  unit_id: number | null;
  coverage_model: string | null;
  eligible: boolean;
  eligibility_state: 'eligible' | 'unqualified' | 'unavailable' | 'conflicted';
  reason_codes: string[];
  qualification_requirements: Array<{
    qualification_code: string;
    display_name: string;
    verified: boolean;
  }>;
  availability: {
    covering_windows: number;
    blocking_windows: number;
    timezone: string;
  };
  overlapping_assignments: number;
  shift: { starts_at: string; ends_at: string; timezone: string };
}

export interface StaffingCandidatePage {
  data: StaffingCandidate[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
  shift: { starts_at: string; ends_at: string; timezone: string };
}

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
  is_synthetic: boolean;
  freshness_status: 'current' | 'stale' | 'expired';
  sla: StaffingSla;
  fulfillment: {
    available: boolean;
    state: 'unconfigured' | 'unfilled' | 'offer_pending' | 'partially_fulfilled' | 'filled';
    offered_count: number;
    accepted_count: number;
    filled_count: number;
    remaining_count: number;
    latest: StaffingFulfillment | null;
    active: StaffingFulfillment[];
    actions: { can_offer: boolean };
  };
}

export interface StaffingCoverage {
  required_count: number;
  available_count: number;
  total_gap_headcount: number;
  coverage_pct: number | null;
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
  available: number | null;
}

export interface StaffingWorkforceMetrics {
  total_members: number;
  active_members: number;
  inactive_members: number;
  active_fte: number;
  role_count: number;
  unit_count: number;
  hospital_wide_members: number;
  synthetic_members: number;
  credential_attention: number;
  unavailable_members: number;
}

export interface StaffingWorkforceRole {
  role_code: string;
  role_label: string;
  role_category: string;
  active_count: number;
  fte: number;
}

export interface StaffingWorkforceMix {
  key: string;
  label: string;
  count: number;
}

export interface StaffingWorkforceShift {
  shift: StaffingShift;
  label: string;
  count: number;
}

export interface StaffingWorkforceSummary {
  available: boolean;
  metrics: StaffingWorkforceMetrics;
  by_role: StaffingWorkforceRole[];
  by_employment: StaffingWorkforceMix[];
  by_shift: StaffingWorkforceShift[];
  assumptions: {
    roster_window: { start: string; end: string } | null;
    annual_coverage_days: number;
    shift_hours: number;
    productive_hours_per_fte: number;
    relief_factor: number;
    not_a_regulatory_ratio: boolean;
  } | null;
}

export interface StaffingWorkforceMember {
  staff_member_id: number;
  display_name: string;
  role_code: string;
  role_label: string;
  role_category: string;
  unit_id: number | null;
  unit_label: string;
  service_line_code: string;
  employee_type: string | null;
  employment_class: string;
  fte: number;
  coverage_model: string | null;
  preferred_shift: StaffingShift | null;
  availability: string;
  availability_source: string | null;
  credential_status: string;
  credentials: string[];
  eligible_float_units: string[];
  is_active: boolean;
  is_synthetic: boolean;
}

export interface StaffingWorkforceDirectory {
  data: StaffingWorkforceMember[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface StaffingWorkforceFilters {
  q?: string;
  role?: string;
  shift?: StaffingShift;
  status?: 'active' | 'inactive';
  page?: number;
  per_page?: number;
}

export interface StaffingOverview {
  permissions: { manage: boolean };
  source: SourceFreshness;
  metrics: {
    open_requests: number;
    at_risk_units: number;
    critical_gaps: number;
    unfilled_requests: number;
    total_gap_headcount: number;
    coverage_pct: number | null;
    stat_requests: number;
  };
  coverage: StaffingCoverage;
  workforce: StaffingWorkforceSummary;
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
  owner_name?: string;
}

export interface OfferStaffingFulfillmentInput {
  staff_member_id: number;
  source: StaffingAssignedSource;
}
