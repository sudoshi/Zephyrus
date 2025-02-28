import React, { useState, useEffect } from 'react';
import PropTypes from 'prop-types';
import { mockRoomRunning } from '@/mock-data/room-running';
import HierarchicalFilters from '@/Components/Analytics/shared/HierarchicalFilters';
import { motion } from 'framer-motion';
import ErrorBoundary from '@/Components/ErrorBoundary';

// Import view components
import OverviewView from './Views/OverviewView';
import HourlyAnalysisView from './Views/HourlyAnalysisView';
import TrendsView from './Views/TrendsView';
import LocationComparisonView from './Views/LocationComparisonView';
import ServiceAnalysisView from './Views/ServiceAnalysisView';

/**
 * Room Running Dashboard Component
 * Displays comprehensive room running metrics and visualizations
 */
const RoomRunningDashboard = ({ activeView = 'overview' }) => {
  // State for filters
  const [filters, setFilters] = useState({
    selectedHospital: '',
    selectedLocation: '',
    selectedSpecialty: '',
    selectedSurgeon: '',
    dateRange: { 
      startDate: new Date(new Date().setDate(new Date().getDate() - 90)), 
      endDate: new Date() 
    },
    showComparison: false,
    comparisonDateRange: { 
      startDate: new Date(new Date().setDate(new Date().getDate() - 180)), 
      endDate: new Date(new Date().setDate(new Date().getDate() - 91)) 
    }
  });

  // Handle filter changes
  const handleFilterChange = (newFilters) => {
    setFilters(newFilters);
  };

  // Format locations data for HierarchicalFilters
  const formatLocationsData = () => {
    return Object.keys(mockRoomRunning.sites).map(site => ({
      id: site,
      name: site,
      hospitalId: site.split(' ')[0] // Extract hospital ID from site name (e.g., 'MARH' from 'MARH OR')
    }));
  };

  // Format services data for HierarchicalFilters
  const formatServicesData = () => {
    return Object.entries(mockRoomRunning.services).map(([service, data]) => ({
      id: service,
      name: service
    }));
  };

  // Animation variants for view transitions
  const variants = {
    hidden: { opacity: 0, x: -10 },
    visible: { opacity: 1, x: 0, transition: { duration: 0.3 } }
  };

  // Render the appropriate view based on activeView
  const renderView = () => {
    switch (activeView) {
      case 'overview':
        return <OverviewView filters={filters} />;
      case 'hourly':
        return <HourlyAnalysisView filters={filters} />;
      case 'trends':
        return <TrendsView filters={filters} />;
      case 'location':
        return <LocationComparisonView filters={filters} />;
      case 'service':
        return <ServiceAnalysisView filters={filters} />;
      default:
        return <OverviewView filters={filters} />;
    }
  };

  return (
    <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
      {/* Left Sidebar - Filters */}
      <div className="lg:col-span-1">
        <HierarchicalFilters
          locations={formatLocationsData()}
          services={formatServicesData()}
          providers={[]}
          onFilterChange={handleFilterChange}
          initialFilters={filters}
        />
      </div>

      {/* Main Content Area */}
      <div className="lg:col-span-3">
        <ErrorBoundary>
          <motion.div
            key={activeView}
            initial="hidden"
            animate="visible"
            variants={variants}
          >
            {renderView()}
          </motion.div>
        </ErrorBoundary>
      </div>
    </div>
  );
};

RoomRunningDashboard.propTypes = {
  activeView: PropTypes.string
};

export default RoomRunningDashboard;
