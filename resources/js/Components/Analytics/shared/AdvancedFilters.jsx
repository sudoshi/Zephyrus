import React, { useState, useEffect, useMemo } from 'react';
import PropTypes from 'prop-types';
import { Button, Datepicker } from '@/Components/ui/flowbite';
import { Calendar, Filter, X, ChevronDown, ChevronUp, Save, Clock, RotateCcw } from 'lucide-react';
import { format } from 'date-fns';

/**
 * Advanced filters component for analytics dashboards
 * Provides sophisticated filtering capabilities with presets and history
 */
const AdvancedFilters = ({
  locations = [],
  services = [],
  providers = [],
  onFilterChange,
  initialFilters = {},
  showServiceFilter = true,
  showProviderFilter = true,
  showDayOfWeekFilter = true,
  showComparisonPeriod = true,
  className = ''
}) => {
  // Filter state
  const [selectedLocation, setSelectedLocation] = useState(initialFilters.selectedLocation || 'all');
  const [selectedService, setSelectedService] = useState(initialFilters.selectedService || null);
  const [selectedProvider, setSelectedProvider] = useState(initialFilters.selectedProvider || null);
  const [startDate, setStartDate] = useState(initialFilters.startDate || new Date(2024, 9, 1)); // Oct 1, 2024
  const [endDate, setEndDate] = useState(initialFilters.endDate || new Date(2024, 11, 31)); // Dec 31, 2024
  const [compStartDate, setCompStartDate] = useState(initialFilters.compStartDate || new Date(2024, 0, 1)); // Jan 1, 2024
  const [compEndDate, setCompEndDate] = useState(initialFilters.compEndDate || new Date(2024, 5, 30)); // Jun 30, 2024
  const [selectedDays, setSelectedDays] = useState(initialFilters.selectedDays || ['All']);
  const [showComparison, setShowComparison] = useState(initialFilters.showComparison || false);
  
  // UI state
  const [isStartDateOpen, setIsStartDateOpen] = useState(false);
  const [isEndDateOpen, setIsEndDateOpen] = useState(false);
  const [isCompStartDateOpen, setIsCompStartDateOpen] = useState(false);
  const [isCompEndDateOpen, setIsCompEndDateOpen] = useState(false);
  const [showDaysDropdown, setShowDaysDropdown] = useState(false);
  const [isExpanded, setIsExpanded] = useState(true);
  const [showPresets, setShowPresets] = useState(false);
  const [presets, setPresets] = useState([
    { name: 'Last 30 Days', filters: { startDate: new Date(2024, 10, 1), endDate: new Date(2024, 10, 30) } },
    { name: 'Last Quarter', filters: { startDate: new Date(2024, 6, 1), endDate: new Date(2024, 8, 30) } }
  ]);
  const [filterHistory, setFilterHistory] = useState([]);
  const [historyIndex, setHistoryIndex] = useState(-1);
  
  // Available options based on selections
  const availableServices = useMemo(() => {
    if (selectedLocation === 'all') {
      return services;
    }
    
    // Filter services based on selected location
    // This is a simplified approach - in a real application, you would filter based on actual data relationships
    return services;
  }, [selectedLocation, services]);
  
  const availableProviders = useMemo(() => {
    if (selectedLocation === 'all' && !selectedService) {
      return providers;
    }
    
    // Filter providers based on selected location and service
    // This is a simplified approach - in a real application, you would filter based on actual data relationships
    if (selectedService) {
      return providers.filter(provider => 
        provider.specialty && provider.specialty.includes(selectedService)
      );
    }
    
    return providers;
  }, [selectedLocation, selectedService, providers]);
  
  const dayOptions = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
  
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
      selectedLocation,
      selectedService,
      selectedProvider,
      dateRange: { startDate, endDate },
      comparisonDateRange: { startDate: compStartDate, endDate: compEndDate },
      selectedDays,
      showComparison
    };
    
    // Add to history if this is a new filter state
    if (historyIndex === filterHistory.length - 1 || historyIndex === -1) {
      setFilterHistory(prev => [...prev, filters]);
      setHistoryIndex(prev => prev + 1);
    }
    
    onFilterChange(filters);
  }, [
    selectedLocation, 
    selectedService, 
    selectedProvider, 
    startDate, 
    endDate, 
    compStartDate, 
    compEndDate, 
    selectedDays,
    showComparison
  ]);
  
  // Handle preset selection
  const handlePresetSelect = (preset) => {
    const { filters } = preset;
    
    if (filters.startDate) setStartDate(filters.startDate);
    if (filters.endDate) setEndDate(filters.endDate);
    if (filters.selectedLocation) setSelectedLocation(filters.selectedLocation);
    if (filters.selectedService) setSelectedService(filters.selectedService);
    if (filters.selectedProvider) setSelectedProvider(filters.selectedProvider);
    if (filters.selectedDays) setSelectedDays(filters.selectedDays);
    if (filters.showComparison !== undefined) setShowComparison(filters.showComparison);
    if (filters.compStartDate) setCompStartDate(filters.compStartDate);
    if (filters.compEndDate) setCompEndDate(filters.compEndDate);
    
    setShowPresets(false);
  };
  
  // Handle saving current filters as a preset
  const handleSavePreset = () => {
    const presetName = prompt('Enter a name for this preset:');
    if (!presetName) return;
    
    const newPreset = {
      name: presetName,
      filters: {
        startDate,
        endDate,
        selectedLocation,
        selectedService,
        selectedProvider,
        selectedDays,
        showComparison,
        compStartDate,
        compEndDate
      }
    };
    
    setPresets(prev => [...prev, newPreset]);
  };
  
  // Handle undo/redo
  const handleUndo = () => {
    if (historyIndex > 0) {
      const newIndex = historyIndex - 1;
      const previousFilters = filterHistory[newIndex];
      
      setSelectedLocation(previousFilters.selectedLocation);
      setSelectedService(previousFilters.selectedService);
      setSelectedProvider(previousFilters.selectedProvider);
      setStartDate(previousFilters.dateRange.startDate);
      setEndDate(previousFilters.dateRange.endDate);
      setCompStartDate(previousFilters.comparisonDateRange.startDate);
      setCompEndDate(previousFilters.comparisonDateRange.endDate);
      setSelectedDays(previousFilters.selectedDays);
      setShowComparison(previousFilters.showComparison);
      
      setHistoryIndex(newIndex);
    }
  };
  
  const handleRedo = () => {
    if (historyIndex < filterHistory.length - 1) {
      const newIndex = historyIndex + 1;
      const nextFilters = filterHistory[newIndex];
      
      setSelectedLocation(nextFilters.selectedLocation);
      setSelectedService(nextFilters.selectedService);
      setSelectedProvider(nextFilters.selectedProvider);
      setStartDate(nextFilters.dateRange.startDate);
      setEndDate(nextFilters.dateRange.endDate);
      setCompStartDate(nextFilters.comparisonDateRange.startDate);
      setCompEndDate(nextFilters.comparisonDateRange.endDate);
      setSelectedDays(nextFilters.selectedDays);
      setShowComparison(nextFilters.showComparison);
      
      setHistoryIndex(newIndex);
    }
  };
  
  // Handle day selection
  const handleDaySelect = (day) => {
    if (day === 'All') {
      setSelectedDays(['All']);
    } else {
      const newSelectedDays = [...selectedDays];
      
      if (newSelectedDays.includes(day)) {
        // Remove day if already selected
        const filteredDays = newSelectedDays.filter(d => d !== day);
        setSelectedDays(filteredDays.length ? filteredDays : ['All']);
      } else {
        // Add day and remove 'All' if present
        const filteredDays = newSelectedDays.filter(d => d !== 'All');
        setSelectedDays([...filteredDays, day]);
      }
    }
  };
  
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
  
  return (
    <div className={`healthcare-card ${className}`}>
      <div className="flex justify-between items-center mb-4">
        <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark flex items-center">
          <Filter className="mr-2 h-5 w-5" />
          Filters
        </h3>
        <div className="flex space-x-2">
          <Button 
            size="xs"
            color="light"
            onClick={handleSavePreset}
            title="Save current filters as preset"
          >
            <Save className="h-4 w-4" />
          </Button>
          <Button 
            size="xs"
            color="light"
            onClick={() => setShowPresets(!showPresets)}
            title="Load preset filters"
          >
            <Clock className="h-4 w-4" />
          </Button>
          <Button 
            size="xs"
            color="light"
            onClick={handleUndo}
            disabled={historyIndex <= 0}
            title="Undo filter change"
          >
            <RotateCcw className="h-4 w-4" />
          </Button>
          <Button 
            size="xs"
            color="light"
            onClick={() => setIsExpanded(!isExpanded)}
          >
            {isExpanded ? <ChevronUp className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />}
          </Button>
        </div>
      </div>
      
      {/* Presets dropdown */}
      {showPresets && (
        <div className="mb-4 p-2 border border-healthcare-border dark:border-healthcare-border-dark rounded-md bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark">
          <h4 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">Saved Presets</h4>
          <div className="space-y-1 max-h-40 overflow-y-auto">
            {presets.map((preset, index) => (
              <button
                key={index}
                className="w-full text-left px-2 py-1 text-sm rounded-md hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark"
                onClick={() => handlePresetSelect(preset)}
              >
                {preset.name}
              </button>
            ))}
          </div>
        </div>
      )}
      
      {isExpanded && (
        <>
          {/* Current Period */}
          <div className="mb-4">
            <div className="flex justify-between items-center mb-2">
              <h4 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Current Period</h4>
              <div className="flex space-x-1">
                <Button size="xs" color="light" onClick={() => handleQuickDateSelect('last30')}>30d</Button>
                <Button size="xs" color="light" onClick={() => handleQuickDateSelect('last90')}>90d</Button>
                <Button size="xs" color="light" onClick={() => handleQuickDateSelect('lastQuarter')}>Q</Button>
                <Button size="xs" color="light" onClick={() => handleQuickDateSelect('ytd')}>YTD</Button>
              </div>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              <div>
                <label className="block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Start Date</label>
                <Datepicker
                  value={startDate}
                  onSelectedDateChanged={(date) => setStartDate(date)}
                  inline={isStartDateOpen}
                  onClose={() => setIsStartDateOpen(false)}
                  maxDate={endDate}
                  theme={{
                    root: {
                      base: 'relative'
                    }
                  }}
                  title="Start Date"
                  trigger={
                    <Button 
                      color="primary" 
                      outline={true}
                      className="w-full justify-start text-left font-normal"
                      onClick={() => setIsStartDateOpen(!isStartDateOpen)}
                    >
                      <Calendar className="mr-2 h-4 w-4" />
                      {formattedDateRange.start}
                    </Button>
                  }
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">End Date</label>
                <Datepicker
                  value={endDate}
                  onSelectedDateChanged={(date) => setEndDate(date)}
                  inline={isEndDateOpen}
                  onClose={() => setIsEndDateOpen(false)}
                  minDate={startDate}
                  theme={{
                    root: {
                      base: 'relative'
                    }
                  }}
                  title="End Date"
                  trigger={
                    <Button 
                      color="primary" 
                      outline={true}
                      className="w-full justify-start text-left font-normal"
                      onClick={() => setIsEndDateOpen(!isEndDateOpen)}
                    >
                      <Calendar className="mr-2 h-4 w-4" />
                      {formattedDateRange.end}
                    </Button>
                  }
                />
              </div>
            </div>
          </div>
          
          {/* Comparative Period */}
          {showComparisonPeriod && (
            <div className="mb-4">
              <div className="flex items-center justify-between mb-2">
                <h4 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Comparative Period</h4>
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
                    <label className="block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Start Date</label>
                    <Datepicker
                      value={compStartDate}
                      onSelectedDateChanged={(date) => setCompStartDate(date)}
                      inline={isCompStartDateOpen}
                      onClose={() => setIsCompStartDateOpen(false)}
                      maxDate={compEndDate}
                      theme={{
                        root: {
                          base: 'relative'
                        }
                      }}
                      title="Comparative Start Date"
                      trigger={
                        <Button 
                          color="secondary" 
                          outline={true}
                          className="w-full justify-start text-left font-normal"
                          onClick={() => setIsCompStartDateOpen(!isCompStartDateOpen)}
                        >
                          <Calendar className="mr-2 h-4 w-4" />
                          {formattedDateRange.compStart}
                        </Button>
                      }
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">End Date</label>
                    <Datepicker
                      value={compEndDate}
                      onSelectedDateChanged={(date) => setCompEndDate(date)}
                      inline={isCompEndDateOpen}
                      onClose={() => setIsCompEndDateOpen(false)}
                      minDate={compStartDate}
                      theme={{
                        root: {
                          base: 'relative'
                        }
                      }}
                      title="Comparative End Date"
                      trigger={
                        <Button 
                          color="secondary" 
                          outline={true}
                          className="w-full justify-start text-left font-normal"
                          onClick={() => setIsCompEndDateOpen(!isCompEndDateOpen)}
                        >
                          <Calendar className="mr-2 h-4 w-4" />
                          {formattedDateRange.compEnd}
                        </Button>
                      }
                    />
                  </div>
                </div>
              )}
            </div>
          )}
          
          {/* Location */}
          <div className="mb-4">
            <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-1">Location</label>
            <select 
              className="bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md w-full p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
              value={selectedLocation} 
              onChange={(e) => {
                setSelectedLocation(e.target.value);
                // Reset service and provider when location changes
                setSelectedService(null);
                setSelectedProvider(null);
              }}
            >
              <option value="all">(All Locations)</option>
              {locations.map(location => (
                <option key={location.id} value={location.id || location.name}>
                  {location.name}
                </option>
              ))}
            </select>
          </div>
          
          {/* Service */}
          {showServiceFilter && (
            <div className="mb-4">
              <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-1">Service</label>
              <select 
                className="bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md w-full p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
                value={selectedService || ''} 
                onChange={(e) => {
                  setSelectedService(e.target.value || null);
                  // Reset provider when service changes
                  setSelectedProvider(null);
                }}
              >
                <option value="">(All Services)</option>
                {availableServices.map(service => (
                  <option key={service.id || service} value={service.id || service}>
                    {service.name || service}
                  </option>
                ))}
              </select>
            </div>
          )}
          
          {/* Provider */}
          {showProviderFilter && selectedService && (
            <div className="mb-4">
              <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-1">Provider</label>
              <select 
                className="bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md w-full p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
                value={selectedProvider || ''} 
                onChange={(e) => setSelectedProvider(e.target.value || null)}
              >
                <option value="">(All Providers)</option>
                {availableProviders.map(provider => (
                  <option key={provider.id} value={provider.id}>
                    {provider.name}
                  </option>
                ))}
              </select>
            </div>
          )}
          
          {/* Day of Week */}
          {showDayOfWeekFilter && (
            <div className="mb-4">
              <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-1">Day of Week</label>
              <div className="relative">
                <button 
                  className="bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md w-full p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark text-left flex justify-between items-center"
                  onClick={() => setShowDaysDropdown(!showDaysDropdown)}
                >
                  <span>{selectedDays.join(', ')}</span>
                  <span className="ml-2">▼</span>
                </button>
                {showDaysDropdown && (
                  <div className="absolute top-full left-0 w-full mt-1 bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md shadow-lg z-10">
                    <div className="p-2 border-b border-healthcare-border dark:border-healthcare-border-dark">
                      <label className="flex items-center">
                        <input 
                          type="checkbox" 
                          className="mr-2" 
                          checked={selectedDays.includes('All')}
                          onChange={() => handleDaySelect('All')}
                        />
                        <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">All Days</span>
                      </label>
                    </div>
                    {dayOptions.map(day => (
                      <div key={day} className="p-2 hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark">
                        <label className="flex items-center">
                          <input 
                            type="checkbox" 
                            className="mr-2"
                            checked={selectedDays.includes(day)}
                            onChange={() => handleDaySelect(day)}
                          />
                          <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{day}</span>
                        </label>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>
          )}
          
          {/* Active Filters Summary */}
          <div className="mt-4 pt-4 border-t border-healthcare-border dark:border-healthcare-border-dark">
            <h4 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">Active Filters</h4>
            <div className="flex flex-wrap gap-2">
              <div className="bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark px-2 py-1 rounded-md text-xs flex items-center">
                <span className="mr-1">Location:</span>
                <span className="font-medium">{selectedLocation === 'all' ? 'All' : selectedLocation}</span>
              </div>
              
              {selectedService && (
                <div className="bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark px-2 py-1 rounded-md text-xs flex items-center">
                  <span className="mr-1">Service:</span>
                  <span className="font-medium">{selectedService}</span>
                  <button 
                    className="ml-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark"
                    onClick={() => setSelectedService(null)}
                  >
                    <X className="h-3 w-3" />
                  </button>
                </div>
              )}
              
              {selectedProvider && (
                <div className="bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark px-2 py-1 rounded-md text-xs flex items-center">
                  <span className="mr-1">Provider:</span>
                  <span className="font-medium">{selectedProvider}</span>
                  <button 
                    className="ml-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark"
                    onClick={() => setSelectedProvider(null)}
                  >
                    <X className="h-3 w-3" />
                  </button>
                </div>
              )}
              
              {!selectedDays.includes('All') && (
                <div className="bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark px-2 py-1 rounded-md text-xs flex items-center">
                  <span className="mr-1">Days:</span>
                  <span className="font-medium">{selectedDays.join(', ')}</span>
                  <button 
                    className="ml-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark"
                    onClick={() => setSelectedDays(['All'])}
                  >
                    <X className="h-3 w-3" />
                  </button>
                </div>
              )}
              
              {showComparison && (
                <div className="bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark px-2 py-1 rounded-md text-xs flex items-center">
                  <span className="mr-1">Comparison:</span>
                  <span className="font-medium">Enabled</span>
                  <button 
                    className="ml-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark"
                    onClick={() => setShowComparison(false)}
                  >
                    <X className="h-3 w-3" />
                  </button>
                </div>
              )}
            </div>
          </div>
        </>
      )}
    </div>
  );
};

AdvancedFilters.propTypes = {
  locations: PropTypes.arrayOf(
    PropTypes.shape({
      id: PropTypes.string,
      name: PropTypes.string.isRequired
    })
  ),
  services: PropTypes.arrayOf(
    PropTypes.oneOfType([
      PropTypes.string,
      PropTypes.shape({
        id: PropTypes.string,
        name: PropTypes.string.isRequired
      })
    ])
  ),
  providers: PropTypes.arrayOf(
    PropTypes.shape({
      id: PropTypes.string.isRequired,
      name: PropTypes.string.isRequired,
      specialty: PropTypes.string
    })
  ),
  onFilterChange: PropTypes.func.isRequired,
  initialFilters: PropTypes.object,
  showServiceFilter: PropTypes.bool,
  showProviderFilter: PropTypes.bool,
  showDayOfWeekFilter: PropTypes.bool,
  showComparisonPeriod: PropTypes.bool,
  className: PropTypes.string
};

export default AdvancedFilters;
