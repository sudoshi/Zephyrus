import React, { useState, useEffect } from 'react';
import PropTypes from 'prop-types';
import { Datepicker } from '@/Components/ui/flowbite';
import { Calendar, Filter, X, ChevronDown, ChevronUp } from 'lucide-react';
import { format } from 'date-fns';

/**
 * Process-specific filters component for Process Analysis workflows
 * Simplified version with only Hospital, Unit, and Specialty dropdowns
 */
const ProcessFilters = ({
  hospitals = [],
  units = [],
  specialties = [],
  onFilterChange,
  initialFilters = {},
  className = ''
}) => {
  // Filter state
  const [selectedHospital, setSelectedHospital] = useState(initialFilters.selectedHospital || '');
  const [selectedUnit, setSelectedUnit] = useState(initialFilters.selectedUnit || '');
  const [selectedSpecialty, setSelectedSpecialty] = useState(initialFilters.selectedSpecialty || '');
  const [startDate, setStartDate] = useState(initialFilters.startDate || new Date(2024, 9, 1)); // Oct 1, 2024
  const [endDate, setEndDate] = useState(initialFilters.endDate || new Date(2024, 11, 31)); // Dec 31, 2024
  const [compStartDate, setCompStartDate] = useState(initialFilters.compStartDate || new Date(2024, 0, 1)); // Jan 1, 2024
  const [compEndDate, setCompEndDate] = useState(initialFilters.compEndDate || new Date(2024, 5, 30)); // Jun 30, 2024
  const [showComparison, setShowComparison] = useState(initialFilters.showComparison || false);
  
  // UI state
  const [isStartDateOpen, setIsStartDateOpen] = useState(false);
  const [isEndDateOpen, setIsEndDateOpen] = useState(false);
  const [isCompStartDateOpen, setIsCompStartDateOpen] = useState(false);
  const [isCompEndDateOpen, setIsCompEndDateOpen] = useState(false);
  const [isExpanded, setIsExpanded] = useState(true);
  const [activeDateRange, setActiveDateRange] = useState('custom');
  
  // Reset dependent filters when parent filter changes
  useEffect(() => {
    if (selectedHospital === '') {
      setSelectedUnit('');
    }
  }, [selectedHospital]);
  
  useEffect(() => {
    if (selectedUnit === '') {
      setSelectedSpecialty('');
    }
  }, [selectedUnit]);
  
  // Handle quick date selections
  const handleQuickDateSelect = (period) => {
    const today = new Date();
    let newStartDate, newEndDate;
    
    switch (period) {
      case '30d':
        newEndDate = today;
        newStartDate = new Date(today);
        newStartDate.setDate(today.getDate() - 30);
        setActiveDateRange('30d');
        break;
      case '90d':
        newEndDate = today;
        newStartDate = new Date(today);
        newStartDate.setDate(today.getDate() - 90);
        setActiveDateRange('90d');
        break;
      case 'ytd':
        newEndDate = today;
        newStartDate = new Date(today.getFullYear(), 0, 1);
        setActiveDateRange('ytd');
        break;
      default:
        return;
    }
    
    setStartDate(newStartDate);
    setEndDate(newEndDate);
    
    // Notify parent component of changes
    if (onFilterChange) {
      onFilterChange({
        ...getFilterState(),
        startDate: newStartDate,
        endDate: newEndDate
      });
    }
  };
  
  // Get current filter state
  const getFilterState = () => ({
    selectedHospital,
    selectedUnit,
    selectedSpecialty,
    dateRange: { startDate, endDate },
    comparisonDateRange: { startDate: compStartDate, endDate: compEndDate },
    showComparison
  });
  
  // Notify parent component when filters change
  useEffect(() => {
    if (onFilterChange) {
      onFilterChange(getFilterState());
    }
  }, [
    selectedHospital,
    selectedUnit,
    selectedSpecialty,
    startDate,
    endDate,
    compStartDate,
    compEndDate,
    showComparison
  ]);
  
  // Format dates for display
  const formattedDateRange = {
    start: format(startDate, 'MMM d, yyyy'),
    end: format(endDate, 'MMM d, yyyy'),
    compStart: format(compStartDate, 'MMM d, yyyy'),
    compEnd: format(compEndDate, 'MMM d, yyyy')
  };

  return (
    <div className={`healthcare-card ${className}`}>
      {/* Header */}
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
      
      {/* Filter Content */}
      {isExpanded && (
        <>
          {/* Hospital Filter */}
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
                  <option key={hospital.id || hospital} value={hospital.id || hospital}>
                    {hospital.name || hospital}
                  </option>
                ))}
              </select>
          </div>
          
          {/* Unit Filter */}
          <div className="mb-4">
            <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-1">
              Unit
            </label>
            <select 
              className="bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md w-full p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
              value={selectedUnit}
              onChange={(e) => setSelectedUnit(e.target.value)}
              disabled={!selectedHospital}
            >
                <option value="">Select Unit</option>
                {units
                  .filter(unit => !selectedHospital || unit.hospitalId === selectedHospital)
                  .map(unit => (
                    <option key={unit.id || unit} value={unit.id || unit}>
                      {unit.name || unit}
                    </option>
                  ))}
              </select>
            {!selectedHospital && (
              <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                Please select a hospital first
              </p>
            )}
          </div>
          
          {/* Specialty Filter */}
          <div className="mb-4">
            <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-1">
              Specialty
            </label>
            <select 
              className="bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md w-full p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
              value={selectedSpecialty}
              onChange={(e) => setSelectedSpecialty(e.target.value)}
              disabled={!selectedUnit}
            >
                <option value="">Select Specialty</option>
                {specialties
                  .filter(specialty => !selectedUnit || specialty.unitId === selectedUnit)
                  .map(specialty => (
                    <option key={specialty.id || specialty} value={specialty.id || specialty}>
                      {specialty.name || specialty}
                    </option>
                  ))}
              </select>
            {!selectedUnit && (
              <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                Please select a unit first
              </p>
            )}
          </div>
          
          {/* Date Range */}
          <div className="mb-4">
            <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-1">
              Date Range
            </label>
            <div className="flex justify-between items-center mb-2">
              <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Date Range
              </label>
              <div className="flex space-x-1">
                <button 
                  className="px-2 py-1 text-xs bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark rounded-md hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark"
                  onClick={() => handleQuickDateSelect('30d')}
                >
                  30d
                </button>
                <button 
                  className="px-2 py-1 text-xs bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark rounded-md hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark"
                  onClick={() => handleQuickDateSelect('90d')}
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
          
          {/* Comparative Period Toggle */}
          <div className="mb-4 flex items-center justify-between">
            <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Comparative Period</span>
            <div 
              className={`w-10 h-5 rounded-full p-1 cursor-pointer transition-colors ${showComparison ? 'bg-healthcare-primary dark:bg-healthcare-primary-dark' : 'bg-gray-600 dark:bg-gray-700'}`}
              onClick={() => {
                setShowComparison(!showComparison);
              }}
            >
              <div 
                className={`w-3 h-3 rounded-full bg-white transform transition-transform ${showComparison ? 'translate-x-5' : ''}`}
              ></div>
            </div>
          </div>
          
          {/* Comparison Date Range */}
          {showComparison && (
            <div className="mb-4">
              <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">
                Comparison Date Range
              </label>
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
                    title="Comparison Start Date"
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
                    title="Comparison End Date"
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
            </div>
          )}
        </>
      )}
    </div>
  );
};

ProcessFilters.propTypes = {
  hospitals: PropTypes.arrayOf(
    PropTypes.oneOfType([
      PropTypes.string,
      PropTypes.shape({
        id: PropTypes.string,
        name: PropTypes.string
      })
    ])
  ),
  units: PropTypes.arrayOf(
    PropTypes.oneOfType([
      PropTypes.string,
      PropTypes.shape({
        id: PropTypes.string,
        name: PropTypes.string,
        hospitalId: PropTypes.string
      })
    ])
  ),
  specialties: PropTypes.arrayOf(
    PropTypes.oneOfType([
      PropTypes.string,
      PropTypes.shape({
        id: PropTypes.string,
        name: PropTypes.string,
        unitId: PropTypes.string
      })
    ])
  ),
  onFilterChange: PropTypes.func,
  initialFilters: PropTypes.shape({
    selectedHospital: PropTypes.string,
    selectedUnit: PropTypes.string,
    selectedSpecialty: PropTypes.string,
    startDate: PropTypes.instanceOf(Date),
    endDate: PropTypes.instanceOf(Date),
    compStartDate: PropTypes.instanceOf(Date),
    compEndDate: PropTypes.instanceOf(Date),
    showComparison: PropTypes.bool
  }),
  className: PropTypes.string
};

export default ProcessFilters;
