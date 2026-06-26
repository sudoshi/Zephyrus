import React from 'react';
import { Icon } from '@iconify/react';
import { Surface } from '@/Components/ui/Surface';

// Compact metric tile on the single canonical surface (Surface). Previously
// rolled its own bg-white/bg-gray-800 surface and used raw emerald/red trend
// colors; now uses the canon surface + healthcare-* status tokens.
const MetricCard = ({ title, value, trend, trendDirection, icon, iconColor, isSubpanel = false }) => {
  const trendColor =
    trendDirection === 'up'
      ? 'text-healthcare-success dark:text-healthcare-success-dark'
      : trendDirection === 'down'
        ? 'text-healthcare-critical dark:text-healthcare-critical-dark'
        : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark';

  return (
    <Surface className="p-4">
      <div className="flex justify-between items-start mb-2">
        <div className="flex items-center">
          {icon && (
            <div className={`rounded-md p-2 mr-3 ${iconColor} bg-healthcare-background dark:bg-healthcare-background-dark`}>
              <Icon icon={icon} className="w-5 h-5" />
            </div>
          )}
          <h3 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{title}</h3>
        </div>
        {trend && (
          <div className={`flex items-center ${trendColor}`}>
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
      <div className="text-2xl font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value}</div>
    </Surface>
  );
};

export default MetricCard;
