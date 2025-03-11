import React from 'react';
import DropLightPanel from './DropLightPanel';
import  { useDarkMode } from '@/hooks/useDarkMode.js';
import { Button } from '@/Components/ui/button';

const AnalyticsPanel = ({ 
  title,
  subtitle,
  children,
  actions = [],
  className = ''
}) => {
  const [isDarkMode] = useDarkMode();

  return (
    <DropLightPanel
      darkMode={isDarkMode}
      className={className}
    >
      <div className="flex justify-between items-start mb-4">
        <div>
          <h2 className="text-lg font-semibold">
            {title}
          </h2>
          {subtitle && (
            <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {subtitle}
            </p>
          )}
        </div>
        {actions.length > 0 && (
          <div className="flex space-x-2">
            {actions.map((action, index) => (
              <Button
                key={index}
                onClick={action.onClick}
                variant="ghost"
                className={`
                  inline-flex items-center
                  text-healthcare-text-secondary hover:text-healthcare-text-primary dark:text-healthcare-text-secondary-dark dark:hover:text-healthcare-text-primary-dark
                `}
              >
                {action.icon}
                <span className="ml-2">{action.label}</span>
              </Button>
            ))}
          </div>
        )}
      </div>
      {children}
    </DropLightPanel>
  );
};

export default AnalyticsPanel;
