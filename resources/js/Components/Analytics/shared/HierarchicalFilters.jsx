import React, { useState, useEffect, useMemo } from 'react';
import PropTypes from 'prop-types';
import { Datepicker } from '@/Components/ui/flowbite';
import { Calendar, Filter, X, ChevronDown, ChevronUp } from 'lucide-react';
import { format } from 'date-fns';

/**
 * Hierarchical filters component for analytics dashboards
 * Provides a structured filtering approach with a clear hierarchy
 */
const HierarchicalFilters = ({
  locations = [],
  services = [],
  providers = [],
  onFilterChange,
  initialFilters = {},
  className = ''
}) => {
  // Hospital list (hardcoded as per requirements)
  const hospitals = [
    { id: 'marh', name: 'Virtua Marlton Hospital' },
    { id: 'memh', name: 'Virtua Mount Holly Hospital' },
    { id: 'ollh', name: 'Virtua Our Lady of Lourdes Hospital' },
    { id: 'vorh', name: 'Virtua Voorhees Hospital' }
  ];
  
  // Filter state
  const [selectedHospital, setSelectedHospital] = useState(initialFilters.selectedHospital || '');
  const [selectedLocation, setSelectedLocation] = useState(initialFilters.selectedLocation || '');
  const [selectedSpecialty, setSelectedSpecialty] = useState(initialFilters.selectedSpecialty || '');
  const [selectedSurgeon, setSelectedSurgeon] = useState(initialFilters.selectedSurgeon || '');
  const [startDate, setStartDate] = useState(initialFilters.startDate || new Date(2024, 9, 1)); // Oct 1, 2024
  const [endDate, setEndDate] = useState(initialFilters.endDate || new Date(2024, 11, 31)); // Dec 31, 2024
  const [showComparison, setShowComparison] = useState(initialFilters.showComparison || false);
  const [compStartDate, setCompStartDate] = useState(initialFilters.compStartDate || new Date(2024, 0, 1)); // Jan 1, 2024
  const [compEndDate, setCompEndDate] = useState(initialFilters.compEndDate || new Date(2024, 5, 30)); // Jun 30, 2024
  
  // UI state
  const [isStartDateOpen, setIsStartDateOpen] = useState(false);
  const [isEndDateOpen, setIsEndDateOpen] = useState(false);
  const [isCompStartDateOpen, setIsCompStartDateOpen] = useState(false);
  const [isCompEndDateOpen, setIsCompEndDateOpen] = useState(false);
  const [isExpanded, setIsExpanded] = useState(true);
  
  // Filtered options based on hierarchy
  const availableLocations = useMemo(() => {
    if (!selectedHospital) return [];
    
    return locations.filter(location => 
      location.hospitalId === selectedHospital || 
      (location.name && location.name.includes(selectedHospital.toUpperCase()))
    );
  }, [selectedHospital, locations]);
  
  const availableSpecialties = useMemo(() => {
    if (!selectedLocation) return [];
    
    // In a real application, you would filter specialties based on the selected location
    // For now, we'll return all specialties
    return services;
  }, [selectedLocation, services]);
  
  const availableSurgeons = useMemo(() => {
    if (!selectedSpecialty) return [];
    
    return providers.filter(provider => 
      provider.specialty && provider.specialty.includes(selectedSpecialty)
    );
  }, [selectedSpecialty, providers]);
  
  // Format dates for display
  const formattedDateRange = {
    start: format(startDate, 'MMM d, yyyy'),
    end: format(endDate, 'MMM d, yyyy'),
    compStart: format(compStartDate, 'MMM d, yyyy'),
    compEnd: format(compEndDate, 'MMM d, yyyy')
  };
  
  // Update parent component when filters change
  useEffect(() => {
    const filters = {
      selectedHospital,
      selectedLocation,
      selectedSpecialty,
      selectedSurgeon,
      dateRange: { startDate, endDate },
      comparisonDateRange: { startDate: compStartDate, endDate: compEndDate },
      showComparison
    };
    
    onFilterChange(filters);
  }, [
    selectedHospital,
    selectedLocation,
    selectedSpecialty,
    selectedSurgeon,
    startDate,
    endDate,
    compStartDate,
    compEndDate,
    showComparison
  ]);
  
  // Reset dependent filters when parent filter changes
  useEffect(() => {
    if (selectedHospital === '') {
      setSelectedLocation('');
    }
  }, [selectedHospital]);
  
  useEffect(() => {
    if (selectedLocation === '') {
      setSelectedSpecialty('');
    }
  }, [selectedLocation]);
  
  useEffect(() => {
    if (selectedSpecialty === '') {
      setSelectedSurgeon('');
    }
  }, [selectedSpecialty]);
  
  // Handle quick date selections
  const handleQuickDateSelect = (period) => {
    const today = new Date();
    let newStartDate, newEndDate;
    
    switch (period) {
      case 'last30':
        newEndDate = today;
        newStartDate = new Date(today);
        newStartDate.setDate(today.getDate() - 30);
        break;
      case 'last90':
        newEndDate = today;
        newStartDate = new Date(today);
        newStartDate.setDate(today.getDate() - 90);
        break;
      case 'lastQuarter':
        newEndDate = new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3 - 1, 0);
        newStartDate = new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3 - 3, 1);
        break;
      case 'ytd':
        newEndDate = today;
        newStartDate = new Date(today.getFullYear(), 0, 1);
        break;
      default:
        return;
    }
    
    setStartDate(newStartDate);
    setEndDate(newEndDate);
  };
  
  // Clear all filters
  const handleClearAllFilters = () => {
    setSelectedHospital('');
    setSelectedLocation('');
    setSelectedSpecialty('');
    setSelectedSurgeon('');
    setStartDate(new Date(2024, 9, 1));
    setEndDate(new Date(2024, 11, 31));
    setShowComparison(false);
    setCompStartDate(new Date(2024, 0, 1));
    setCompEndDate(new Date(2024, 5, 30));
  };
  
  // Get active filters for display
  const activeFilters = useMemo(() => {
    const filters = [];
    
    if (selectedHospital) {
      const hospital = hospitals.find(h => h.id === selectedHospital);
      filters.push({
        type: 'hospital',
        id: selectedHospital,
        label: hospital ? hospital.name : selectedHospital,
        onRemove: () => setSelectedHospital('')
      });
    }
    
    if (selectedLocation) {
      const location = locations.find(l => l.id === selectedLocation);
      filters.push({
        type: 'location',
        id: selectedLocation,
        label: location ? location.name : selectedLocation,
        onRemove: () => setSelectedLocation('')
      });
    }
    
    if (selectedSpecialty) {
      filters.push({
        type: 'specialty',
        id: selectedSpecialty,
        label: selectedSpecialty,
        onRemove: () => setSelectedSpecialty('')
      });
    }
    
    if (selectedSurgeon) {
      const surgeon = providers.find(p => p.id === selectedSurgeon);
      filters.push({
        type: 'surgeon',
        id: selectedSurgeon,
        label: surgeon ? surgeon.name : selectedSurgeon,
        onRemove: () => setSelectedSurgeon('')
      });
    }
    
    // Date range is always active, so we don't include it here
    
    if (showComparison) {
      filters.push({
        type: 'comparison',
        id: 'comparison',
        label: 'Comparison Period',
        onRemove: () => setShowComparison(false)
      });
    }
    
    return filters;
  }, [
    selectedHospital,
    selectedLocation,
    selectedSpecialty,
    selectedSurgeon,
    showComparison,
    hospitals,
    locations,
    providers
  ]);
  
  return (
    <div className={`healthcare-card ${className}`}>
      <div className="flex justify-between items-center mb-4">
        <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark flex items-center">
          <Filter className="mr-2 h-5 w-5" />
          Filters
        </h3>
        <button 
          className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark"
          onClick={() => setIsExpanded(!isExpanded)}
        >
          {isExpanded ? <ChevronUp className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />}
        </button>
      </div>
      
      {isExpanded && (
        <>
          {/* Hospital Selection (Highest Level) */}
          <div className="mb-4">
            <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-1">
              Hospital
            </label>
            <select 
              className="bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md w-full p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
              value={selectedHospital} 
              onChange={(e) => setSelectedHospital(e.target.value)}
            >
              <option value="">Select Hospital</option>
              {hospitals.map(hospital => (
                <option key={hospital.id} value={hospital.id}>
                  {hospital.name}
                </option>
              ))}
            </select>
          </div>
          
          {/* OR Locations (Second Level) */}
          <div className="mb-4">
            <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-1">
              OR Location
            </label>
            <select 
              className="bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md w-full p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
              value={selectedLocation} 
              onChange={(e) => setSelectedLocation(e.target.value)}
              disabled={!selectedHospital}
            >
              <option value="">Select OR Location</option>
              {availableLocations.map(location => (
                <option key={location.id} value={location.id}>
                  {location.name}
                </option>
              ))}
            </select>
            {!selectedHospital && (
              <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                Please select a hospital first
              </p>
            )}
          </div>
          
          {/* Specialties (Third Level) */}
          <div className="mb-4">
            <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-1">
              Specialty
            </label>
            <select 
              className="bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md w-full p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
              value={selectedSpecialty} 
              onChange={(e) => setSelectedSpecialty(e.target.value)}
              disabled={!selectedLocation}
            >
              <option value="">Select Specialty</option>
              {availableSpecialties.map(specialty => (
                <option key={typeof specialty === 'string' ? specialty : specialty.id} value={typeof specialty === 'string' ? specialty : specialty.id}>
                  {typeof specialty === 'string' ? specialty : specialty.name}
                </option>
              ))}
            </select>
            {!selectedLocation && (
              <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                Please select an OR location first
              </p>
            )}
          </div>
          
          {/* Surgeons (Fourth Level) */}
          <div className="mb-4">
            <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-1">
              Surgeon
            </label>
            <select 
              className="bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md w-full p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
              value={selectedSurgeon} 
              onChange={(e) => setSelectedSurgeon(e.target.value)}
              disabled={!selectedSpecialty}
            >
              <option value="">Select Surgeon</option>
              {availableSurgeons.map(surgeon => (
                <option key={surgeon.id} value={surgeon.id}>
                  {surgeon.name}
                </option>
              ))}
            </select>
            {!selectedSpecialty && (
              <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                Please select a specialty first
              </p>
            )}
          </div>
          
          {/* Date Range */}
          <div className="mb-4">
            <div className="flex justify-between items-center mb-2">
              <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Date Range
              </label>
              <div className="flex space-x-1">
                <button 
                  className="px-2 py-1 text-xs bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark rounded-md hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark"
                  onClick={() => handleQuickDateSelect('last30')}
                >
                  30d
                </button>
                <button 
                  className="px-2 py-1 text-xs bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark rounded-md hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark"
                  onClick={() => handleQuickDateSelect('last90')}
                >
                  90d
                </button>
                <button 
                  className="px-2 py-1 text-xs bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark rounded-md hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark"
                  onClick={() => handleQuickDateSelect('ytd')}
                >
                  YTD
                </button>
              </div>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              <div>
                <Datepicker
                  value={startDate}
                  onChange={(date) => setStartDate(date)}
                  inline={isStartDateOpen}
                  onClose={() => setIsStartDateOpen(false)}
                  maxDate={endDate}
                  theme={{
                    root: {
                      base: 'relative'
                    },
                    popup: {
                      root: {
                        base: 'absolute top-10 z-55 block pt-2'
                      }
                    }
                  }}
                  title="Start Date"
                  trigger={
                    <button 
                      className="w-full flex items-center justify-start px-3 py-2 bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
                      onClick={() => setIsStartDateOpen(!isStartDateOpen)}
                    >
                      <Calendar className="mr-2 h-4 w-4" />
                      {formattedDateRange.start}
                    </button>
                  }
                />
              </div>
              <div>
                <Datepicker
                  value={endDate}
                  onChange={(date) => setEndDate(date)}
                  inline={isEndDateOpen}
                  onClose={() => setIsEndDateOpen(false)}
                  minDate={startDate}
                  theme={{
                    root: {
                      base: 'relative'
                    },
                    popup: {
                      root: {
                        base: 'absolute top-10 z-55 block pt-2'
                      }
                    }
                  }}
                  title="End Date"
                  trigger={
                    <button 
                      className="w-full flex items-center justify-start px-3 py-2 bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
                      onClick={() => setIsEndDateOpen(!isEndDateOpen)}
                    >
                      <Calendar className="mr-2 h-4 w-4" />
                      {formattedDateRange.end}
                    </button>
                  }
                />
              </div>
            </div>
          </div>
          
          {/* Comparative Period */}
          <div className="mb-4">
            <div className="flex items-center justify-between mb-2">
              <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Comparative Period
              </label>
              <label className="inline-flex items-center cursor-pointer">
                <input 
                  type="checkbox" 
                  className="sr-only peer"
                  checked={showComparison}
                  onChange={() => setShowComparison(!showComparison)}
                />
                <div className="relative w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-healthcare-primary dark:peer-checked:bg-healthcare-primary-dark"></div>
              </label>
            </div>
            
            {showComparison && (
              <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                  <Datepicker
                    value={compStartDate}
                    onChange={(date) => setCompStartDate(date)}
                    inline={isCompStartDateOpen}
                    onClose={() => setIsCompStartDateOpen(false)}
                    maxDate={compEndDate}
                    theme={{
                      root: {
                        base: 'relative'
                      },
                      popup: {
                        root: {
                          base: 'absolute top-10 z-55 block pt-2'
                        }
                      }
                    }}
                    title="Comparative Start Date"
                    trigger={
                      <button 
                        className="w-full flex items-center justify-start px-3 py-2 bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
                        onClick={() => setIsCompStartDateOpen(!isCompStartDateOpen)}
                      >
                        <Calendar className="mr-2 h-4 w-4" />
                        {formattedDateRange.compStart}
                      </button>
                    }
                  />
                </div>
                <div>
                  <Datepicker
                    value={compEndDate}
                    onChange={(date) => setCompEndDate(date)}
                    inline={isCompEndDateOpen}
                    onClose={() => setIsCompEndDateOpen(false)}
                    minDate={compStartDate}
                    theme={{
                      root: {
                        base: 'relative'
                      },
                      popup: {
                        root: {
                          base: 'absolute top-10 z-55 block pt-2'
                        }
                      }
                    }}
                    title="Comparative End Date"
                    trigger={
                      <button 
                        className="w-full flex items-center justify-start px-3 py-2 bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
                        onClick={() => setIsCompEndDateOpen(!isCompEndDateOpen)}
                      >
                        <Calendar className="mr-2 h-4 w-4" />
                        {formattedDateRange.compEnd}
                      </button>
                    }
                  />
                </div>
              </div>
            )}
          </div>
          
          {/* Active Filters */}
          {activeFilters.length > 0 && (
            <div className="mt-4 pt-4 border-t border-healthcare-border dark:border-healthcare-border-dark">
              <div className="flex justify-between items-center mb-2">
                <h4 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Active Filters
                </h4>
                <button 
                  className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark"
                  onClick={handleClearAllFilters}
                >
                  Clear All
                </button>
              </div>
              <div className="flex flex-wrap gap-2">
                {activeFilters.map(filter => (
                  <div 
                    key={`${filter.type}-${filter.id}`}
                    className="bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark px-2 py-1 rounded-md text-xs flex items-center"
                  >
                    <span className="mr-1">{filter.type}:</span>
                    <span className="font-medium">{filter.label}</span>
                    <button 
                      className="ml-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark"
                      onClick={filter.onRemove}
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </div>
                ))}
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
};

HierarchicalFilters.propTypes = {
  locations: PropTypes.arrayOf(
    PropTypes.shape({
      id: PropTypes.string,
      name: PropTypes.string, // Made optional
      hospitalId: PropTypes.string
    })
  ),
  services: PropTypes.arrayOf(
    PropTypes.oneOfType([
      PropTypes.string,
      PropTypes.shape({
        id: PropTypes.string,
        name: PropTypes.string
      })
    ])
  ),
  providers: PropTypes.arrayOf(
    PropTypes.shape({
      id: PropTypes.string,
      name: PropTypes.string,
      specialty: PropTypes.string
    })
  ),
  onFilterChange: PropTypes.func.isRequired,
  initialFilters: PropTypes.object,
  className: PropTypes.string
};

export default HierarchicalFilters;
