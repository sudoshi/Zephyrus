import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import StaffingOffice from '@/Pages/Staffing/StaffingOffice';
import {
  useAssignStaffingRequest,
  useStaffingOverview,
  useStaffingWorkforce,
  useUpdateStaffingStatus,
} from '@/features/staffing/hooks';

vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ title, children }: { title: string; children: React.ReactNode }) => <main><h1>{title}</h1>{children}</main> }));
vi.mock('@/Components/Operations/OperationalDataState', () => ({
  OperationalDataError: ({ title }: { title: string }) => <div>{title}</div>,
  SourceFreshnessBanner: () => <div>Current source</div>,
}));
vi.mock('@/Components/system', () => ({
  metric: (value: unknown) => value,
  KpiTile: ({ metric }: { metric: { label: string; display?: string; value: number } }) => <div>{metric.label}: {metric.display ?? metric.value}</div>,
}));
vi.mock('@/features/staffing/hooks');

const workforce = {
  available: true,
  metrics: {
    total_members: 2800,
    active_members: 2788,
    inactive_members: 12,
    active_fte: 2314.5,
    role_count: 53,
    unit_count: 25,
    hospital_wide_members: 800,
    synthetic_members: 2800,
    credential_attention: 31,
    unavailable_members: 28,
  },
  by_role: [{ role_code: 'critical_care_nurse', role_label: 'Critical Care Nurse', role_category: 'nursing', active_count: 310, fte: 276.4 }],
  by_employment: [{ key: 'full_time', label: 'Full Time', count: 1800 }],
  by_shift: [
    { shift: 'day', label: 'Day', count: 1000 },
    { shift: 'evening', label: 'Evening', count: 940 },
    { shift: 'night', label: 'Night', count: 848 },
  ],
  assumptions: {
    roster_window: { start: '2026-07-09', end: '2026-08-05' },
    annual_coverage_days: 365,
    shift_hours: 8,
    productive_hours_per_fte: 1664,
    relief_factor: 1.18,
    not_a_regulatory_ratio: true,
  },
} as const;

const overview = {
  source: {},
  metrics: { open_requests: 0, at_risk_units: 0, critical_gaps: 0, unfilled_requests: 0, total_gap_headcount: 0, coverage_pct: 98, stat_requests: 0 },
  coverage: { required_count: 100, available_count: 98, total_gap_headcount: 2, coverage_pct: 98, below_minimum_safe: 0 },
  workforce,
  units_at_risk: [],
  by_role: [],
  queue: [],
  resource_options: [{ key: 'float_pool', name: 'Float Pool', type: 'internal_pool', available: null }],
};

describe('Staffing Office workforce roster', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(useStaffingOverview).mockReturnValue({ data: overview, isLoading: false, isError: false, refetch: vi.fn() } as never);
    vi.mocked(useStaffingWorkforce).mockReturnValue({
      data: {
        data: [{
          staff_member_id: 1,
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
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
      },
      isLoading: false,
      isError: false,
      isFetching: false,
      refetch: vi.fn(),
    } as never);
    vi.mocked(useAssignStaffingRequest).mockReturnValue({ isPending: false, mutate: vi.fn() } as never);
    vi.mocked(useUpdateStaffingStatus).mockReturnValue({ isPending: false, mutate: vi.fn() } as never);
  });

  it('renders roster posture, directory controls, and truthful unknown pool capacity', () => {
    render(<StaffingOffice />);

    expect(screen.getByRole('heading', { name: 'Workforce roster' })).toBeInTheDocument();
    expect(screen.getByText('Active people: 2788')).toBeInTheDocument();
    expect(screen.getByText('Avery A. Adams')).toBeInTheDocument();
    expect(screen.getByRole('textbox', { name: 'Search workforce' })).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Filter by role' })).toBeInTheDocument();
    expect(screen.getByText('Float Pool: Unknown')).toBeInTheDocument();
  });

  it('reports unavailable alignment data without inventing roster counts', () => {
    vi.mocked(useStaffingOverview).mockReturnValue({
      data: { ...overview, workforce: { ...workforce, available: false } },
      isLoading: false,
      isError: false,
      refetch: vi.fn(),
    } as never);

    render(<StaffingOffice />);

    expect(screen.getByText('Workforce alignment data is not available.')).toBeInTheDocument();
    expect(screen.queryByText('Active people: 2788')).not.toBeInTheDocument();
  });

  it('renders fractional SLA minutes as hours, minutes, and seconds', () => {
    vi.mocked(useStaffingOverview).mockReturnValue({
      data: {
        ...overview,
        queue: [{
          staffing_request_id: 42,
          request_uuid: 'request-42',
          unit_id: 3,
          unit_label: 'Medical ICU',
          staffing_plan_id: 7,
          role: 'rn',
          role_label: 'Registered Nurse',
          shift_date: '2026-07-09',
          shift: 'night',
          request_type: 'fill_gap',
          priority: 'urgent',
          status: 'open',
          headcount_needed: 2,
          hours_needed: 8,
          requested_by: 'operations-demo',
          needed_by: '2026-07-09T23:00:00Z',
          assigned_at: null,
          filled_at: null,
          completed_at: null,
          assigned_source: null,
          assigned_staff_ref: null,
          owner_name: null,
          risk_flags: [],
          resolution_payload: {},
          metadata: {},
          is_synthetic: true,
          freshness_status: 'current',
          sla: {
            minutes_until_due: 61.516666666666666,
            at_risk: false,
            label: '61.516666666666666m remaining',
          },
        }],
      },
      isLoading: false,
      isError: false,
      refetch: vi.fn(),
    } as never);

    render(<StaffingOffice />);

    expect(screen.getByText(/1 hr 1 min 31 sec remaining/)).toBeInTheDocument();
    expect(screen.queryByText(/61\.516666/)).not.toBeInTheDocument();
  });
});
