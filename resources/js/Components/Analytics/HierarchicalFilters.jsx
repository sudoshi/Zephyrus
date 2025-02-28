import React from 'react';
import PropTypes from 'prop-types';
import { Card, Select, Datepicker } from 'flowbite-react';
import Panel from '@/Components/ui/Panel';

const HierarchicalFilters = ({
  hospitals,
  locations,
  specialties,
  selectedHospital,
  selectedLocation,
  selectedSpecialty,
  dateRange,
  onHospitalChange,
  onLocationChange,
  onSpecialtyChange,
  onDateRangeChange
}) => {
  // Filter locations based on selected hospital
  const filteredLocations = selectedHospital
    ? locations.filter(location => location.startsWith(selectedHospital))
    : locations;

  // Handle date range change
  const handleStartDateChange = (date) => {
    onDateRangeChange({
      ...dateRange,
      start: date
    });
  };

  const handleEndDateChange = (date) => {
    onDateRangeChange({
      ...dateRange,
      end: date
    });
  };

  return (
    <Panel title="Filters" isSubpanel dropLightIntensity="medium">
      <div className="space-y-4">
        {/* Hospital Filter */}
        <div>
          <label htmlFor="hospital-filter" className="block mb-2 text-sm font-medium text-gray-900 dark:text-white">
            Hospital
          </label>
          <Select
            id="hospital-filter"
            value={selectedHospital}
            onChange={(e) => onHospitalChange(e.target.value)}
          >
            <option value="">All Hospitals</option>
            {hospitals.map((hospital) => (
              <option key={hospital} value={hospital}>
                {hospital}
              </option>
            ))}
          </Select>
        </div>

        {/* Location Filter */}
        <div>
          <label htmlFor="location-filter" className="block mb-2 text-sm font-medium text-gray-900 dark:text-white">
            Location
          </label>
          <Select
            id="location-filter"
            value={selectedLocation}
            onChange={(e) => onLocationChange(e.target.value)}
          >
            <option value="">All Locations</option>
            {filteredLocations.map((location) => (
              <option key={location} value={location}>
                {location}
              </option>
            ))}
          </Select>
        </div>

        {/* Specialty Filter */}
        <div>
          <label htmlFor="specialty-filter" className="block mb-2 text-sm font-medium text-gray-900 dark:text-white">
            Specialty
          </label>
          <Select
            id="specialty-filter"
            value={selectedSpecialty}
            onChange={(e) => onSpecialtyChange(e.target.value)}
          >
            <option value="">All Specialties</option>
            {specialties.map((specialty) => (
              <option key={specialty} value={specialty}>
                {specialty}
              </option>
            ))}
          </Select>
        </div>

        {/* Date Range Filter */}
        <div>
          <label className="block mb-2 text-sm font-medium text-gray-900 dark:text-white">
            Date Range
          </label>
          <div className="grid grid-cols-2 gap-2">
            <div>
              <Datepicker
                value={dateRange.start}
                onChange={handleStartDateChange}
                placeholder="Start Date"
              />
            </div>
            <div>
              <Datepicker
                value={dateRange.end}
                onChange={handleEndDateChange}
                placeholder="End Date"
              />
            </div>
          </div>
        </div>

        {/* Apply Filters Button */}
        <div className="pt-2">
          <button
            type="button"
            className="w-full text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center dark:bg-blue-600 dark:hover:bg-blue-700 dark:focus:ring-blue-800"
          >
            Apply Filters
          </button>
        </div>

        {/* Reset Filters Button */}
        <div>
          <button
            type="button"
            className="w-full text-gray-500 bg-white hover:bg-gray-100 focus:ring-4 focus:ring-gray-300 rounded-lg border border-gray-200 text-sm font-medium px-5 py-2.5 hover:text-gray-900 focus:z-10 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-500 dark:hover:text-white dark:hover:bg-gray-600 dark:focus:ring-gray-600"
            onClick={() => {
              onHospitalChange('');
              onLocationChange('');
              onSpecialtyChange('');
              onDateRangeChange({ start: null, end: null });
            }}
          >
            Reset Filters
          </button>
        </div>
      </div>
    </Panel>
  );
};

HierarchicalFilters.propTypes = {
  hospitals: PropTypes.arrayOf(PropTypes.string).isRequired,
  locations: PropTypes.arrayOf(PropTypes.string).isRequired,
  specialties: PropTypes.arrayOf(PropTypes.string).isRequired,
  selectedHospital: PropTypes.string,
  selectedLocation: PropTypes.string,
  selectedSpecialty: PropTypes.string,
  dateRange: PropTypes.shape({
    start: PropTypes.instanceOf(Date),
    end: PropTypes.instanceOf(Date)
  }),
  onHospitalChange: PropTypes.func.isRequired,
  onLocationChange: PropTypes.func.isRequired,
  onSpecialtyChange: PropTypes.func.isRequired,
  onDateRangeChange: PropTypes.func.isRequired
};

export default HierarchicalFilters;
