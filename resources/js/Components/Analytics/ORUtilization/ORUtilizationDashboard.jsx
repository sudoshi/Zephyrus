import React, { useState, useEffect } from 'react';
import { useORUtilizationData } from '@/hooks/useORUtilizationData';
import { useAnalytics } from '@/contexts/AnalyticsContext';
import HierarchicalFilters from '@/Components/Analytics/shared/HierarchicalFilters';
import { motion, AnimatePresence } from 'framer-motion';
import ErrorBoundary from '@/Components/ErrorBoundary';

// Import view components
import OverviewView from './Views/OverviewView';
import TrendsView from './Views/TrendsView';
import RoomAnalysisView from './Views/RoomAnalysisView';
import SpecialtyAnalysisView from './Views/SpecialtyAnalysisView';
import OpportunityAnalysisView from './Views/OpportunityAnalysisView';

/**
 * OR Utilization Dashboard Component
 * Displays comprehensive OR utilization metrics and visualizations
 */
const ORUtilizationDashboard = ({ activeView: initialActiveView }) => {
  // Get analytics context
  const { selectedLocation, dateRange } = useAnalytics();
  
  // Use the provided activeView prop if available
  const [activeView, setActiveView] = useState(initialActiveView || 'overview');
  const [filtersVisible, setFiltersVisible] = useState(false);
  
  // Local filter state with hierarchical structure
  const [filters, setFilters] = useState({
    selectedHospital: '',
    selectedLocation: '',
    selectedSpecialty: '',
    selectedSurgeon: '',
    dateRange,
    showComparison: false,
    comparisonDateRange: {
      startDate: new Date(2024, 0, 1), // Jan 1, 2024
      endDate: new Date(2024, 5, 30)   // Jun 30, 2024
    }
  });
  
  // Update filters when context changes
  useEffect(() => {
    setFilters(prev => ({
      ...prev,
      dateRange
    }));
  }, [dateRange]);

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
  } = useORUtilizationData(filters);
  
  // Handle filter changes
  const handleFilterChange = (newFilters) => {
    setFilters(newFilters);
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
                Error Loading OR Utilization Data
              </h3>
              <div className="mt-2 text-sm text-red-700">
                {error.message || 'Failed to load OR utilization data. Please try again later.'}
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
                There is no OR utilization data available for the selected filters. Please try different filters or contact support if you believe this is an error.
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

    // Render the appropriate view based on activeView
    switch (activeView) {
      case 'overview':
        return <OverviewView data={data} derivedMetrics={derivedMetrics} />;
      case 'trends':
        return <TrendsView data={data} />;
      case 'room':
        return <RoomAnalysisView data={data} />;
      case 'specialty':
        return <SpecialtyAnalysisView data={data} />;
      case 'opportunity':
        return <OpportunityAnalysisView data={data} derivedMetrics={derivedMetrics} />;
      default:
        return <OverviewView data={data} derivedMetrics={derivedMetrics} />;
    }
  };
  
  // Toggle mobile filters visibility
  const toggleFilters = () => {
    setFiltersVisible(!filtersVisible);
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
        <div className="w-full md:w-80 flex-shrink-0">
          <ErrorBoundary>
            <HierarchicalFilters 
              locations={Object.values(data?.locations || {}).map(location => ({
                ...location,
                name: location.name || location.fullName || 'Unknown Location'
              }))}
              services={Object.keys(data?.specialties || {})}
              providers={Object.values(data?.providers || {}).map(provider => ({
                ...provider,
                id: provider.id || '',
                name: provider.name || 'Unknown Provider',
                specialty: provider.specialty || ''
              }))}
              onFilterChange={handleFilterChange}
              initialFilters={filters}
              className="sticky top-4"
            />
          </ErrorBoundary>
        </div>

        <div className="flex-grow">
          <ErrorBoundary>
            <AnimatePresence mode="wait">
              <motion.div
                key={activeView}
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, y: -10 }}
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

export default ORUtilizationDashboard;
