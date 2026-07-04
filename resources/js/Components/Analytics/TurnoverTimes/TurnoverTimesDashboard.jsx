import React, { useState, useEffect } from 'react';
import PropTypes from 'prop-types';
// NOTE: Explicit .js extension is required here for CI/CD build compatibility
// This is an exception to our standard import pattern (no extensions)
import { useAnalyticsData } from '@/Hooks/useAnalyticsData.js';
import HierarchicalFilters from '@/Components/Analytics/shared/HierarchicalFilters';
import OverviewView from './Views/OverviewView';
import TrendsView from './Views/TrendsView';
import HourlyAnalysisView from './Views/HourlyAnalysisView';
import LocationComparisonView from './Views/LocationComparisonView';
import ServiceAnalysisView from './Views/ServiceAnalysisView';
import { AnimatePresence, motion } from 'framer-motion';
import ErrorBoundary from '@/Components/ErrorBoundary';

export default function TurnoverTimesDashboard({ activeView = 'overview', data = null }) {
  // P5: live payload only (TurnoverService via the controller) — the bundled
  // mock fallback is gone; an empty period gets an honest empty state.
  const mockTurnoverTimes = data;
  // State for filters
  const [filters, setFilters] = useState({
    selectedHospital: '',
    selectedLocation: '',
    selectedSpecialty: '',
    selectedSurgeon: '',
    startDate: new Date(new Date().setDate(new Date().getDate() - 30)),
    endDate: new Date(),
    showComparison: false,
    compStartDate: new Date(new Date().setDate(new Date().getDate() - 60)),
    compEndDate: new Date(new Date().setDate(new Date().getDate() - 30))
  });

  // Honest empty state — never fabricate turnover numbers.
  if (!mockTurnoverTimes || !mockTurnoverTimes.sites || Object.keys(mockTurnoverTimes.sites).length === 0) {
    return (
      <div className="p-8 text-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        No turnover data is available for this period.
      </div>
    );
  }

  // Format locations data for HierarchicalFilters
  const formatLocationsData = () => {
    return Object.keys(mockTurnoverTimes.sites).map(site => ({
      id: site,
      name: site,
      hospitalId: site.split(' ')[0].toLowerCase() // Extract hospital ID from site name (e.g., 'marh' from 'MARH OR')
    }));
  };

  // Format services data for HierarchicalFilters
  const formatServicesData = () => {
    return Object.keys(mockTurnoverTimes.services).map(service => ({
      id: service,
      name: service
    }));
  };

  // Handle filter changes
  const handleFilterChange = (newFilters) => {
    setFilters(prevFilters => ({ ...prevFilters, ...newFilters }));
  };

  // In a real application, this would be an API call
  const fetchTurnoverData = async () => {
    return {
      locationData: mockTurnoverTimes.sites[filters.selectedLocation] || mockTurnoverTimes.sites['MARH OR'],
      serviceData: filters.selectedSpecialty ? mockTurnoverTimes.services[filters.selectedSpecialty] : null
    };
  };

  const { isLoading, error } = useAnalyticsData(fetchTurnoverData, [
    filters.selectedHospital,
    filters.selectedLocation,
    filters.selectedSpecialty,
    filters.startDate,
    filters.endDate
  ]);
  
  if (error) {
    throw error; // This will be caught by ErrorBoundary
  }

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-healthcare-text-primary dark:border-healthcare-text-primary-dark"></div>
      </div>
    );
  }

  // Animation variants for view transitions
  const variants = {
    initial: { opacity: 0, x: 20 },
    animate: { opacity: 1, x: 0 },
    exit: { opacity: 0, x: -20 }
  };

  // Render the appropriate view based on the active tab
  const renderView = () => {
    return (
      <AnimatePresence mode="wait">
        <motion.div
          key={activeView}
          initial="initial"
          animate="animate"
          exit="exit"
          variants={variants}
          transition={{ duration: 0.3 }}
        >
          {activeView === 'overview' && <OverviewView filters={filters} data={mockTurnoverTimes} />}
          {activeView === 'hourly' && <HourlyAnalysisView filters={filters} data={mockTurnoverTimes} />}
          {activeView === 'trends' && <TrendsView filters={filters} data={mockTurnoverTimes} />}
          {activeView === 'location' && <LocationComparisonView filters={filters} data={mockTurnoverTimes} />}
          {activeView === 'service' && <ServiceAnalysisView filters={filters} data={mockTurnoverTimes} />}
        </motion.div>
      </AnimatePresence>
    );
  };

  return (
    <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
      {/* Sidebar with filters */}
      <div className="lg:col-span-1">
        <ErrorBoundary>
          <HierarchicalFilters
            locations={formatLocationsData()}
            services={formatServicesData()}
            providers={[]}
            onFilterChange={handleFilterChange}
            initialFilters={filters}
          />
        </ErrorBoundary>
      </div>
      
      {/* Main content area */}
      <div className="lg:col-span-3">
        <ErrorBoundary>
          {renderView()}
        </ErrorBoundary>
      </div>
    </div>
  );
}

TurnoverTimesDashboard.propTypes = {
  activeView: PropTypes.oneOf(['overview', 'hourly', 'trends', 'location', 'service']).isRequired,
  data: PropTypes.object
};
