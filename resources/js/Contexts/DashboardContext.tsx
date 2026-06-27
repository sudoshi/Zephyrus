import React, { createContext, useContext, useState, useMemo, useCallback, useEffect } from 'react';
import type { ReactNode } from 'react';
import { router, usePage } from '@inertiajs/react';
import type { NavigationItem, WorkflowNavigationItem } from '@/types';

interface WorkflowNavigation {
  name: string;
  analytics: NavigationItem[];
  operations: NavigationItem[];
  predictions?: NavigationItem[];
}

interface WorkflowNavigationConfig {
  [key: string]: WorkflowNavigation;
}

// Move workflowNavigationConfig outside the DashboardProvider component
const workflowNavigationConfig: WorkflowNavigationConfig = {
  superuser: {
    name: 'SUPERUSER',
    // Only routes that actually resolve are listed; the prior superuser block
    // carried 7 dead hrefs (procedure-analysis, capacity-management, staffing,
    // scheduling, volume-forecasting, capacity-planning, resource-optimization).
    analytics: [
      { name: 'Primetime Utilization', href: '/analytics/primetime-utilization' },
      { name: 'OR Utilization', href: '/analytics/or-utilization' },
      { name: 'Block Utilization', href: '/analytics/block-utilization' },
      { name: 'Room Running', href: '/analytics/room-running' },
      { name: 'Turnover Times', href: '/analytics/turnover-times' },
    ],
    operations: [
      { name: 'Patient Flow', href: '/rtdc/patient-flow-navigator' },
    ],
    predictions: [],
  },
  home: {
    name: 'Home',
    analytics: [],
    operations: [],
  },
  rtdc: {
    name: 'RTDC',
    analytics: [
      {
        name: 'Utilization & Capacity',
        href: '/rtdc/analytics/utilization',
        description: 'Track and analyze utilization and capacity metrics',
      },
      {
        name: 'Performance Metrics',
        href: '/rtdc/analytics/performance',
        description: 'Monitor key performance indicators',
      },
      {
        name: 'Resource Analytics',
        href: '/rtdc/analytics/resources',
        description: 'Analyze resource allocation and utilization',
      },
      {
        name: 'Trends & Patterns',
        href: '/rtdc/analytics/trends',
        description: 'Historical tracking and pattern analysis',
      },
    ],
    operations: [
      {
        name: 'Bed Tracking',
        href: '/rtdc/bed-tracking',
        description: 'Real-time bed monitoring',
      },
      {
        name: 'Patient Flow 4D',
        href: '/rtdc/patient-flow-navigator',
        description: 'Replay and monitor patient movement on the facility model',
      },
      {
        name: 'Bed Placement',
        href: '/rtdc/bed-placement',
        description: 'Prescriptive bed-assignment recommendations',
      },
      {
        name: 'Ancillary Services',
        href: '/rtdc/ancillary-services',
        description: 'Track and coordinate support services',
      },
      {
        name: 'Global Huddle',
        href: '/rtdc/global-huddle',
        description: 'Hospital-wide operations coordination',
      },
      {
        name: 'Unit Huddle',
        href: '/rtdc/unit-huddle',
        description: 'Unit-level operations coordination',
      },
      {
        name: 'Service Huddle',
        href: '/rtdc/service-huddle',
        description: 'Department-specific coordination',
      },
    ],
    predictions: [
      {
        name: 'Demand Forecasting',
        href: '/rtdc/predictions/demand',
        description: 'Forecast patient volumes and case loads',
      },
      {
        name: 'Resource Planning',
        href: '/rtdc/predictions/resources',
        description: 'Plan future staffing and capacity needs',
      },
      {
        name: 'Discharge Predictions',
        href: '/rtdc/predictions/discharge',
        description: 'Forecast bed availability',
      },
      {
        name: 'Risk Assessment',
        href: '/rtdc/predictions/risk',
        description: 'Analyze schedule risks and bottlenecks',
      },
    ],
  },
  perioperative: {
    name: 'Perioperative',
    analytics: [
      {
        name: 'Block Utilization',
        href: '/analytics/block-utilization',
        description: 'Analyze block time utilization metrics',
      },
      {
        name: 'OR Utilization',
        href: '/analytics/or-utilization',
        description: 'Monitor operating room utilization',
      },
      {
        name: 'Primetime Utilization',
        href: '/analytics/primetime-utilization',
        description: 'Analyze utilization during prime operating hours',
      },
      {
        name: 'Room Running',
        href: '/analytics/room-running',
        description: 'Track operating room running metrics',
      },
      {
        name: 'Turnover Times',
        href: '/analytics/turnover-times',
        description: 'Analyze room turnover performance',
      },
    ],
    operations: [
      {
        name: 'Block Schedule',
        href: '/operations/block-schedule',
        description: 'Manage and view the OR block schedule',
      },
      {
        name: 'Case Management',
        href: '/operations/cases',
        description: 'Oversee case scheduling and management',
      },
      {
        name: 'Room Status',
        href: '/operations/room-status',
        description: 'Real-time monitoring of OR room statuses',
      },
    ],
    predictions: [
      {
        name: 'Utilization Forecast',
        href: '/predictions/forecast',
        description: 'Predict OR utilization and optimize scheduling',
      },
      {
        name: 'Demand Analysis',
        href: '/predictions/demand',
        description: 'Analyze demand for OR resources',
      },
      {
        name: 'Resource Planning',
        href: '/predictions/resources',
        description: 'Project future staffing needs and resource allocation',
      },
    ],
  },
  emergency: {
    name: 'Emergency',
    analytics: [
      {
        name: 'Wait Time',
        href: '/ed/analytics/wait-time',
        description: 'Monitor and analyze patient wait times',
      },
      {
        name: 'Patient Flow',
        href: '/ed/analytics/flow',
        description: 'Assess patient movement through the ED',
      },
    ],
    operations: [
      {
        name: 'Resource Management',
        href: '/ed/operations/resources',
        description: 'Manage ED resources and staffing',
      },
      {
        name: 'Triage',
        href: '/ed/operations/triage',
        description: 'Manage triage operations and patient prioritization',
      },
      {
        name: 'Treatment',
        href: '/ed/operations/treatment',
        description: 'Oversee treatment procedures and protocols',
      },
    ],
    predictions: [
      {
        name: 'Arrival Prediction',
        href: '/ed/predictions/arrival',
        description: 'Forecast patient arrivals to the ED',
      },
      {
        name: 'Resource Optimization',
        href: '/ed/predictions/resources',
        description: 'Optimize resource allocation based on predictions',
      },
    ],
  },
  improvement: {
    name: 'Improvement',
    analytics: [
      {
        name: 'Overview',
        href: '/dashboard/improvement',
        description: 'Overview of improvement initiatives',
        icon: 'lucide:layout-dashboard'
      },
      {
        name: 'Bottlenecks',
        href: '/improvement/bottlenecks',
        description: 'Analyze and monitor system bottlenecks',
        icon: 'lucide:alert-circle'
      },
      {
        name: 'Process Analysis',
        href: '/improvement/process',
        description: 'Process analysis dashboard',
        icon: 'lucide:git-branch'
      },
      {
        name: 'Root Cause',
        href: '/improvement/root-cause',
        description: 'Analyze and identify root causes of process bottlenecks and system issues',
        icon: 'lucide:search'
      },
      {
        name: 'Active Cycles',
        href: '/improvement/active',
        description: 'Track active improvement initiatives and explore opportunities',
        icon: 'lucide:refresh-ccw'
      },
    ],
    operations: [],
    predictions: [],
  },
  transport: {
    name: 'Transport',
    analytics: [
      {
        name: 'Command Center',
        href: '/dashboard/transport',
        description: 'Monitor active movement, SLA risk, and throughput',
        icon: 'lucide:truck'
      },
      {
        name: 'Analytics',
        href: '/transport/analytics',
        description: 'Review transport cycle times, delays, and vendor performance',
        icon: 'lucide:bar-chart-3'
      },
    ],
    operations: [
      {
        name: 'Requests',
        href: '/transport/requests',
        description: 'Create and manage canonical transport requests',
        icon: 'lucide:clipboard-list'
      },
      {
        name: 'Dispatch',
        href: '/transport/dispatch',
        description: 'Assign teams, vendors, and operational status',
        icon: 'lucide:route'
      },
      {
        name: 'Inpatient',
        href: '/transport/inpatient',
        description: 'Coordinate internal patient movement',
        icon: 'lucide:bed'
      },
      {
        name: 'Transfers',
        href: '/transport/transfers',
        description: 'Coordinate interfacility transfers and bed dependencies',
        icon: 'lucide:building-2'
      },
      {
        name: 'Discharge',
        href: '/transport/discharge',
        description: 'Coordinate discharge rides and NEMT',
        icon: 'lucide:send'
      },
      {
        name: 'EMS',
        href: '/transport/ems',
        description: 'Track inbound EMS handoffs and ETAs',
        icon: 'lucide:ambulance'
      },
      {
        name: 'Care Transitions',
        href: '/transport/care-transitions',
        description: 'Monitor post-acute referrals and transition packets',
        icon: 'lucide:network'
      },
      {
        name: 'Resources',
        href: '/transport/resources',
        description: 'Manage teams, equipment, and vendor capacity',
        icon: 'lucide:boxes'
      },
    ],
    predictions: [
      {
        name: 'Integration Settings',
        href: '/transport/settings/integrations',
        description: 'Review connector capabilities and implementation posture',
        icon: 'lucide:settings'
      },
    ],
  },
};

interface DashboardState {
  currentWorkflow: string;
  navigationItems: WorkflowNavigation;
  isLoading: boolean;
}

interface DashboardContextType {
  currentWorkflow: string;
  changeWorkflow: (workflow: string) => void;
  navigationItems: WorkflowNavigation;
  mainNavigationItems: WorkflowNavigationItem[];
  isLoading: boolean;
}

const DashboardContext = createContext<DashboardContextType | undefined>(undefined);

interface DashboardProviderProps {
  children: ReactNode;
  currentUrl?: string;
}

export function DashboardProvider({ children }: DashboardProviderProps) {
  const { workflow: initialWorkflow } = usePage<{ workflow?: string }>().props;
  const [state, setState] = useState<DashboardState>({
    currentWorkflow: initialWorkflow || 'superuser',
    navigationItems: workflowNavigationConfig[initialWorkflow || 'superuser'],
    isLoading: false,
  });

  useEffect(() => {
    if (initialWorkflow && initialWorkflow !== state.currentWorkflow) {
      setState((prevState) => ({
        ...prevState,
        currentWorkflow: initialWorkflow,
        navigationItems: workflowNavigationConfig[initialWorkflow],
      }));
    }
  }, [initialWorkflow]);

  const changeWorkflow = useCallback(
    (workflow: string) => {
      // Set loading state
      setState((prevState) => ({
        ...prevState,
        isLoading: true
      }));

      // Determine the redirect path
      const path = workflow === 'home' ? '/home' : `/dashboard/${workflow}`;

      // Use Inertia's router.get() with URL parameters
      router.get(`/set-preference/${workflow}`, {
        redirect: path
      }, {
        preserveState: false,
        preserveScroll: false,
        onSuccess: () => {
          setState((prevState) => ({
            ...prevState,
            currentWorkflow: workflow,
            navigationItems: workflowNavigationConfig[workflow],
            isLoading: false
          }));
        },
        onError: () => {
          // Reset loading state on error
          setState((prevState) => ({
            ...prevState,
            isLoading: false
          }));
        }
      });
    },
    []
  );

  const mainNavigationItems = useMemo<WorkflowNavigationItem[]>(
    () => [
      {
        name: 'SUPERUSER',
        workflow: 'superuser',
        href: '/dashboard',
        icon: 'heroicons:key'
      },
      {
        name: 'RTDC',
        workflow: 'rtdc',
        href: '/dashboard/rtdc',
        icon: 'heroicons:command-line'
      },
      {
        name: 'Perioperative',
        workflow: 'perioperative',
        href: '/dashboard/perioperative',
        icon: 'heroicons:heart'
      },
      {
        name: 'Emergency',
        workflow: 'emergency',
        href: '/dashboard/emergency',
        icon: 'heroicons:exclamation-triangle'
      },
      {
        name: 'Improvement',
        workflow: 'improvement',
        href: '/dashboard/improvement',
        icon: 'heroicons:arrow-trending-up'
      },
      {
        name: 'Transport',
        workflow: 'transport',
        href: '/dashboard/transport',
        icon: 'heroicons:truck'
      },
    ],
    []
  );

  const value: DashboardContextType = {
    currentWorkflow: state.currentWorkflow,
    changeWorkflow,
    navigationItems: state.navigationItems,
    mainNavigationItems,
    isLoading: state.isLoading,
  };

  return (
    <DashboardContext.Provider value={value}>
      {children}
    </DashboardContext.Provider>
  );
}

export function useDashboard(): DashboardContextType {
  const context = useContext(DashboardContext);
  if (context === undefined) {
    throw new Error('useDashboard must be used within a DashboardProvider');
  }
  return context;
}
