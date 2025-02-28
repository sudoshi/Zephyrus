import React from 'react';
import PropTypes from 'prop-types';
import { Card } from '@/Components/ui/flowbite';
import { TrendingUp, DollarSign, Target, Clock } from 'lucide-react';

/**
 * Component for displaying opportunity metrics in the OR Utilization Dashboard
 */
const OpportunityMetricsCard = ({ 
  utilizationGap, 
  potentialAdditionalCases, 
  targetUtilization,
  currentUtilization,
  className = ''
}) => {
  // Format values for display
  const formattedUtilizationGap = utilizationGap ? `${utilizationGap.toFixed(1)}%` : '0%';
  const formattedCurrentUtilization = currentUtilization ? `${currentUtilization.toFixed(1)}%` : 'N/A';
  const formattedTargetUtilization = targetUtilization ? `${targetUtilization.toFixed(1)}%` : 'N/A';
  
  // Calculate potential revenue (placeholder calculation)
  const avgRevenuePerCase = 5000; // placeholder value
  const potentialRevenue = potentialAdditionalCases * avgRevenuePerCase;
  const formattedPotentialRevenue = potentialRevenue ? 
    `$${(potentialRevenue / 1000000).toFixed(1)}M` : 
    '$0';
  
  // Determine color based on utilization gap
  const getGapColor = (gap) => {
    if (!gap || gap <= 0) return 'text-green-500';
    if (gap <= 5) return 'text-blue-500';
    if (gap <= 10) return 'text-yellow-500';
    return 'text-red-500';
  };
  
  const gapColor = getGapColor(utilizationGap);
  
  // Calculate progress percentage
  const progressPercentage = currentUtilization && targetUtilization ? 
    Math.min(100, (currentUtilization / targetUtilization) * 100) : 
    0;
  
  return (
    <Card className={`healthcare-card ${className}`}>
      <div className="flex items-center mb-4">
        <Target className="h-5 w-5 mr-2 text-healthcare-primary dark:text-healthcare-primary-dark" />
        <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          Opportunity Assessment
        </h3>
      </div>
      
      <div className="grid grid-cols-2 gap-4">
        {/* Utilization Gap */}
        <div className="bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark p-3 rounded-md">
          <div className="flex items-center mb-1">
            <TrendingUp className="h-4 w-4 mr-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Utilization Gap
            </span>
          </div>
          <div className={`text-2xl font-bold ${gapColor}`}>
            {formattedUtilizationGap}
          </div>
          <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
            Target - Current
          </div>
        </div>
        
        {/* Potential Additional Cases */}
        <div className="bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark p-3 rounded-md">
          <div className="flex items-center mb-1">
            <Clock className="h-4 w-4 mr-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Potential Cases
            </span>
          </div>
          <div className="text-2xl font-bold text-healthcare-primary dark:text-healthcare-primary-dark">
            {potentialAdditionalCases || 0}
          </div>
          <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
            Additional cases per month
          </div>
        </div>
        
        {/* Potential Revenue */}
        <div className="bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark p-3 rounded-md">
          <div className="flex items-center mb-1">
            <DollarSign className="h-4 w-4 mr-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Potential Revenue
            </span>
          </div>
          <div className="text-2xl font-bold text-green-500">
            {formattedPotentialRevenue}
          </div>
          <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
            Estimated annual impact
          </div>
        </div>
        
        {/* Progress to Target */}
        <div className="bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark p-3 rounded-md">
          <div className="flex items-center mb-1">
            <Target className="h-4 w-4 mr-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Progress to Target
            </span>
          </div>
          <div className="flex items-center">
            <div className="text-lg font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mr-2">
              {formattedCurrentUtilization} / {formattedTargetUtilization}
            </div>
          </div>
          <div className="w-full bg-gray-200 rounded-full h-2.5 mt-2">
            <div 
              className="bg-healthcare-primary dark:bg-healthcare-primary-dark h-2.5 rounded-full" 
              style={{ width: `${progressPercentage}%` }}
            ></div>
          </div>
        </div>
      </div>
      
      {/* Opportunity Insights */}
      <div className="mt-4 pt-4 border-t border-healthcare-border dark:border-healthcare-border-dark">
        <h4 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">
          Opportunity Insights
        </h4>
        <ul className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark space-y-1">
          <li className="flex items-start">
            <span className="inline-block h-1.5 w-1.5 rounded-full bg-healthcare-primary dark:bg-healthcare-primary-dark mt-1.5 mr-2"></span>
            <span>Increasing utilization by just 5% could add {Math.round(potentialAdditionalCases * 0.5)} additional cases per month.</span>
          </li>
          <li className="flex items-start">
            <span className="inline-block h-1.5 w-1.5 rounded-full bg-healthcare-primary dark:bg-healthcare-primary-dark mt-1.5 mr-2"></span>
            <span>Top-performing peer institutions achieve 80-85% utilization rates.</span>
          </li>
          <li className="flex items-start">
            <span className="inline-block h-1.5 w-1.5 rounded-full bg-healthcare-primary dark:bg-healthcare-primary-dark mt-1.5 mr-2"></span>
            <span>Consider block time reallocation to optimize underutilized time slots.</span>
          </li>
        </ul>
      </div>
    </Card>
  );
};

OpportunityMetricsCard.propTypes = {
  utilizationGap: PropTypes.number,
  potentialAdditionalCases: PropTypes.number,
  targetUtilization: PropTypes.number,
  currentUtilization: PropTypes.number,
  className: PropTypes.string
};

export default OpportunityMetricsCard;
