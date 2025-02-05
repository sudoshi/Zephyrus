import React, { createContext, useContext, useState, useMemo, useCallback, useEffect } from 'react';
import PropTypes from 'prop-types';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';

// Move workflowNavigationConfig outside the DashboardProvider component
const workflowNavigationConfig = {
  rtdc: {
    analytics: [
      {
        name: 'Utilization & Capacity',
        href: route('rtdc.analytics.utilization'),
        description: 'Track and analyze utilization and capacity metrics',
      },
      {
        name: 'Performance Metrics',
        href: route('rtdc.analytics.performance'),
        description: 'Monitor key performance indicators',
      },
      {
        name: 'Resource Analytics',
        href: route('rtdc.analytics.resources'),
        description: 'Analyze resource allocation and utilization',
      },
      {
        name: 'Trends & Patterns',
        href: route('rtdc.analytics.trends'),
        description: 'Historical tracking and pattern analysis',
      },
    ],
    operations: [
      {
        name: 'Bed Tracking',
        href: route('rtdc.bed-tracking'),
        description: 'Real-time bed monitoring',
      },
      {
        name: 'Ancillary Services',
        href: route('rtdc.ancillary-services'),
        description: 'Track and coordinate support services',
      },
      {
        name: 'Global Huddle',
        href: route('rtdc.global-huddle'),
        description: 'Hospital-wide operations coordination',
      },
      {
        name: 'Service Huddle',
        href: route('rtdc.service-huddle'),
        description: 'Department-specific coordination',
      },
    ],
    predictions: [
      {
        name: 'Demand Forecasting',
        href: route('rtdc.predictions.demand'),
        description: 'Forecast patient volumes and case loads',
      },
      {
        name: 'Resource Planning',
        href: route('rtdc.predictions.resources'),
        description: 'Plan future staffing and capacity needs',
      },
      {
        name: 'Discharge Predictions',
        href: route('rtdc.predictions.discharge'),
        description: 'Forecast bed availability',
      },
      {
        name: 'Risk Assessment',
        href: route('rtdc.predictions.risk'),
        description: 'Analyze schedule risks and bottlenecks',
      },
    ],
  },
  or: {
    analytics: [
      {
        name: 'Service Analytics',
        href: route('analytics.service'),
        description: 'Analyze performance metrics across different services',
      },
      {
        name: 'Provider Analytics',
        href: route('analytics.provider'),
        description: 'Monitor provider efficiency and performance metrics',
      },
      {
        name: 'Historical Trends',
        href: route('analytics.trends'),
        description: 'Examine historical patterns and track performance over time',
      },
    ],
    operations: [
      {
        name: 'Block Schedule',
        href: route('operations.block-schedule'),
        description: 'Manage and view the OR block schedule',
      },
      {
        name: 'Case Management',
        href: route('operations.cases'),
        description: 'Oversee case scheduling and management',
      },
      {
        name: 'Room Status',
        href: route('operations.room-status'),
        description: 'Real-time monitoring of OR room statuses',
      },
    ],
    predictions: [
      {
        name: 'Utilization Forecast',
        href: route('predictions.forecast'),
        description: 'Predict OR utilization and optimize scheduling',
      },
      {
        name: 'Demand Analysis',
        href: route('predictions.demand'),
        description: 'Analyze demand for OR resources',
      },
      {
        name: 'Resource Planning',
        href: route('predictions.resources'),
        description: 'Project future staffing needs and resource allocation',
      },
    ],
  },
  ed: {
    analytics: [
      {
        name: 'Wait Time',
        href: route('ed.analytics.wait-time'),
        description: 'Monitor and analyze patient wait times',
      },
      {
        name: 'Patient Flow',
        href: route('ed.analytics.flow'),
        description: 'Assess patient movement through the ED',
      },
    ],
    operations: [
      {
        name: 'Resource Management',
        href: route('ed.operations.resources'),
        description: 'Manage ED resources and staffing',
      },
      {
        name: 'Triage',
        href: route('ed.operations.triage'),
        description: 'Manage triage operations and patient prioritization',
      },
      {
        name: 'Treatment',
        href: route('ed.operations.treatment'),
        description: 'Oversee treatment procedures and protocols',
      },
    ],
    predictions: [
      {
        name: 'Arrival Prediction',
        href: route('ed.predictions.arrival'),
        description: 'Forecast patient arrivals to the ED',
      },
      {
        name: 'Resource Optimization',
        href: route('ed.predictions.resources'),
        description: 'Optimize resource allocation based on predictions',
      },
    ],
  },
};

const DashboardContext = createContext();

export function DashboardProvider({ children }) {
  const { workflow: initialWorkflow } = usePage().props;
  const [state, setState] = useState({
    currentWorkflow: initialWorkflow || 'or',
    navigationItems: workflowNavigationConfig[initialWorkflow || 'or'],
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
    async (workflow) => {
      const previousState = state;
      setState((prevState) => ({
        ...prevState,
        currentWorkflow: workflow,
        navigationItems: workflowNavigationConfig[workflow],
        isLoading: true,
      }));

      try {
        await axios.post('/change-workflow', { workflow });
      } catch (error) {
        console.error('Failed to change workflow:', error);
        setState(previousState);
      } finally {
        setState((prevState) => ({ ...prevState, isLoading: false }));
      }
    },
    [state]
  );

  const dashboardItems = useMemo(
    () => [
      { name: 'RTDC', href: route('dashboard.rtdc') },
      { name: 'OR', href: route('dashboard.or') },
      { name: 'ED', href: route('dashboard.ed') },
    ],
    []
  );

  const value = {
    currentWorkflow: state.currentWorkflow,
    changeWorkflow,
    navigationItems: state.navigationItems,
    dashboardItems,
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
