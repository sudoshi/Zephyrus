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
        ? 'bg-indigo-100 text-indigo-700' 
        : 'text-gray-700 hover:bg-gray-100'
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
      ${isDarkMode ? 'bg-gray-800' : 'bg-white'}
      flex flex-col
    `}
    role="complementary"
    aria-label="Filters">
      <button
        onClick={onToggle}
        className={`absolute -right-4 top-8 rounded-full p-2 shadow-md ${
          isDarkMode ? 'bg-gray-700 text-gray-300' : 'bg-white text-gray-500'
        }`}
        aria-label={isCollapsed ? "Expand sidebar" : "Collapse sidebar"}
        aria-expanded={!isCollapsed}
      >
        <Icon 
          icon={isCollapsed ? 'heroicons:chevron-right' : 'heroicons:chevron-left'} 
          className="w-4 h-4 text-gray-500"
        />
      </button>

      <div className={`p-4 ${isCollapsed ? 'hidden' : ''}`}>
        <h3 className={`text-lg font-semibold mb-6 ${
          isDarkMode ? 'text-gray-100' : 'text-gray-900'
        }`}>
          Filters
        </h3>

        <div className="space-y-6">
          <div>
            <label className={`block text-sm font-medium mb-2 ${
              isDarkMode ? 'text-gray-300' : 'text-gray-700'
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
              isDarkMode ? 'text-gray-300' : 'text-gray-700'
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

          <div className={`border-t ${isDarkMode ? 'border-gray-700' : 'border-gray-200'} pt-6`}>
            <h4 className={`text-sm font-medium mb-2 ${
              isDarkMode ? 'text-gray-300' : 'text-gray-700'
            }`}>
              Keyboard Shortcuts
            </h4>
            <div className={`space-y-2 text-sm ${
              isDarkMode ? 'text-gray-400' : 'text-gray-500'
            }`}>
              <div className="flex justify-between">
                <span>Toggle Sidebar</span>
                <kbd className={`px-2 py-1 rounded ${
                  isDarkMode ? 'bg-gray-700' : 'bg-gray-100'
                }`}>⌘ /</kbd>
              </div>
              <div className="flex justify-between">
                <span>Quick Filter</span>
                <kbd className={`px-2 py-1 rounded ${
                  isDarkMode ? 'bg-gray-700' : 'bg-gray-100'
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
