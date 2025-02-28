import React, { useState } from 'react';
import PropTypes from 'prop-types';
import { mockPrimetimeUtilization } from '../../../mock-data/primetime-utilization';
import HierarchicalFilters from '../HierarchicalFilters';
import { motion } from 'framer-motion';

// Import view components
import OverviewView from './Views/OverviewView';
import TrendsView from './Views/TrendsView';
import DayOfWeekView from './Views/DayOfWeekView';
import LocationComparisonView from './Views/LocationComparisonView';
import ProviderAnalysisView from './Views/ProviderAnalysisView';

const PrimetimeUtilizationDashboard = ({ activeView = 'overview' }) => {
  // State for filters
  const [filters, setFilters] = useState({
    selectedHospital: '',
    selectedLocation: '',
    selectedSpecialty: '',
    dateRange: { start: null, end: null }
  });

  // Handle filter changes
  const handleFilterChange = (filterType, value) => {
    setFilters(prevFilters => ({
      ...prevFilters,
      [filterType]: value
    }));
  };

  // Get available hospitals, locations, and specialties from mock data
  const hospitals = ['MARH', 'MASH', 'MACH'];
  const locations = Object.keys(mockPrimetimeUtilization.sites || {});
  const specialties = ['Orthopedics', 'General Surgery', 'Cardiology', 'Neurosurgery', 'Urology'];

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
      case 'trends':
        return <TrendsView filters={filters} />;
      case 'dayOfWeek':
        return <DayOfWeekView filters={filters} />;
      case 'location':
        return <LocationComparisonView filters={filters} />;
      case 'provider':
        return <ProviderAnalysisView filters={filters} />;
      default:
        return <OverviewView filters={filters} />;
    }
  };

  return (
    <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
      {/* Left Sidebar - Filters */}
      <div className="lg:col-span-1">
        <HierarchicalFilters
          hospitals={hospitals}
          locations={locations}
          specialties={specialties}
          selectedHospital={filters.selectedHospital}
          selectedLocation={filters.selectedLocation}
          selectedSpecialty={filters.selectedSpecialty}
          dateRange={filters.dateRange}
          onHospitalChange={(hospital) => handleFilterChange('selectedHospital', hospital)}
          onLocationChange={(location) => handleFilterChange('selectedLocation', location)}
          onSpecialtyChange={(specialty) => handleFilterChange('selectedSpecialty', specialty)}
          onDateRangeChange={(range) => handleFilterChange('dateRange', range)}
        />
      </div>

      {/* Main Content Area */}
      <div className="lg:col-span-3">
        <motion.div
          key={activeView}
          initial="hidden"
          animate="visible"
          variants={variants}
        >
          {renderView()}
        </motion.div>
      </div>
    </div>
  );
};

PrimetimeUtilizationDashboard.propTypes = {
  activeView: PropTypes.string
};

export default PrimetimeUtilizationDashboard;
