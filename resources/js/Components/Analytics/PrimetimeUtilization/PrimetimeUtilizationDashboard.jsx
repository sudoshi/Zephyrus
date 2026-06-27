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
import ServiceAnalysisView from './Views/ServiceAnalysisView';

const PrimetimeUtilizationDashboard = ({ activeView = 'overview', primetime }) => {
  // Live payload from the controller (App\Services\Analytics\PrimetimeUtilizationService);
  // falls back to the bundled mock when the prop is absent (e.g. storybook / empty DB).
  const data = primetime || mockPrimetimeUtilization;
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
  const locations = Object.keys(data.sites || {});
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
        return <OverviewView filters={filters} data={data} />;
      case 'trends':
        return <TrendsView filters={filters} data={data} />;
      case 'dayOfWeek':
        return <DayOfWeekView filters={filters} data={data} />;
      case 'location':
        return <LocationComparisonView filters={filters} data={data} />;
      case 'provider':
        return <ProviderAnalysisView filters={filters} data={data} />;
      case 'service':
        return <ServiceAnalysisView filters={filters} data={data} />;
      default:
        return <OverviewView filters={filters} data={data} />;
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
