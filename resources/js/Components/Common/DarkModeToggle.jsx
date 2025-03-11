import React from 'react';
import PropTypes from 'prop-types';
import { Icon } from '@iconify/react';
import { useDarkMode, HEALTHCARE_COLORS } from '@/hooks/useDarkMode';

const DarkModeToggle = ({ isDarkMode, onToggle }) => {
  const colors = HEALTHCARE_COLORS[isDarkMode ? 'dark' : 'light'];

  return (
    <button
      onClick={onToggle}
      className={`
        relative p-2.5 rounded-lg transition-all duration-300
        ${isDarkMode 
          ? 'bg-healthcare-surface-dark hover:bg-healthcare-hover-dark text-healthcare-info-dark' 
          : 'bg-healthcare-surface hover:bg-healthcare-hover text-healthcare-info'
        }
        focus:outline-none focus:ring-2 focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark focus:ring-opacity-50
        border border-healthcare-border dark:border-healthcare-border-dark
        group
      `}
      aria-label={`Switch to ${isDarkMode ? 'light' : 'dark'} mode`}
      title={`Switch to ${isDarkMode ? 'light' : 'dark'} mode`}
    >
      <div className="relative w-5 h-5">
        <Icon 
          icon={isDarkMode ? 'heroicons:sun' : 'heroicons:moon'} 
          className="w-5 h-5 absolute inset-0 transform transition-all duration-300 rotate-0 group-hover:rotate-12"
          aria-hidden="true"
        />
      </div>
      
      {/* Tooltip */}
      <span className="absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 px-2 py-1 text-xs font-medium rounded-md whitespace-nowrap opacity-0 group-hover:opacity-100 transition-all duration-300 pointer-events-none bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark border border-healthcare-border dark:border-healthcare-border-dark shadow-sm">
        {isDarkMode ? 'Switch to light mode' : 'Switch to dark mode'}
      </span>
    </button>
  );
};

DarkModeToggle.propTypes = {
  isDarkMode: PropTypes.bool.isRequired,
  onToggle: PropTypes.func.isRequired
};

export default DarkModeToggle;
