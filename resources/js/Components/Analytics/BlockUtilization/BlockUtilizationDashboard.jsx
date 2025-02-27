import React, { useState, useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import { motion, AnimatePresence } from 'framer-motion';
import { Icon } from '@iconify/react';

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

// Filter sidebar component
const FilterSidebar = ({ filters, onChange, visible }) => {
  const handleFilterChange = (e) => {
    const { name, value } = e.target;
    onChange({ ...filters, [name]: value });
  };

  return (
    <div className={`bg-white dark:bg-gray-800 rounded-lg shadow p-4 ${visible ? 'block' : 'hidden md:block'}`}>
      <h3 className="text-lg font-medium mb-4 dark:text-white">Filters</h3>
      <div className="space-y-4">
        <div>
          <label htmlFor="site" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Site</label>
          <select 
            id="site" 
            name="site" 
            value={filters.site} 
            onChange={handleFilterChange}
            className="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
          >
            <option value="all">All Sites</option>
            {Object.keys(mockBlockUtilization.sites).map((site, index) => (
              <option key={index} value={site}>{site}</option>
            ))}
          </select>
        </div>
        <div>
          <label htmlFor="service" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Service</label>
          <select 
            id="service" 
            name="service" 
            value={filters.service} 
            onChange={handleFilterChange}
            className="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
          >
            <option value="all">All Services</option>
            {mockBlockUtilization.serviceData.map((service, index) => (
              <option key={index} value={service.name}>{service.name}</option>
            ))}
          </select>
        </div>
        <div>
          <label htmlFor="timeRange" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Time Range</label>
          <select 
            id="timeRange" 
            name="timeRange" 
            value={filters.timeRange} 
            onChange={handleFilterChange}
            className="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
          >
            <option value="30">Last 30 Days</option>
            <option value="60">Last 60 Days</option>
            <option value="90">Last 90 Days</option>
            <option value="180">Last 6 Months</option>
            <option value="365">Last 12 Months</option>
          </select>
        </div>
      </div>
    </div>
  );
};

const BlockUtilizationDashboard = ({ activeView: initialActiveView }) => {
  const { url } = usePage();
  
  // Use the provided activeView prop if available, otherwise use the URL parameter
  const [activeView, setActiveView] = useState(initialActiveView || 'service');
  const [filtersVisible, setFiltersVisible] = useState(false);
  
  const [filters, setFilters] = useState({
    site: 'all',
    service: 'all',
    timeRange: '90'
  });

  // Update active view when initialActiveView prop changes
  useEffect(() => {
    if (initialActiveView) {
      setActiveView(initialActiveView);
    }
  }, [initialActiveView]);

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

  // Get visible filters based on active view
  const getVisibleFilters = () => {
    const baseFilters = ['site', 'timeRange'];
    
    switch (activeView) {
      case 'service':
        return [...baseFilters];
      case 'trend':
        return [...baseFilters, 'service'];
      case 'dayOfWeek':
        return [...baseFilters, 'service'];
      case 'location':
        return ['timeRange', 'service'];
      case 'block':
        return [...baseFilters, 'service'];
      case 'details':
        return [...baseFilters, 'service'];
      case 'nonprime':
        return [...baseFilters, 'service'];
      default:
        return baseFilters;
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
        <div className="w-full md:w-64 flex-shrink-0">
          <FilterSidebar 
            filters={filters} 
            onChange={setFilters} 
            visible={filtersVisible} 
          />
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
