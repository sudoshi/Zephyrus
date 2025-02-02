import React, { useState, useEffect } from 'react';
import { useDarkMode } from '../../../hooks/useDarkMode';
import ErrorBoundary from '../../Common/ErrorBoundary';
import { mockServiceAnalytics } from '../../../mock-data/service-analytics';
import { Icon } from '@iconify/react';
import SummaryCards from '../BlockUtilization/SummaryCards';
import DarkModeToggle from '../../Common/DarkModeToggle';
import Select from '../../BlockSchedule/Select';
import DateRangeSelector from '../../Common/DateRangeSelector';
import PropTypes from 'prop-types';

const ServiceDashboard = () => {
  // State initialization
  const [isDarkMode, setIsDarkMode] = useDarkMode();
  const [selectedSite, setSelectedSite] = useState('Default Site');
  const [dateRange, setDateRange] = useState({
    start: '2025-01-01',
    end: '2025-01-31',
  });
  const [quickDateFilter, setQuickDateFilter] = useState(null);
  const [summaryMetrics, setSummaryMetrics] = useState({
    overallUtilization: 0,
    utilizationTrend: 0,
    primeTimeUsage: 0,
    primeTimeTrend: 0,
    totalCases: 0,
    casesTrend: 0,
    outOfBlockCases: 0,
    outOfBlockTrend: 0,
  });

  // Data initialization
  const sites = Object.keys(mockServiceAnalytics.sites);
  const siteData = mockServiceAnalytics.sites[selectedSite] || {
    services: [],
    totals: {
      average_utilization: 0,
      total_cases: 0,
      average_turnover: 0,
    },
  };

  // Update summary metrics when site data changes
  useEffect(() => {
    if (!siteData?.totals) return;

    setSummaryMetrics({
      overallUtilization: siteData.totals.average_utilization || 0,
      utilizationTrend: 2.5, // Mock trend data
      primeTimeUsage: siteData.totals.average_turnover || 0,
      primeTimeTrend: -1.5,
      totalCases: siteData.totals.total_cases || 0,
      casesTrend: 3.2,
      outOfBlockCases: 0, // Assuming not applicable
      outOfBlockTrend: 0,
    });
  }, [siteData?.totals]);

  // Handle quick date selection
  const handleQuickDateSelect = (days) => {
    const end = new Date();
    const start = new Date();
    start.setDate(start.getDate() - days);

    setQuickDateFilter(days);
    setDateRange({
      start: start.toISOString().split('T')[0],
      end: end.toISOString().split('T')[0],
    });
  };

  return (
    <div
      className={`flex min-h-screen ${
        isDarkMode ? 'bg-gray-900' : 'bg-gray-50'
      }`}
    >
      <main className="flex-1">
        <div className="p-6 space-y-6">
          {/* Header */}
          <div className="flex justify-between items-center">
            <h1
              className={`text-2xl font-bold ${
                isDarkMode ? 'text-gray-100' : 'text-gray-900'
              }`}
            >
              Service Analytics Overview
            </h1>
            <div className="flex items-center space-x-4">
              {/* Site Selector */}
              <Select
                value={selectedSite}
                onChange={setSelectedSite}
                options={sites.map((site) => ({
                  value: site,
                  label: site,
                }))}
                className="w-48"
              />

              {/* Quick Date Buttons */}
              <div className="flex space-x-2">
                {[7, 30, 90].map((days) => (
                  <button
                    key={days}
                    onClick={() => handleQuickDateSelect(days)}
                    className={`px-3 py-2 text-sm font-medium rounded-md ${
                      quickDateFilter === days
                        ? isDarkMode
                          ? 'bg-indigo-600 text-white'
                          : 'bg-indigo-100 text-indigo-700'
                        : isDarkMode
                        ? 'text-gray-300 hover:bg-gray-700'
                        : 'text-gray-700 hover:bg-gray-200'
                    }`}
                  >
                    {`${days}D`}
                  </button>
                ))}
              </div>

              {/* Date Range Selector */}
              <DateRangeSelector
                startDate={dateRange.start}
                endDate={dateRange.end}
                onDateRangeChange={(range) => {
                  setQuickDateFilter(null);
                  setDateRange(range);
                }}
              />

              {/* Dark Mode Toggle */}
              <DarkModeToggle
                isDarkMode={isDarkMode}
                onToggle={() => setIsDarkMode(!isDarkMode)}
              />

              {/* Settings Button */}
              <button
                type="button"
                className={`inline-flex items-center px-3 py-2 text-sm font-medium rounded-md ${
                  isDarkMode
                    ? 'text-gray-300 bg-gray-700 border-gray-600 hover:bg-gray-600'
                    : 'text-gray-700 bg-white border-gray-300 hover:bg-gray-50'
                } border`}
              >
                <Icon
                  icon="heroicons:cog-6-tooth"
                  className="w-5 h-5 mr-2"
                />
                Settings
              </button>

              {/* Export Report Button */}
              <button
                type="button"
                className={`inline-flex items-center px-3 py-2 text-sm font-medium text-white rounded-md ${
                  isDarkMode
                    ? 'bg-indigo-500 hover:bg-indigo-600'
                    : 'bg-indigo-600 hover:bg-indigo-700'
                }`}
              >
                <Icon
                  icon="heroicons:document-arrow-down"
                  className="w-5 h-5 mr-2"
                />
                Export Report
              </button>
            </div>
          </div>

          {/* Summary Cards */}
          <ErrorBoundary>
            <SummaryCards
              metrics={summaryMetrics}
              isDarkMode={isDarkMode}
            />
          </ErrorBoundary>

          {/* Main Content */}
          {/* Implement additional dashboard components as needed */}
        </div>
      </main>
    </div>
  );
};

ServiceDashboard.propTypes = {
  initialSite: PropTypes.string,
  initialDateRange: PropTypes.shape({
    start: PropTypes.string,
    end: PropTypes.string,
  }),
};

ServiceDashboard.defaultProps = {
  initialSite: 'Default Site',
  initialDateRange: {
    start: '2025-01-01',
    end: '2025-01-31',
  },
};

export default ServiceDashboard;
