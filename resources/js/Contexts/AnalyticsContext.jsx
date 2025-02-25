import React, { createContext, useContext, useState, useMemo } from 'react';
import PropTypes from 'prop-types';
import { format } from 'date-fns';

const AnalyticsContext = createContext(null);

export function AnalyticsProvider({ children }) {
  const [selectedLocation, setSelectedLocation] = useState('MARH OR');
  const [dateRange, setDateRange] = useState({
    startDate: new Date(2024, 9, 1),
    endDate: new Date(2024, 11, 31)
  });
  const [selectedService, setSelectedService] = useState(null);

  const value = useMemo(() => ({
    selectedLocation,
    setSelectedLocation,
    dateRange,
    setDateRange,
    selectedService,
    setSelectedService,
    formattedDateRange: {
      start: format(dateRange.startDate, 'PPP'),
      end: format(dateRange.endDate, 'PPP')
    }
  }), [selectedLocation, dateRange, selectedService]);

  return (
    <AnalyticsContext.Provider value={value}>
      {children}
    </AnalyticsContext.Provider>
  );
}

AnalyticsProvider.propTypes = {
  children: PropTypes.node.isRequired,
};

export function useAnalytics() {
  const context = useContext(AnalyticsContext);
  if (!context) {
    throw new Error('useAnalytics must be used within an AnalyticsProvider');
  }
  return context;
}
