import React from 'react';
import { 
  TrendingUp, TrendingDown, 
  ChevronRight, Activity,
  AlertCircle
} from 'lucide-react';
import { Button } from '@/Components/ui/button';

const ProcessIntelligenceCard = ({ 
  title, 
  icon: Icon,
  primaryMetric,
  secondaryMetrics,
  healthScore,
  onClick,
  className = "" 
}) => {
  // Helper to determine trend direction and color
  const getTrendInfo = (value) => {
    const isPositive = !value.includes('-');
    const isNeutral = value === '0%';
    return {
      icon: isPositive ? TrendingUp : TrendingDown,
      color: isNeutral 
        ? 'text-healthcare-info' 
        : isPositive 
          ? 'text-healthcare-success' 
          : 'text-healthcare-warning'
    };
  };

  // Calculate health indicator color
  const getHealthColor = (score) => {
    if (score >= 90) return 'bg-healthcare-success';
    if (score >= 75) return 'bg-healthcare-warning';
    return 'bg-healthcare-critical';
  };

  return (
    <div 
      className={`healthcare-panel hover:shadow-md transition-shadow cursor-pointer ${className}`}
      onClick={onClick}
      role="button"
      tabIndex={0}
    >
      {/* Header */}
      <div className="flex items-center justify-between mb-4">
        <div className="flex items-center gap-2">
          <Icon className="h-5 w-5 text-healthcare-primary dark:text-healthcare-primary-dark" />
          <h3 className="font-medium">{title}</h3>
        </div>
        <div className="flex items-center gap-2">
          <div className={`h-2 w-2 rounded-full ${getHealthColor(healthScore)}`} />
          <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {healthScore}% Health
          </span>
        </div>
      </div>

      {/* Primary Metric */}
      <div className="mb-4">
        <div className="flex items-baseline justify-between">
          <span className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {primaryMetric.value}
          </span>
          <div className="flex items-center gap-1">
            {(() => {
              const { icon: TrendIcon, color } = getTrendInfo(primaryMetric.trend);
              return (
                <div className={`flex items-center ${color}`}>
                  <TrendIcon className="h-4 w-4" />
                  <span className="text-sm">{primaryMetric.trend}</span>
                </div>
              );
            })()}
          </div>
        </div>
        <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {primaryMetric.label}
        </span>
      </div>

      {/* Secondary Metrics */}
      <div className="space-y-2 mb-4">
        {secondaryMetrics.map((metric, index) => (
          <div key={index} className="flex items-center justify-between">
            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {metric.label}
            </span>
            <div className="flex items-center gap-1">
              <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                {metric.value}
              </span>
              {metric.trend && (() => {
                const { icon: TrendIcon, color } = getTrendInfo(metric.trend);
                return <TrendIcon className={`h-3 w-3 ${color}`} />;
              })()}
            </div>
          </div>
        ))}
      </div>

      {/* Action Button */}
      <Button 
        variant="ghost" 
        className="w-full justify-between text-healthcare-primary dark:text-healthcare-primary-dark hover:text-healthcare-primary-hover dark:hover:text-healthcare-primary-dark"
      >
        View Details
        <ChevronRight className="h-4 w-4" />
      </Button>
    </div>
  );
};

export default ProcessIntelligenceCard;
