import React, { useState, useEffect } from 'react';
import { Tabs } from '@/Components/ui/flowbite';
import { useORUtilizationData } from '@/hooks/useORUtilizationData';
import { useAnalytics } from '@/contexts/AnalyticsContext';
import HierarchicalFilters from '@/Components/Analytics/shared/HierarchicalFilters';
import EfficiencyMetricsCard from './EfficiencyMetricsCard';
import OpportunityMetricsCard from './OpportunityMetricsCard';
import SpecialtyDistributionCard from './SpecialtyDistributionCard';
import RoomUtilizationCard from './RoomUtilizationCard';
import UtilizationTrendsCard from './UtilizationTrendsCard';
import ErrorBoundary from '@/Components/ErrorBoundary';

/**
 * OR Utilization Dashboard Component
 * Displays comprehensive OR utilization metrics and visualizations
 */
const ORUtilizationDashboard = () => {
  // Get analytics context
  const { selectedLocation, dateRange } = useAnalytics();
  
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
  
  // Get location data for the selected location
  const getSelectedLocationData = () => {
    if (!data || !data.locations) return null;
    
    // If a specific location is selected, use that
    if (filters.selectedLocation) {
      return data.locations[filters.selectedLocation];
    }
    
    // If only a hospital is selected, find the first location for that hospital
    if (filters.selectedHospital) {
      const locationKey = Object.keys(data.locations).find(key => 
        data.locations[key].hospitalId === filters.selectedHospital
      );
      
      if (locationKey) {
        return data.locations[locationKey];
      }
    }
    
    // Default to the first location
    return data.locations[Object.keys(data.locations)[0]];
  };
  
  // Get selected location name
  const getSelectedLocationName = () => {
    const locationData = getSelectedLocationData();
    
    if (locationData) {
      return locationData.fullName || locationData.name;
    }
    
    // If no location data but hospital is selected, show hospital name
    if (filters.selectedHospital) {
      const hospitalMap = {
        'marh': 'Virtua Marlton Hospital',
        'memh': 'Virtua Mount Holly Hospital',
        'ollh': 'Virtua Our Lady of Lourdes Hospital',
        'vorh': 'Virtua Voorhees Hospital'
      };
      
      return hospitalMap[filters.selectedHospital] || 'Selected Hospital';
    }
    
    return 'All Locations';
  };
  
  // Get room data for the selected location
  const getRoomData = () => {
    const locationData = getSelectedLocationData();
    return locationData?.rooms || [];
  };
  
  // Get trends data
  const getTrendsData = () => {
    if (!data || !data.trends) return [];
    
    const locationId = filters.selectedLocation !== 'all' 
      ? filters.selectedLocation 
      : Object.keys(data.trends)[0];
    
    const locationTrends = data.trends[locationId];
    if (!locationTrends || !locationTrends.utilization) return [];
    
    // Transform the data to the expected format
    return locationTrends.utilization.map(item => ({
      date: item.month,
      utilization: item.value
    }));
  };
  
  // Render loading state
  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-healthcare-primary dark:border-healthcare-primary-dark"></div>
      </div>
    );
  }
  
  // Render error state
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
  
  // Render no data state
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
  
  // Get data for the dashboard
  const locationData = getSelectedLocationData();
  const roomData = getRoomData();
  const trendsData = getTrendsData();
  
  // Get derived metrics
  const efficiencyMetrics = derivedMetrics?.efficiencyRatio ? {
    efficiencyRatio: derivedMetrics.efficiencyRatio,
    casesPerDay: locationData?.casesPerDay || 0,
    turnoverTime: locationData?.averageTurnoverTime || 0,
    caseDuration: locationData?.averageCaseDuration || 0
  } : null;
  
  const opportunityMetrics = derivedMetrics?.opportunity ? {
    utilizationGap: derivedMetrics.opportunity.utilizationGap,
    potentialAdditionalCases: derivedMetrics.opportunity.potentialAdditionalCases,
    targetUtilization: derivedMetrics.opportunity.targetUtilization,
    currentUtilization: locationData?.utilization || 0
  } : null;
  
  return (
    <div className="flex flex-col md:flex-row gap-6">
      {/* Left sidebar with filters */}
      <div className="w-full md:w-80 flex-shrink-0">
        <ErrorBoundary>
          <HierarchicalFilters 
            locations={Object.values(data?.locations || {}).map(location => ({
              ...location,
              // Ensure all locations have a name property
              name: location.name || location.fullName || 'Unknown Location'
            }))}
            services={Object.keys(data?.specialties || {})}
            providers={Object.values(data?.providers || {}).map(provider => ({
              ...provider,
              // Ensure all providers have required properties
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
      
      {/* Main content */}
      <div className="flex-grow">
        <ErrorBoundary>
          <Tabs 
            aria-label="OR Utilization Dashboard Tabs"
            variant="underline"
            className="mb-6"
          >
            {/* Overview Tab */}
            <Tabs.Item title="Overview" icon={() => <span className="mr-2">📊</span>}>
              <div className="mb-4">
                <h2 className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  OR Utilization Overview: {getSelectedLocationName()}
                </h2>
                <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  Comprehensive view of operating room utilization metrics and opportunities.
                </p>
              </div>
              
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                {efficiencyMetrics && (
                  <EfficiencyMetricsCard {...efficiencyMetrics} />
                )}
                
                {opportunityMetrics && (
                  <OpportunityMetricsCard {...opportunityMetrics} />
                )}
              </div>
              
              <div className="mb-6">
                <UtilizationTrendsCard 
                  trendsData={trendsData}
                  comparisonTrendsData={[]} // We'll implement comparison data in a future update
                  showComparison={false} // Disable comparison for now until we implement it properly
                />
              </div>
              
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <SpecialtyDistributionCard 
                  specialtyData={data?.specialties || {}}
                />
                
                <RoomUtilizationCard 
                  roomData={roomData}
                />
              </div>
            </Tabs.Item>
            
            {/* Trends Tab */}
            <Tabs.Item title="Trends" icon={() => <span className="mr-2">📈</span>}>
              <div className="mb-4">
                <h2 className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Utilization Trends: {getSelectedLocationName()}
                </h2>
                <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  Historical trends and comparative analysis of OR utilization.
                </p>
              </div>
              
              <div className="mb-6">
                <UtilizationTrendsCard 
                  trendsData={trendsData}
                  comparisonTrendsData={[]} // We'll implement comparison data in a future update
                  showComparison={false} // Disable comparison for now until we implement it properly
                  className="h-full"
                />
              </div>
              
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-6 rounded-lg shadow-sm">
                  <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
                    Utilization by Day of Week
                  </h3>
                  <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Day of week analysis will be displayed here.
                  </p>
                </div>
                
                <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-6 rounded-lg shadow-sm">
                  <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
                    Utilization by Time of Day
                  </h3>
                  <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Time of day analysis will be displayed here.
                  </p>
                </div>
              </div>
            </Tabs.Item>
            
            {/* Room Analysis Tab */}
            <Tabs.Item title="Room Analysis" icon={() => <span className="mr-2">🏥</span>}>
              <div className="mb-4">
                <h2 className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Room Analysis: {getSelectedLocationName()}
                </h2>
                <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  Detailed analysis of individual operating room performance.
                </p>
              </div>
              
              <div className="mb-6">
                <RoomUtilizationCard 
                  roomData={roomData}
                  className="h-full"
                />
              </div>
              
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-6 rounded-lg shadow-sm">
                  <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
                    Room Turnover Analysis
                  </h3>
                  <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Room turnover analysis will be displayed here.
                  </p>
                </div>
                
                <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-6 rounded-lg shadow-sm">
                  <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
                    Room Scheduling Accuracy
                  </h3>
                  <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Room scheduling accuracy analysis will be displayed here.
                  </p>
                </div>
              </div>
            </Tabs.Item>
            
            {/* Specialty Analysis Tab */}
            <Tabs.Item title="Specialty Analysis" icon={() => <span className="mr-2">👨‍⚕️</span>}>
              <div className="mb-4">
                <h2 className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Specialty Analysis: {getSelectedLocationName()}
                </h2>
                <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  Utilization metrics broken down by surgical specialty.
                </p>
              </div>
              
              <div className="mb-6">
                <SpecialtyDistributionCard 
                  specialtyData={data?.specialties || {}}
                  className="h-full"
                />
              </div>
              
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-6 rounded-lg shadow-sm">
                  <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
                    Specialty Turnover Times
                  </h3>
                  <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Specialty turnover time analysis will be displayed here.
                  </p>
                </div>
                
                <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-6 rounded-lg shadow-sm">
                  <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
                    Specialty Case Duration Accuracy
                  </h3>
                  <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Specialty case duration accuracy analysis will be displayed here.
                  </p>
                </div>
              </div>
            </Tabs.Item>
            
            {/* Opportunity Analysis Tab */}
            <Tabs.Item title="Opportunity Analysis" icon={() => <span className="mr-2">💡</span>}>
              <div className="mb-4">
                <h2 className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Opportunity Analysis: {getSelectedLocationName()}
                </h2>
                <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  Identify opportunities to improve OR utilization and efficiency.
                </p>
              </div>
              
              <div className="mb-6">
                {opportunityMetrics && (
                  <OpportunityMetricsCard {...opportunityMetrics} className="h-full" />
                )}
              </div>
              
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-6 rounded-lg shadow-sm">
                  <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
                    Block Time Optimization
                  </h3>
                  <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Block time optimization recommendations will be displayed here.
                  </p>
                </div>
                
                <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-6 rounded-lg shadow-sm">
                  <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
                    Efficiency Improvement Opportunities
                  </h3>
                  <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Efficiency improvement opportunities will be displayed here.
                  </p>
                </div>
              </div>
            </Tabs.Item>
          </Tabs>
        </ErrorBoundary>
      </div>
    </div>
  );
};

export default ORUtilizationDashboard;
