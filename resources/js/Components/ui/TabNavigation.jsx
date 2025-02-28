import React from 'react';
import PropTypes from 'prop-types';
import { Icon } from '@iconify/react';

/**
 * TabNavigation component for consistent tab navigation across dashboards
 * 
 * @param {Object} props - Component props
 * @param {Array} props.menuGroups - Array of menu groups, each with a title and items array
 * @param {string} props.activeTab - Currently active tab ID
 * @param {Function} props.onTabChange - Function to call when a tab is clicked
 * @param {string} [props.className] - Additional CSS classes for the container
 * @returns {React.ReactElement} TabNavigation component
 */
const TabNavigation = ({ menuGroups, activeTab, onTabChange, className = '' }) => {
  // Custom TabButton component
  const TabButton = ({ id, label, icon }) => (
    <button
      className={`flex items-center gap-2 px-4 py-2 rounded-t-lg ${
        activeTab === id
          ? 'bg-blue-600 text-white'
          : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600'
      }`}
      onClick={() => onTabChange(id)}
    >
      <Icon icon={icon} className="w-5 h-5" />
      {label}
    </button>
  );

  return (
    <div className={`healthcare-card dark:bg-gray-800 mb-6 ${className}`}>
      <div className="flex flex-wrap gap-2 border-b border-gray-200 dark:border-gray-700 pb-2">
        {menuGroups.flatMap(group => 
          group.items.map(item => (
            <TabButton 
              key={item.id}
              id={item.id}
              label={item.label}
              icon={item.icon || 'carbon:unknown'} // Provide a fallback icon
            />
          ))
        )}
      </div>
    </div>
  );
};

TabNavigation.propTypes = {
  menuGroups: PropTypes.arrayOf(
    PropTypes.shape({
      title: PropTypes.string.isRequired,
      items: PropTypes.arrayOf(
        PropTypes.shape({
          id: PropTypes.string.isRequired,
          label: PropTypes.string.isRequired,
          icon: PropTypes.string.isRequired
        })
      ).isRequired
    })
  ).isRequired,
  activeTab: PropTypes.string.isRequired,
  onTabChange: PropTypes.func.isRequired,
  className: PropTypes.string
};

export default TabNavigation;
