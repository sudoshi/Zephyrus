import React, { useState, useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import { motion, AnimatePresence } from 'framer-motion';
import { Icon } from '@iconify/react';
import HierarchicalFilters from '@/Components/Analytics/shared/HierarchicalFilters';
import ErrorBoundary from '@/Components/ErrorBoundary';

// Import view components
import ServiceView from './Views/ServiceView';
import TrendView from './Views/TrendView';
import DayOfWeekView from './Views/DayOfWeekView';
import LocationView from './Views/LocationView';
import BlockView from './Views/BlockView';
import DetailsView from './Views/DetailsView';
import NonPrimeView from './Views/NonPrimeView';

// Import mock data
import { mockBlockUtilization } from '@/mock-data/block-utilization';

const BlockUtilizationDashboard = ({ activeView: initialActiveView }) => {
  const { url } = usePage();
  
  // Use the provided activeView prop if available, otherwise use the URL parameter
  const [activeView, setActiveView] = useState(initialActiveView || 'service');
  const [filtersVisible, setFiltersVisible] = useState(false);
  
  // Enhanced filters state to match HierarchicalFilters component
  const [filters, setFilters] = useState({
    selectedHospital: '',
    selectedLocation: '',
    selectedSpecialty: '',
    selectedSurgeon: '',
    dateRange: { 
      startDate: new Date(new Date().setDate(new Date().getDate() - 90)), 
      endDate: new Date() 
    },
    comparisonDateRange: { 
      startDate: new Date(new Date().setDate(new Date().getDate() - 180)), 
      endDate: new Date(new Date().setDate(new Date().getDate() - 91)) 
    },
    showComparison: false
  });

  // Update active view when initialActiveView prop changes
  useEffect(() => {
    if (initialActiveView) {
      setActiveView(initialActiveView);
    }
  }, [initialActiveView]);

  // Handle filter changes from HierarchicalFilters
  const handleFilterChange = (newFilters) => {
    setFilters(newFilters);
  };

  // Get the appropriate view component based on activeView
  const getViewComponent = () => {
    switch (activeView) {
      case 'service':
        return <ServiceView filters={filters} />;
      case 'trend':
        return <TrendView filters={filters} />;
      case 'dayOfWeek':
        return <DayOfWeekView filters={filters} />;
      case 'location':
        return <LocationView filters={filters} />;
      case 'block':
        return <BlockView filters={filters} />;
      case 'details':
        return <DetailsView filters={filters} />;
      case 'nonprime':
        return <NonPrimeView filters={filters} />;
      default:
        return <ServiceView filters={filters} />;
    }
  };

  // Toggle mobile filters visibility
  const toggleFilters = () => {
    setFiltersVisible(!filtersVisible);
  };

  // Format locations data for HierarchicalFilters
  const formatLocationsData = () => {
    return Object.keys(mockBlockUtilization.sites).map(site => ({
      id: site,
      name: site,
      hospitalId: site.split(' ')[0] // Extract hospital ID from site name (e.g., 'MARH' from 'MARH OR')
    }));
  };

  // Format services data for HierarchicalFilters
  const formatServicesData = () => {
    const services = new Set();
    Object.values(mockBlockUtilization.sites).forEach(site => {
      site.services.forEach(service => {
        services.add(service.service_name);
      });
    });
    return Array.from(services);
  };

  // Format providers data for HierarchicalFilters (if available)
  const formatProvidersData = () => {
    // In a real application, this would come from the API
    // For now, return an empty array
    return [];
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
              locations={formatLocationsData()}
              services={formatServicesData()}
              providers={formatProvidersData()}
              onFilterChange={handleFilterChange}
              initialFilters={filters}
              className="sticky top-4"
            />
          </ErrorBoundary>
        </div>

        <div className="flex-grow">
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
        </div>
      </div>
    </div>
  );
};

export default BlockUtilizationDashboard;
