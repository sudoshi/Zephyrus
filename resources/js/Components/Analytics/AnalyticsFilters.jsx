import React from 'react';
import PropTypes from 'prop-types';
import { Button, Datepicker } from 'flowbite-react';
import { CalendarIcon } from 'lucide-react';
import { useAnalytics } from '@/contexts/AnalyticsContext';

export default function AnalyticsFilters({ 
  locationOptions, 
  showServiceFilter = false,
  serviceOptions = []
}) {
  const {
    selectedLocation,
    setSelectedLocation,
    dateRange,
    setDateRange,
    selectedService,
    setSelectedService,
    formattedDateRange
  } = useAnalytics();

  const [isStartDateOpen, setIsStartDateOpen] = React.useState(false);
  const [isEndDateOpen, setIsEndDateOpen] = React.useState(false);

  return (
    <div className="flex flex-wrap gap-4 mb-6">
      <div className="w-64">
        <label className="block text-sm font-medium mb-1">Location</label>
        <select 
          className="bg-healthcare-surface-dark border border-healthcare-border-dark rounded-md w-full p-2"
          value={selectedLocation} 
          onChange={(e) => setSelectedLocation(e.target.value)}
        >
          {locationOptions.map(location => (
            <option key={location} value={location}>{location}</option>
          ))}
        </select>
      </div>

      {showServiceFilter && (
        <div className="w-64">
          <label className="block text-sm font-medium mb-1">Service</label>
          <select 
            className="bg-healthcare-surface-dark border border-healthcare-border-dark rounded-md w-full p-2"
            value={selectedService || ''} 
            onChange={(e) => setSelectedService(e.target.value || null)}
          >
            <option value="">All Services</option>
            {serviceOptions.map(service => (
              <option key={service} value={service}>{service}</option>
            ))}
          </select>
        </div>
      )}

      <div className="w-64">
        <label className="block text-sm font-medium mb-1">Start Date</label>
        <Datepicker
          value={dateRange.startDate}
          onSelectedDateChanged={(date) => setDateRange(prev => ({ ...prev, startDate: date }))}
          inline={isStartDateOpen}
          onClose={() => setIsStartDateOpen(false)}
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
              <CalendarIcon className="mr-2 h-4 w-4" />
              {formattedDateRange.start}
            </Button>
          }
        />
      </div>

      <div className="w-64">
        <label className="block text-sm font-medium mb-1">End Date</label>
        <Datepicker
          value={dateRange.endDate}
          onSelectedDateChanged={(date) => setDateRange(prev => ({ ...prev, endDate: date }))}
          inline={isEndDateOpen}
          onClose={() => setIsEndDateOpen(false)}
          minDate={dateRange.startDate}
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
              <CalendarIcon className="mr-2 h-4 w-4" />
              {formattedDateRange.end}
            </Button>
          }
        />
      </div>
    </div>
  );
}

AnalyticsFilters.propTypes = {
  locationOptions: PropTypes.arrayOf(PropTypes.string).isRequired,
  showServiceFilter: PropTypes.bool,
  serviceOptions: PropTypes.arrayOf(PropTypes.string),
};
