import React, { useState, useEffect } from 'react';
import { useDarkMode, HEALTHCARE_COLORS } from '../../../hooks/useDarkMode';
import PropTypes from 'prop-types';
import ErrorBoundary from '../../Common/ErrorBoundary';
import { mockBlockUtilization, utilizationRanges } from '../../../mock-data/block-utilization';
import { Icon } from '@iconify/react';
import { ServiceMetricsPropType } from './types';
import SummaryCards from './SummaryCards';
import DarkModeToggle from '../../Common/DarkModeToggle';
import Select from '../../BlockSchedule/Select';
import DateRangeSelector from '../../Common/DateRangeSelector';
import TrendAnalysis from './TrendAnalysis';
import DayOfWeekAnalysis from './DayOfWeekAnalysis';
import ProviderDetails from './ProviderDetails';

const getUtilizationColor = (value, isDarkMode) => {
  if (value === null || value === undefined) return utilizationRanges.noBlock.color;
  if (value <= utilizationRanges.low.max) {
    return isDarkMode ? HEALTHCARE_COLORS.dark.critical : HEALTHCARE_COLORS.light.critical;
  }
  if (value <= utilizationRanges.medium.max) {
    return isDarkMode ? HEALTHCARE_COLORS.dark.warning : HEALTHCARE_COLORS.light.warning;
  }
  return isDarkMode ? HEALTHCARE_COLORS.dark.success : HEALTHCARE_COLORS.light.success;
};

const BlockUtilizationDashboard = () => {
  // State initialization
  const [isDarkMode, setIsDarkMode] = useDarkMode();
  const [selectedSite, setSelectedSite] = useState('MARH OR');
  const [dateRange, setDateRange] = useState({
    start: '2024-10-01',
    end: '2024-12-31',
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
  const sites = Object.keys(mockBlockUtilization.sites);
  const siteData = mockBlockUtilization.sites[selectedSite] || {
    services: [],
    totals: {
      total_block_utilization: 0,
      non_prime_percentage: 0,
      numof_cases: 0,
      out_of_block: 0,
    },
  };

  // Update summary metrics when site data changes
  useEffect(() => {
    if (!siteData?.totals) return;

    setSummaryMetrics({
      overallUtilization: siteData.totals.total_block_utilization || 0,
      utilizationTrend: 2.5, // Mock trend data
      primeTimeUsage: 100 - (siteData.totals.non_prime_percentage || 0),
      primeTimeTrend: 1.8,
      totalCases: siteData.totals.numof_cases || 0,
      casesTrend: 3.2,
      outOfBlockCases: siteData.totals.out_of_block || 0,
      outOfBlockTrend: -1.5,
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
    <div className="flex min-h-screen bg-healthcare-background dark:bg-healthcare-background-dark">
      <main className="flex-1">
        <div className="p-6 space-y-6">
          {/* Header */}
          <div className="flex justify-between items-center">
            <h1 className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Block Utilization Overview
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
                        ? 'bg-healthcare-info text-white'
                        : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark hover:bg-healthcare-surface dark:hover:bg-healthcare-surface-dark'
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
              <DarkModeToggle isDarkMode={isDarkMode} onToggle={() => setIsDarkMode(!isDarkMode)} />

              {/* Settings Button */}
              <button
                type="button"
                className="inline-flex items-center px-3 py-2 text-sm font-medium rounded-md text-healthcare-text-primary dark:text-healthcare-text-primary-dark bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark"
              >
                <Icon icon="heroicons:cog-6-tooth" className="w-5 h-5 mr-2" />
                Settings
              </button>

              {/* Export Report Button */}
              <button
                type="button"
                className="inline-flex items-center px-3 py-2 text-sm font-medium text-white rounded-md bg-healthcare-info dark:bg-healthcare-info-dark hover:bg-healthcare-info-dark dark:hover:bg-healthcare-info"
              >
                <Icon icon="heroicons:document-arrow-down" className="w-5 h-5 mr-2" />
                Export Report
              </button>
            </div>
          </div>

          {/* Summary Cards */}
          <ErrorBoundary>
            <SummaryCards metrics={summaryMetrics} isDarkMode={isDarkMode} />
          </ErrorBoundary>

          {/* Legend */}
          <div className="rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark shadow-blue-light dark:shadow-blue-dark p-4">
            <div className="flex items-center space-x-6">
              <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                Utilization Range:
              </span>
              <div className="flex items-center space-x-4">
                <div className="flex items-center">
                  <div
                    className="w-4 h-4 mr-2"
                    style={{ backgroundColor: utilizationRanges.low.color }}
                  />
                  <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {'<'} {utilizationRanges.low.max}%
                  </span>
                </div>
                <div className="flex items-center">
                  <div
                    className="w-4 h-4 mr-2"
                    style={{ backgroundColor: utilizationRanges.medium.color }}
                  />
                  <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {utilizationRanges.medium.min}-{utilizationRanges.medium.max}%
                  </span>
                </div>
                <div className="flex items-center">
                  <div
                    className="w-4 h-4 mr-2"
                    style={{ backgroundColor: utilizationRanges.high.color }}
                  />
                  <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {'>'} {utilizationRanges.medium.max}%
                  </span>
                </div>
                <div className="flex items-center">
                  <div
                    className="w-4 h-4 mr-2"
                    style={{ backgroundColor: utilizationRanges.noBlock.color }}
                  />
                  <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    No Block Time
                  </span>
                </div>
              </div>
            </div>
          </div>

          {/* Service Metrics Table */}
          <ErrorBoundary>
            <div className="rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark shadow-blue-light dark:shadow-blue-dark overflow-hidden mt-6">
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                  <thead className="bg-healthcare-surface dark:bg-healthcare-surface-dark">
                    <tr>
                      <th className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                        Service
                      </th>
                      <th className="px-6 py-3 text-right text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                        Cases
                      </th>
                      {/* ... Other headers */}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                    {siteData.services.map((service) => (
                      <tr key={service.service_id}>
                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                          {service.service_name}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-right text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          {service.numof_cases}
                        </td>
                        {/* ... Other cells */}
                      </tr>
                    ))}
                    {/* Totals Row */}
                    <tr className="font-semibold bg-healthcare-surface dark:bg-healthcare-surface-dark">
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        Grand Total
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-right text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {siteData.totals.numof_cases}
                      </td>
                      {/* ... Other total cells */}
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </ErrorBoundary>

          {/* Analysis Grid */}
          <div className="grid grid-cols-2 gap-6 mt-6">
            <ErrorBoundary>
              <div className="rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark shadow-blue-light dark:shadow-blue-dark p-6">
                <TrendAnalysis siteData={mockBlockUtilization.trends[selectedSite]} />
              </div>
            </ErrorBoundary>

            <ErrorBoundary>
              <div className="rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark shadow-blue-light dark:shadow-blue-dark p-6">
                <DayOfWeekAnalysis dayOfWeekData={mockBlockUtilization.dayOfWeek} />
              </div>
            </ErrorBoundary>
          </div>

          {/* Provider Details */}
          <ErrorBoundary>
            <div className="rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark shadow-blue-light dark:shadow-blue-dark mt-6">
              <ProviderDetails providerData={mockBlockUtilization.providers} />
            </div>
          </ErrorBoundary>
        </div>
      </main>
    </div>
  );
};

BlockUtilizationDashboard.propTypes = {
  initialSite: PropTypes.string,
  initialDateRange: PropTypes.shape({
    start: PropTypes.string,
    end: PropTypes.string,
  }),
};

BlockUtilizationDashboard.defaultProps = {
  initialSite: 'MARH OR',
  initialDateRange: {
    start: '2024-10-01',
    end: '2024-12-31',
  },
};

export default BlockUtilizationDashboard;
