import React, { useState, useEffect } from 'react';
import { useDarkMode } from '../../../hooks/useDarkMode';
import ErrorBoundary from '../../Common/ErrorBoundary';
import { mockProviderAnalytics } from '../../../mock-data/provider-analytics';
import { Icon } from '@iconify/react';
import SummaryCards from '../BlockUtilization/SummaryCards';
import FilterSidebar from '../BlockUtilization/FilterSidebar';
import DarkModeToggle from '../../Common/DarkModeToggle';
import PropTypes from 'prop-types';

const ProviderDashboard = () => {
  // State initialization
  const [isDarkMode, setIsDarkMode] = useDarkMode();
  const [selectedSite, setSelectedSite] = useState('Default Site');
  const [dateRange, setDateRange] = useState({
    start: '2025-01-01',
    end: '2025-01-31'
  });
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(false);
  const [summaryMetrics, setSummaryMetrics] = useState({
    averageCasesPerDay: 0,
    casesTrend: 0,
    onTimeStarts: 0,
    onTimeTrend: 0,
    averageDuration: 0,
    durationTrend: 0
  });

  // Data initialization
  const sites = Object.keys(mockProviderAnalytics.sites);
  const siteData = mockProviderAnalytics.sites[selectedSite] || {
    providers: [],
    totals: {
      average_cases_per_day: 0,
      on_time_starts: 0,
      average_duration: 0
    }
  };

  // Update summary metrics when site data changes
  useEffect(() => {
    if (!siteData?.totals) return;

    setSummaryMetrics({
      averageCasesPerDay: siteData.totals.average_cases_per_day || 0,
      casesTrend: 2.5, // Mock trend data
      onTimeStarts: siteData.totals.on_time_starts || 0,
      onTimeTrend: 3.2,
      averageDuration: siteData.totals.average_duration || 0,
      durationTrend: -1.5
    });
  }, [siteData?.totals]);

  // Handle keyboard shortcuts
  useEffect(() => {
    const handleKeyPress = (e) => {
      if (e.metaKey && e.key === '/') {
        e.preventDefault();
        setIsSidebarCollapsed((prev) => !prev);
      }
    };

    window.addEventListener('keydown', handleKeyPress);
    return () => window.removeEventListener('keydown', handleKeyPress);
  }, []);

  return (
    <div className={`flex min-h-screen ${isDarkMode ? 'bg-gray-900' : 'bg-gray-50'}`}>
      <FilterSidebar
        isCollapsed={isSidebarCollapsed}
        onToggle={() => setIsSidebarCollapsed((prev) => !prev)}
        selectedSite={selectedSite}
        onSiteChange={setSelectedSite}
        dateRange={dateRange}
        onDateRangeChange={setDateRange}
        sites={sites}
        isDarkMode={isDarkMode}
      />

      <main
        className={`flex-1 transition-all duration-300 ${
          isSidebarCollapsed ? 'ml-12' : 'ml-64'
        }`}
      >
        <div className="p-6 space-y-6">
          {/* Header */}
          <div className="flex justify-between items-center">
            <h1
              className={`text-2xl font-bold ${
                isDarkMode ? 'text-gray-100' : 'text-gray-900'
              }`}
            >
              Provider Analytics Overview
            </h1>
            <div className="flex items-center space-x-2">
              <DarkModeToggle
                isDarkMode={isDarkMode}
                onToggle={() => setIsDarkMode(!isDarkMode)}
              />
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
            <SummaryCards metrics={summaryMetrics} isDarkMode={isDarkMode} />
          </ErrorBoundary>

          {/* Main Content */}
          {/* Implement the rest of the dashboard components similar to BlockUtilizationDashboard */}
          {/* For example, ProviderTrendAnalysis, ProviderDetailsTable, etc. */}
        </div>
      </main>
    </div>
  );
};

ProviderDashboard.propTypes = {
  initialSite: PropTypes.string,
  initialDateRange: PropTypes.shape({
    start: PropTypes.string,
    end: PropTypes.string,
  }),
};

ProviderDashboard.defaultProps = {
  initialSite: 'Default Site',
  initialDateRange: {
    start: '2025-01-01',
    end: '2025-01-31',
  },
};

export default ProviderDashboard;
