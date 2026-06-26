import React, { useState } from 'react';
import PropTypes from 'prop-types';
import { Icon } from '@iconify/react';
import Select from '../../BlockSchedule/Select';
import DateRangeSelector from '../../Common/DateRangeSelector';

const QuickDateButton = ({ label, days, onClick, isActive }) => (
  <button
    onClick={onClick}
    className={`px-3 py-2 text-sm font-medium rounded-md transition-colors ${
      isActive
        ? 'bg-healthcare-primary/10 text-healthcare-primary dark:bg-healthcare-primary-dark/20 dark:text-healthcare-primary-dark'
        : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark'
    }`}
  >
    {label}
  </button>
);

const FilterSidebar = ({ 
  isCollapsed,
  onToggle,
  selectedSite,
  onSiteChange,
  dateRange,
  onDateRangeChange,
  sites,
  isDarkMode = false
}) => {
  const [quickDateFilter, setQuickDateFilter] = useState(null);

  const handleQuickDateSelect = (days) => {
    const end = new Date();
    const start = new Date();
    start.setDate(start.getDate() - days);
    
    setQuickDateFilter(days);
    onDateRangeChange({
      start: start.toISOString().split('T')[0],
      end: end.toISOString().split('T')[0]
    });
  };

  return (
    <div className={`
      fixed top-0 left-0 h-full shadow-lg transition-all duration-300
      ${isCollapsed ? 'w-12' : 'w-64'}
      ${isDarkMode ? 'bg-healthcare-surface-dark' : 'bg-healthcare-surface'}
      flex flex-col
    `}
    role="complementary"
    aria-label="Filters">
      <button
        onClick={onToggle}
        className={`absolute -right-4 top-8 rounded-full p-2 shadow-md ${
          isDarkMode ? 'bg-healthcare-surface-dark text-healthcare-text-secondary-dark' : 'bg-healthcare-surface text-healthcare-text-secondary'
        }`}
        aria-label={isCollapsed ? "Expand sidebar" : "Collapse sidebar"}
        aria-expanded={!isCollapsed}
      >
        <Icon 
          icon={isCollapsed ? 'heroicons:chevron-right' : 'heroicons:chevron-left'} 
          className="w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
        />
      </button>

      <div className={`p-4 ${isCollapsed ? 'hidden' : ''}`}>
        <h3 className={`text-lg font-semibold mb-6 ${
          isDarkMode ? 'text-healthcare-text-primary-dark' : 'text-healthcare-text-primary'
        }`}>
          Filters
        </h3>

        <div className="space-y-6">
          <div>
            <label className={`block text-sm font-medium mb-2 ${
              isDarkMode ? 'text-healthcare-text-secondary-dark' : 'text-healthcare-text-secondary'
            }`}>
              Location
            </label>
            <Select
              value={selectedSite}
              onChange={onSiteChange}
              options={sites.map(site => ({
                value: site,
                label: site
              }))}
              className="w-full"
            />
          </div>

          <div>
            <label className={`block text-sm font-medium mb-2 ${
              isDarkMode ? 'text-healthcare-text-secondary-dark' : 'text-healthcare-text-secondary'
            }`}>
              Date Range
            </label>
            <div className="space-y-2">
              <div className="flex flex-wrap gap-2">
                <QuickDateButton
                  label="7D"
                  days={7}
                  onClick={() => handleQuickDateSelect(7)}
                  isActive={quickDateFilter === 7}
                />
                <QuickDateButton
                  label="30D"
                  days={30}
                  onClick={() => handleQuickDateSelect(30)}
                  isActive={quickDateFilter === 30}
                />
                <QuickDateButton
                  label="90D"
                  days={90}
                  onClick={() => handleQuickDateSelect(90)}
                  isActive={quickDateFilter === 90}
                />
              </div>
              <DateRangeSelector
                startDate={dateRange.start}
                endDate={dateRange.end}
                onDateRangeChange={(range) => {
                  setQuickDateFilter(null);
                  onDateRangeChange(range);
                }}
              />
            </div>
          </div>

          <div className={`border-t ${isDarkMode ? 'border-healthcare-border-dark' : 'border-healthcare-border'} pt-6`}>
            <h4 className={`text-sm font-medium mb-2 ${
              isDarkMode ? 'text-healthcare-text-secondary-dark' : 'text-healthcare-text-secondary'
            }`}>
              Keyboard Shortcuts
            </h4>
            <div className={`space-y-2 text-sm ${
              isDarkMode ? 'text-healthcare-text-secondary-dark' : 'text-healthcare-text-secondary'
            }`}>
              <div className="flex justify-between">
                <span>Toggle Sidebar</span>
                <kbd className={`px-2 py-1 rounded ${
                  isDarkMode ? 'bg-healthcare-background-dark' : 'bg-healthcare-background'
                }`}>⌘ /</kbd>
              </div>
              <div className="flex justify-between">
                <span>Quick Filter</span>
                <kbd className={`px-2 py-1 rounded ${
                  isDarkMode ? 'bg-healthcare-background-dark' : 'bg-healthcare-background'
                }`}>⌘ F</kbd>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

QuickDateButton.propTypes = {
  label: PropTypes.string.isRequired,
  days: PropTypes.number.isRequired,
  onClick: PropTypes.func.isRequired,
  isActive: PropTypes.bool.isRequired
};

FilterSidebar.propTypes = {
  isDarkMode: PropTypes.bool,
  isCollapsed: PropTypes.bool.isRequired,
  onToggle: PropTypes.func.isRequired,
  selectedSite: PropTypes.string.isRequired,
  onSiteChange: PropTypes.func.isRequired,
  dateRange: PropTypes.shape({
    start: PropTypes.string.isRequired,
    end: PropTypes.string.isRequired
  }).isRequired,
  onDateRangeChange: PropTypes.func.isRequired,
  sites: PropTypes.arrayOf(PropTypes.string).isRequired
};

export default FilterSidebar;
