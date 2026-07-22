export interface User {
  id: number;
  username: string;
  name: string;
  email: string;
  workflow_preference?: string;
  must_change_password?: boolean;
  /** The users.role string column (auth role of record); distinct from Spatie `roles`. */
  role?: string;
  roles?: string[];
}

export interface PageProps {
  auth: {
    user: User | null;
    roles?: string[];
    effective_roles?: string[];
    capabilities?: string[];
    is_admin?: boolean;
    can?: {
      view_integrations?: boolean;
      manage_integrations?: boolean;
      view_enterprise_setup?: boolean;
      manage_staffing_alignment?: boolean;
      view_administration?: boolean;
      view_identity?: boolean;
      manage_identity?: boolean;
      manage_privileges?: boolean;
      view_user_audit?: boolean;
      view_access_reviews?: boolean;
      manage_access_reviews?: boolean;
      view_authorization?: boolean;
      view_system_health?: boolean;
      run_diagnostics?: boolean;
      manage_enterprise_setup?: boolean;
      manage_facility_administration?: boolean;
      operate_integrations?: boolean;
      approve_integration_changes?: boolean;
      manage_data_stewardship?: boolean;
      view_patient_communications?: boolean;
      respond_patient_communications?: boolean;
    };
  };
  eddy?: {
    enabled: boolean;
  };
  arena?: {
    ai_enabled: boolean;
  };
  features?: {
    care_pathways_demo?: boolean;
    virtual_rounds?: boolean;
    home_hospital?: boolean;
    patient_communications?: boolean;
  };
  flash?: {
    message?: string;
    error?: string;
  };
  adminScope?: AdminScopeContract | null;
  [key: string]: unknown;
}

export interface AdminScopeOption {
  id: number;
  key: string;
  name: string;
}

export interface AdminFacilityScopeOption extends AdminScopeOption {
  organizationId: number;
}

export interface AdminSourceScopeOption extends AdminScopeOption {
  organizationId: number;
  facilityId: number;
}

export interface ActiveAdminScope {
  organization: AdminScopeOption;
  facility: AdminScopeOption | null;
  source: AdminScopeOption | null;
  revision: string;
  selectedAt: string;
}

export interface AdminScopeContract {
  organizations: AdminScopeOption[];
  facilities: AdminFacilityScopeOption[];
  sources: AdminSourceScopeOption[];
  current: ActiveAdminScope | null;
  query: Record<string, number>;
  updateUrl: string;
  clearUrl: string;
}

export interface NavigationItem {
  name: string;
  href: string;
  icon?: string;
  description?: string;
  current?: boolean;
}

export interface WorkflowNavigationItem {
  name: string;
  workflow: string;
  href: string;
  icon: string;
}
