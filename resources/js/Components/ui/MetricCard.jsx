import React from 'react';
import { Icon } from '@iconify/react';

const MetricCard = ({ title, value, trend, trendDirection, icon, iconColor, isSubpanel = false }) => {
  return (
    <div className={`
      bg-white dark:bg-gray-800 rounded-lg 
      ${isSubpanel 
        ? 'shadow-sm border border-gray-100 dark:border-gray-700 bg-gradient-to-b from-white to-gray-50 dark:from-gray-800 dark:to-gray-850' 
        : 'shadow'
      } 
      p-4
    `}>
      <div className="flex justify-between items-start mb-2">
        <div className="flex items-center">
          {icon && (
            <div className={`rounded-md p-2 mr-3 ${iconColor} ${isSubpanel ? 'bg-gray-50 dark:bg-gray-750' : 'bg-gray-100 dark:bg-gray-700'}`}>
              <Icon icon={icon} className="w-5 h-5" />
            </div>
          )}
          <h3 className="text-sm font-medium text-gray-500 dark:text-gray-300">{title}</h3>
        </div>
        {trend && (
          <div className={`flex items-center ${
            trendDirection === 'up' ? 'text-emerald-500' : 
            trendDirection === 'down' ? 'text-red-500' : 'text-gray-500'
          }`}>
            <Icon 
              icon={
                trendDirection === 'up' ? 'heroicons:arrow-trending-up' : 
                trendDirection === 'down' ? 'heroicons:arrow-trending-down' : 
                'heroicons:minus'
              } 
              className="w-4 h-4 mr-1" 
            />
            <span className="text-xs font-medium">{trend}</span>
          </div>
        )}
      </div>
      <div className="text-2xl font-bold dark:text-white">{value}</div>
    </div>
  );
};

export default MetricCard;
