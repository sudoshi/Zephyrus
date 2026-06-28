export interface User {
  id: number;
  username: string;
  name: string;
  email: string;
  workflow_preference?: string;
  must_change_password?: boolean;
  roles?: string[];
}

export interface PageProps {
  auth: {
    user: User | null;
    roles?: string[];
    is_admin?: boolean;
  };
  eddy?: {
    enabled: boolean;
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
