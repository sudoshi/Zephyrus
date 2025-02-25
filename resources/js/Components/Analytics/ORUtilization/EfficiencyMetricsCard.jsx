import React from 'react';
import PropTypes from 'prop-types';
import { Card } from '@/Components/ui/flowbite';
import { Clock, BarChart2, TrendingUp, Users } from 'lucide-react';

/**
 * Component for displaying efficiency metrics in the OR Utilization Dashboard
 */
const EfficiencyMetricsCard = ({ 
  efficiencyRatio, 
  casesPerDay, 
  turnoverTime, 
  caseDuration,
  className = ''
}) => {
  // Format values for display
  const formattedEfficiencyRatio = efficiencyRatio ? `${efficiencyRatio.toFixed(1)}%` : 'N/A';
  const formattedCasesPerDay = casesPerDay ? casesPerDay.toFixed(1) : 'N/A';
  const formattedTurnoverTime = turnoverTime ? `${turnoverTime} min` : 'N/A';
  const formattedCaseDuration = caseDuration ? `${caseDuration} min` : 'N/A';
  
  // Determine color based on efficiency ratio
  const getEfficiencyColor = (ratio) => {
    if (!ratio) return 'text-gray-500';
    if (ratio >= 85) return 'text-green-500';
    if (ratio >= 75) return 'text-blue-500';
    if (ratio >= 65) return 'text-yellow-500';
    return 'text-red-500';
  };
  
  const efficiencyColor = getEfficiencyColor(efficiencyRatio);
  
  return (
    <Card className={`healthcare-card ${className}`}>
      <div className="flex items-center mb-4">
        <BarChart2 className="h-5 w-5 mr-2 text-healthcare-primary dark:text-healthcare-primary-dark" />
        <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          Efficiency Metrics
        </h3>
      </div>
      
      <div className="grid grid-cols-2 gap-4">
        {/* Efficiency Ratio */}
        <div className="bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark p-3 rounded-md">
          <div className="flex items-center mb-1">
            <TrendingUp className="h-4 w-4 mr-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Efficiency Ratio
            </span>
          </div>
          <div className={`text-2xl font-bold ${efficiencyColor}`}>
            {formattedEfficiencyRatio}
          </div>
          <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
            Case time / (Case time + Turnover)
          </div>
        </div>
        
        {/* Cases Per Day */}
        <div className="bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark p-3 rounded-md">
          <div className="flex items-center mb-1">
            <Users className="h-4 w-4 mr-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Cases Per Day
            </span>
          </div>
          <div className="text-2xl font-bold text-healthcare-primary dark:text-healthcare-primary-dark">
            {formattedCasesPerDay}
          </div>
          <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
            Average per room
          </div>
        </div>
        
        {/* Turnover Time */}
        <div className="bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark p-3 rounded-md">
          <div className="flex items-center mb-1">
            <Clock className="h-4 w-4 mr-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Avg Turnover Time
            </span>
          </div>
          <div className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {formattedTurnoverTime}
          </div>
          <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
            Time between cases
          </div>
        </div>
        
        {/* Case Duration */}
        <div className="bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark p-3 rounded-md">
          <div className="flex items-center mb-1">
            <Clock className="h-4 w-4 mr-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Avg Case Duration
            </span>
          </div>
          <div className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {formattedCaseDuration}
          </div>
          <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
            Time in OR
          </div>
        </div>
      </div>
      
      {/* Efficiency Tips */}
      <div className="mt-4 pt-4 border-t border-healthcare-border dark:border-healthcare-border-dark">
        <h4 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">
          Efficiency Insights
        </h4>
        <ul className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark space-y-1">
          <li className="flex items-start">
            <span className="inline-block h-1.5 w-1.5 rounded-full bg-healthcare-primary dark:bg-healthcare-primary-dark mt-1.5 mr-2"></span>
            <span>Reducing turnover time by 5 minutes could increase efficiency ratio by approximately 2-3%.</span>
          </li>
          <li className="flex items-start">
            <span className="inline-block h-1.5 w-1.5 rounded-full bg-healthcare-primary dark:bg-healthcare-primary-dark mt-1.5 mr-2"></span>
            <span>Industry benchmark for efficiency ratio is 80-85% for similar facilities.</span>
          </li>
          <li className="flex items-start">
            <span className="inline-block h-1.5 w-1.5 rounded-full bg-healthcare-primary dark:bg-healthcare-primary-dark mt-1.5 mr-2"></span>
            <span>Consider parallel processing for room preparation to reduce turnover time.</span>
          </li>
        </ul>
      </div>
    </Card>
  );
};

EfficiencyMetricsCard.propTypes = {
  efficiencyRatio: PropTypes.number,
  casesPerDay: PropTypes.number,
  turnoverTime: PropTypes.number,
  caseDuration: PropTypes.number,
  className: PropTypes.string
};

export default EfficiencyMetricsCard;
