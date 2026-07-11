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
    is_admin?: boolean;
    can?: {
      view_integrations?: boolean;
      manage_integrations?: boolean;
      view_enterprise_setup?: boolean;
      manage_staffing_alignment?: boolean;
      view_administration?: boolean;
      view_user_audit?: boolean;
    };
  };
  eddy?: {
    enabled: boolean;
  };
  arena?: {
    ai_enabled: boolean;
  };
  features?: {
    virtual_rounds?: boolean;
  };
  flash?: {
    message?: string;
    error?: string;
  };
  [key: string]: unknown;
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
