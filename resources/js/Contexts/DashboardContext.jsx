import React, { createContext, useContext, useState, useMemo, useCallback, useEffect } from 'react';
import PropTypes from 'prop-types';
import { router, usePage } from '@inertiajs/react';

// Move workflowNavigationConfig outside the DashboardProvider component
const workflowNavigationConfig = {
  superuser: {
    name: 'SUPERUSER',
    analytics: [
      { name: 'Primetime Utilization', href: '/analytics/primetime-utilization' },
      { name: 'OR Utilization', href: '/analytics/or-utilization' },
      { name: 'Block Utilization', href: '/analytics/block-utilization' },
      { name: 'Room Running', href: '/analytics/room-running' },
      { name: 'Turnover Times', href: '/analytics/turnover-times' },
      { name: 'Procedure Analysis', href: '/analytics/procedure-analysis' },
    ],
    operations: [
      { name: 'Capacity Management', href: '/operations/capacity-management' },
      { name: 'Staffing', href: '/operations/staffing' },
      { name: 'Scheduling', href: '/operations/scheduling' },
      { name: 'Patient Flow', href: '/operations/patient-flow' },
    ],
    predictions: [
      { name: 'Volume Forecasting', href: '/predictions/volume-forecasting' },
      { name: 'Capacity Planning', href: '/predictions/capacity-planning' },
      { name: 'Resource Optimization', href: '/predictions/resource-optimization' },
    ],
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
};

const DashboardContext = createContext();

export function DashboardProvider({ children }) {
  const { workflow: initialWorkflow } = usePage().props;
  const [state, setState] = useState({
    currentWorkflow: initialWorkflow || 'perioperative',
    navigationItems: workflowNavigationConfig[initialWorkflow || 'perioperative'],
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
    (workflow) => {
      setState((prevState) => ({
        ...prevState,
        isLoading: true
      }));

      // First, make a server request to update the workflow in the session
      fetch('/change-workflow', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
          'Accept': 'application/json',
        },
        body: JSON.stringify({ workflow }),
        credentials: 'same-origin'
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          // After the session is updated, navigate to the new dashboard
          const path = workflow === 'home' ? '/home' : `/dashboard/${workflow}`;
          router.visit(path, {
            preserveState: true, // Preserve state to maintain authentication
            preserveScroll: false, // Don't preserve scroll position for a fresh start
            onBefore: () => {
              // Update navigation items before navigation
              setState((prevState) => ({
                ...prevState,
                currentWorkflow: workflow,
                navigationItems: workflowNavigationConfig[workflow] || null
              }));
            },
            onSuccess: () => {
              // Update state with new workflow data
              setState((prevState) => ({
                ...prevState,
                currentWorkflow: workflow,
                navigationItems: workflowNavigationConfig[workflow],
                isLoading: false
              }));
            },
            onError: () => {
              setState((prevState) => ({ 
                ...prevState, 
                isLoading: false,
                navigationItems: workflowNavigationConfig[prevState.currentWorkflow] // Restore previous navigation items on error
              }));
            }
          });
        } else {
          // Handle error if the server request fails
          setState((prevState) => ({
            ...prevState,
            isLoading: false
          }));
        }
      })
      .catch(error => {
        console.error('Error changing workflow:', error);
        setState((prevState) => ({
          ...prevState,
          isLoading: false
        }));
      });
    },
    []
  );

  const mainNavigationItems = useMemo(
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
    ],
    []
  );

  const value = {
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

DashboardProvider.propTypes = {
  children: PropTypes.node.isRequired,
};

export function useDashboard() {
  const context = useContext(DashboardContext);
  if (context === undefined) {
    throw new Error('useDashboard must be used within a DashboardProvider');
  }
  return context;
}
