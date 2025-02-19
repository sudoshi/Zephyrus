import React, { useMemo } from 'react';
import { 
  AlertTriangle, 
  Users, 
  Clock, 
  ArrowRight, 
  Lightbulb,
  UserPlus,
  CalendarClock,
  ArrowUpRight
} from 'lucide-react';
import StatusTooltip from '../ResourceAnalysis/StatusTooltip';

const InsightsPanel = ({
  data,
  thresholds,
  onAction,
  className = ''
}) => {
  const insights = useMemo(() => {
    if (!data?.current) return [];
    const results = [];

    // Check for critical wait times
    Object.entries(data.current).forEach(([step, value]) => {
      if (!value) return;
      const threshold = thresholds?.[step];
      if (threshold && value > threshold.critical) {
        results.push({
          type: 'critical',
          title: `Critical Wait Time in ${step.replace(/([A-Z])/g, ' $1').toLowerCase()}`,
          description: `Current wait time (${value}min) exceeds critical threshold (${threshold.critical}min)`,
          actions: [
            {
              label: 'Add Staff',
              icon: UserPlus,
              handler: () => onAction?.('add_staff', { step }),
              severity: 'critical'
            },
            {
              label: 'Adjust Schedule',
              icon: CalendarClock,
              handler: () => onAction?.('adjust_schedule', { step }),
              severity: 'warning'
            }
          ]
        });
      }
    });

    // Check for increasing trends
    Object.entries(data.trends || {}).forEach(([step, trend]) => {
      if (trend?.direction === 'increasing' && trend.percentage > 20) {
        results.push({
          type: 'warning',
          title: `Rapid Increase in ${step.replace(/([A-Z])/g, ' $1').toLowerCase()}`,
          description: `Wait times have increased by ${trend.percentage}% in the last hour`,
          actions: [
            {
              label: 'View Analysis',
              icon: ArrowUpRight,
              handler: () => onAction?.('view_analysis', { step }),
              severity: 'info'
            }
          ]
        });
      }
    });

    // Resource optimization suggestions
    if (data.staffing?.utilization > 0.9) {
      results.push({
        type: 'suggestion',
        title: 'High Staff Utilization',
        description: 'Staff utilization is above 90%. Consider adjusting resources.',
        actions: [
          {
            label: 'Optimize Resources',
            icon: Users,
            handler: () => onAction?.('optimize_resources'),
            severity: 'warning'
          }
        ]
      });
    }

    return results;
  }, [data, thresholds, onAction]);

  const getActionColor = (severity) => {
    switch (severity) {
      case 'critical':
        return 'bg-healthcare-critical text-white hover:bg-healthcare-critical/90';
      case 'warning':
        return 'bg-healthcare-warning text-white hover:bg-healthcare-warning/90';
      case 'info':
        return 'bg-healthcare-info text-white hover:bg-healthcare-info/90';
      default:
        return 'bg-healthcare-primary text-white hover:bg-healthcare-primary/90';
    }
  };

  if (insights.length === 0) {
    return (
      <div className={`healthcare-card ${className}`}>
        <div className="flex items-center gap-3 text-healthcare-success">
          <Lightbulb className="h-5 w-5" />
          <span className="font-medium">All wait times are within normal ranges</span>
        </div>
      </div>
    );
  }

  return (
    <div className={`healthcare-card space-y-6 ${className}`}>
      <h3 className="font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark flex items-center gap-2">
        <Lightbulb className="h-5 w-5 text-healthcare-warning" />
        Insights & Recommendations
      </h3>

      <div className="space-y-4">
        {insights.map((insight, index) => (
          <div
            key={index}
            className={`p-4 rounded-lg ${
              insight.type === 'critical'
                ? 'bg-healthcare-critical/10'
                : insight.type === 'warning'
                ? 'bg-healthcare-warning/10'
                : 'bg-healthcare-info/10'
            }`}
          >
            <div className="flex items-start gap-3 mb-3">
              <div className={`rounded-full p-2 ${
                insight.type === 'critical'
                  ? 'bg-healthcare-critical/20 text-healthcare-critical'
                  : insight.type === 'warning'
                  ? 'bg-healthcare-warning/20 text-healthcare-warning'
                  : 'bg-healthcare-info/20 text-healthcare-info'
              }`}>
                {insight.type === 'critical' ? (
                  <AlertTriangle className="h-5 w-5" />
                ) : insight.type === 'warning' ? (
                  <Clock className="h-5 w-5" />
                ) : (
                  <Lightbulb className="h-5 w-5" />
                )}
              </div>
              <div>
                <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-1">
                  {insight.title}
                </h4>
                <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {insight.description}
                </p>
              </div>
            </div>

            <div className="flex gap-2 mt-4">
              {insight.actions.map((action, actionIndex) => {
                const Icon = action.icon;
                return (
                  <StatusTooltip
                    key={actionIndex}
                    content={
                      <div className="text-sm">
                        Click to {action.label.toLowerCase()}
                      </div>
                    }
                    position="top"
                  >
                    <button
                      onClick={action.handler}
                      className={`flex items-center gap-2 px-3 py-2 rounded-md text-sm font-medium transition-colors ${getActionColor(action.severity)}`}
                    >
                      <Icon className="h-4 w-4" />
                      {action.label}
                    </button>
                  </StatusTooltip>
                );
              })}
            </div>
          </div>
        ))}
      </div>

      {insights.length > 0 && (
        <div className="pt-4 border-t border-healthcare-border dark:border-healthcare-border-dark">
          <button
            onClick={() => onAction?.('view_all_insights')}
            className="flex items-center gap-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark transition-colors"
          >
            View All Insights
            <ArrowRight className="h-4 w-4" />
          </button>
        </div>
      )}
    </div>
  );
};

export default InsightsPanel;
