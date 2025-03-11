import React, { useState, useEffect } from 'react';
import PropTypes from 'prop-types';
import { usePatientFlowData } from '@/hooks/usePatientFlowData';
import { useAnalytics } from '@/Contexts/AnalyticsContext';
import HierarchicalFilters from '@/Components/Analytics/shared/HierarchicalFilters';
import { motion, AnimatePresence } from 'framer-motion';
import ErrorBoundary from '@/Components/ErrorBoundary';
import TabNavigation from '@/Components/ui/TabNavigation';
import Panel from '@/Components/ui/Panel';
import { Icon } from '@iconify/react';

// Import view components
import OverviewView from './Views/OverviewView';
import StatisticsView from './Views/StatisticsView';
import ProcessMapView from './Views/ProcessMapView';
import VariantsView from './Views/VariantsView';
import PerformanceView from './Views/PerformanceView';
import OptimizationView from './Views/OptimizationView';

/**
 * Patient Flow Dashboard Component
 * Displays comprehensive patient flow metrics and process visualizations
 */
const PatientFlowDashboard = ({ activeView: initialActiveView, filters: initialFilters }) => {
  // Get analytics context
  const { selectedLocation, dateRange } = useAnalytics();
  
  // Use the provided activeView prop if available
  const [activeView, setActiveView] = useState(initialActiveView || 'overview');
  const [filtersVisible, setFiltersVisible] = useState(false);
  
  // Local filter state with hierarchical structure
  const [filters, setFilters] = useState(initialFilters || {
    selectedHospital: '',
    selectedLocation: '',
    selectedDepartment: '',
    selectedUnit: '',
    selectedPatientType: '',
    dateRange,
    showComparison: false,
    comparisonDateRange: {
      startDate: new Date(2024, 0, 1), // Jan 1, 2024
      endDate: new Date(2024, 5, 30)   // Jun 30, 2024
    }
  });
  
  // Update filters when context changes or when initialFilters changes
  useEffect(() => {
    if (initialFilters) {
      setFilters(initialFilters);
    } else {
      setFilters(prev => ({
        ...prev,
        dateRange
      }));
    }
  }, [dateRange, initialFilters]);

  // Update active view when initialActiveView prop changes
  useEffect(() => {
    if (initialActiveView) {
      setActiveView(initialActiveView);
    }
  }, [initialActiveView]);
  
  // Load data using the custom hook
  const { 
    data, 
    isLoading, 
    error, 
    refresh,
    derivedMetrics,
    hasData
  } = usePatientFlowData(filters);
  
  // Handle filter changes
  const handleFilterChange = (newFilters) => {
    setFilters(newFilters);
  };

  // Define tab navigation menu groups
  const menuGroups = [
    {
      title: 'Analysis',
      items: [
        { id: 'overview', label: 'Overview', icon: 'carbon:dashboard' },
        { id: 'statistics', label: 'Statistics', icon: 'carbon:chart-line' },
        { id: 'variants', label: 'Variants', icon: 'carbon:flow' },
        { id: 'performance', label: 'Performance', icon: 'carbon:chart-evaluation' },
        { id: 'optimization', label: 'Optimization', icon: 'carbon:optimize' }
      ]
    }
  ];

  // Handle tab change
  const handleTabChange = (tabId) => {
    setActiveView(tabId);
  };

  // Get the appropriate view component based on activeView
  const getViewComponent = () => {
    if (isLoading) {
      return (
        <div className="flex justify-center items-center h-64">
          <div className="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-healthcare-primary dark:border-healthcare-primary-dark"></div>
        </div>
      );
    }

    if (error) {
      return (
        <div className="bg-red-100 dark:bg-red-900 border border-red-400 dark:border-red-700 text-red-700 dark:text-red-200 px-4 py-3 rounded relative" role="alert">
          <div className="flex items-start">
            <div className="flex-shrink-0">
              <svg className="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
              </svg>
            </div>
            <div className="ml-3">
              <h3 className="text-sm font-medium text-red-800">
                Error Loading Patient Flow Data
              </h3>
              <div className="mt-2 text-sm text-red-700">
                {error.message || 'Failed to load patient flow data. Please try again later.'}
              </div>
              <div className="mt-4">
                <button
                  type="button"
                  onClick={() => refresh(true)}
                  className="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-red-700 bg-red-100 hover:bg-red-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                >
                  Retry
                </button>
              </div>
            </div>
          </div>
        </div>
      );
    }

    if (!hasData) {
      return (
        <div className="bg-yellow-50 dark:bg-yellow-900 border border-yellow-200 dark:border-yellow-700 text-yellow-700 dark:text-yellow-200 px-4 py-3 rounded relative" role="alert">
          <div className="flex items-start">
            <div className="flex-shrink-0">
              <svg className="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
              </svg>
            </div>
            <div className="ml-3">
              <h3 className="text-sm font-medium text-yellow-800 dark:text-yellow-300">
                No Data Available
              </h3>
              <div className="mt-2 text-sm text-yellow-700 dark:text-yellow-200">
                There is no patient flow data available for the selected filters. Please try different filters or contact support if you believe this is an error.
              </div>
              <div className="mt-4">
                <button
                  type="button"
                  onClick={() => refresh(true)}
                  className="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-yellow-700 bg-yellow-100 hover:bg-yellow-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 dark:bg-yellow-800 dark:text-yellow-100 dark:hover:bg-yellow-700"
                >
                  Refresh Data
                </button>
              </div>
            </div>
          </div>
        </div>
      );
    }

    // Special case for process-map - encapsulate in a panel without a title
    if (activeView === 'process-map') {
      return (
        <Panel title="">
          <ProcessMapView data={data} />
        </Panel>
      );
    }

    // Render the appropriate view based on activeView
    switch (activeView) {
      case 'overview':
        return <OverviewView data={data} derivedMetrics={derivedMetrics} />;
      case 'statistics':
        return <StatisticsView data={data} />;
      case 'variants':
        return <VariantsView data={data} />;
      case 'performance':
        return <PerformanceView data={data} />;
      case 'optimization':
        return <OptimizationView data={data} />;
      default:
        return <OverviewView data={data} derivedMetrics={derivedMetrics} />;
    }
  };
  
  // Toggle mobile filters visibility
  const toggleFilters = () => {
    setFiltersVisible(!filtersVisible);
  };

  // Create a vertical navigation component
  const VerticalNavigation = () => {
    return (
      <div className="bg-gray-100 dark:bg-gray-800 rounded-lg p-4 h-full">
        <div className="space-y-1">
          {menuGroups.map(group => (
            <div key={group.title} className="mb-4">
              <h3 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">
                {group.title}
              </h3>
              <div className="space-y-1">
                {group.items.map(item => (
                  <button
                    key={item.id}
                    onClick={() => handleTabChange(item.id)}
                    className={`flex items-center w-full px-3 py-2 text-sm font-medium rounded-md transition-colors duration-150 ${activeView === item.id 
                      ? 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200' 
                      : 'text-gray-700 hover:bg-gray-200 dark:text-gray-300 dark:hover:bg-gray-700'}`}
                  >
                    {item.icon && (
                      <span className="mr-3">
                        <Icon icon={item.icon} className="h-5 w-5" />
                      </span>
                    )}
                    {item.label}
                  </button>
                ))}
              </div>
            </div>
          ))}
        </div>
      </div>
    );
  };

  return (
    <div className="w-full">
      <div className="mb-4 md:hidden">
        <button
          onClick={toggleFilters}
          className="flex items-center justify-center w-full px-4 py-2 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-md shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
        >
          {filtersVisible ? 'Hide Filters' : 'Show Filters'}
        </button>
      </div>

      <div className="flex flex-col md:flex-row gap-6">
        {/* Left sidebar with navigation and filters */}
        <div className="md:w-64 flex-shrink-0 space-y-6">
          {/* Vertical Navigation */}
          <div className="hidden md:block sticky top-4">
            <VerticalNavigation />
          </div>
          
          {/* Filters */}
          <div className={`${!filtersVisible ? 'hidden md:block' : ''} sticky top-4 mt-6`}>
            <ErrorBoundary>
              <HierarchicalFilters 
                locations={Object.values(data?.locations || {}).map(location => ({
                  ...location,
                  name: location.name || location.fullName || 'Unknown Location'
                }))}
                services={Object.keys(data?.departments || {})}
                providers={Object.values(data?.units || {}).map(unit => ({
                  ...unit,
                  id: unit.id || '',
                  name: unit.name || 'Unknown Unit',
                  department: unit.department || ''
                }))}
                onFilterChange={handleFilterChange}
                initialFilters={filters}
              />
            </ErrorBoundary>
          </div>
        </div>

        {/* Main content area */}
        <div className="flex-grow">
          <ErrorBoundary>
            <AnimatePresence mode="wait">
              <motion.div
                key={activeView}
                initial={{ opacity: 0, x: 10 }}
                animate={{ opacity: 1, x: 0 }}
                exit={{ opacity: 0, x: -10 }}
                transition={{ duration: 0.3 }}
                className="w-full"
              >
                {getViewComponent()}
              </motion.div>
            </AnimatePresence>
          </ErrorBoundary>
        </div>
      </div>
    </div>
  );
};

PatientFlowDashboard.propTypes = {
  activeView: PropTypes.string,
  filters: PropTypes.shape({
    selectedHospital: PropTypes.string,
    selectedLocation: PropTypes.string,
    selectedDepartment: PropTypes.string,
    selectedUnit: PropTypes.string,
    selectedPatientType: PropTypes.string,
    dateRange: PropTypes.shape({
      startDate: PropTypes.instanceOf(Date),
      endDate: PropTypes.instanceOf(Date)
    }),
    showComparison: PropTypes.bool,
    comparisonDateRange: PropTypes.shape({
      startDate: PropTypes.instanceOf(Date),
      endDate: PropTypes.instanceOf(Date)
    })
  })
};

export default PatientFlowDashboard;
