import React, { useState, useEffect, useCallback } from 'react';
import { AlertTriangle, TrendingUp, TrendingDown, Clock, Activity } from 'lucide-react';
import StatusTooltip from '../ResourceAnalysis/StatusTooltip';

const LiveMonitor = ({
  data,
  thresholds,
  onAlert,
  refreshInterval = 60000, // 1 minute
  className = ''
}) => {
  const [alerts, setAlerts] = useState([]);
  const [trends, setTrends] = useState({});
  const [lastUpdate, setLastUpdate] = useState(new Date());

  const calculateTrend = useCallback((current, previous) => {
    if (!previous || !current) return null;
    const change = ((current - previous) / previous) * 100;
    return {
      direction: change > 0 ? 'increasing' : 'decreasing',
      percentage: Math.abs(Math.round(change))
    };
  }, []);

  const checkThresholds = useCallback((metrics) => {
    if (!metrics) return [];
    const newAlerts = [];
    Object.entries(metrics).forEach(([step, value]) => {
      if (!value) return;
      const threshold = thresholds?.[step];
      if (threshold && value > threshold.critical) {
        newAlerts.push({
          step,
          value,
          threshold: threshold.critical,
          severity: 'critical',
          message: `${step.replace(/([A-Z])/g, ' $1').toLowerCase()} wait time exceeds critical threshold`
        });
      } else if (threshold && value > threshold.warning) {
        newAlerts.push({
          step,
          value,
          threshold: threshold.warning,
          severity: 'warning',
          message: `${step.replace(/([A-Z])/g, ' $1').toLowerCase()} wait time approaching critical level`
        });
      }
    });
    return newAlerts;
  }, [thresholds]);

  useEffect(() => {
    if (!data?.current) return;

    const newAlerts = checkThresholds(data.current);
    setAlerts(newAlerts);
    
    const newTrends = {};
    Object.entries(data.current).forEach(([step, value]) => {
      if (value) {
        newTrends[step] = calculateTrend(value, data.previous?.[step]);
      }
    });
    setTrends(newTrends);
    
    setLastUpdate(new Date());
    
    // Notify parent of new alerts
    if (newAlerts.length > 0) {
      onAlert?.(newAlerts);
    }
  }, [data, checkThresholds, calculateTrend, onAlert]);

  const getStatusColor = useCallback((value, step) => {
    if (!value || !step) return 'text-healthcare-text-secondary';
    const threshold = thresholds?.[step];
    if (!threshold) return 'text-healthcare-text-secondary';
    if (value > threshold.critical) return 'text-healthcare-critical';
    if (value > threshold.warning) return 'text-healthcare-warning';
    return 'text-healthcare-success';
  }, [thresholds]);

  const getTooltipContent = (step, value, trend) => {
    if (!step || !value) return null;
    return (
      <div className="space-y-2">
        <div className="font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark capitalize">
          {step.replace(/([A-Z])/g, ' $1').toLowerCase()}
        </div>
        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Current Wait: {value} minutes
        </div>
        {trend && (
          <div className={`text-sm font-medium ${
            trend.direction === 'increasing' 
              ? 'text-healthcare-critical' 
              : 'text-healthcare-success'
          }`}>
            {trend.direction === 'increasing' ? 'Up' : 'Down'} {trend.percentage}%
          </div>
        )}
        {thresholds?.[step] && (
          <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Critical: {thresholds[step].critical} min
            <br />
            Warning: {thresholds[step].warning} min
          </div>
        )}
      </div>
    );
  };

  if (!data?.current || Object.keys(data.current).length === 0) {
    return (
      <div className={`healthcare-card ${className}`}>
        <div className="flex items-center justify-between mb-6">
          <h3 className="font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Live Wait Times
          </h3>
          <div className="flex items-center gap-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <Clock className="h-4 w-4" />
            No data available
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className={`healthcare-card ${className}`}>
      <div className="flex items-center justify-between mb-6">
        <h3 className="font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          Live Wait Times
        </h3>
        <div className="flex items-center gap-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          <Clock className="h-4 w-4" />
          Last updated: {lastUpdate.toLocaleTimeString()}
        </div>
      </div>

      <div className="grid grid-cols-3 gap-6">
        {Object.entries(data.current).map(([step, value]) => {
          if (!value) return null;
          const trend = trends[step];
          const TrendIcon = trend?.direction === 'increasing' ? TrendingUp : TrendingDown;
          
          return (
            <StatusTooltip
              key={step}
              content={getTooltipContent(step, value, trend)}
              position="top"
            >
              <div className="healthcare-panel">
                <div className="flex items-center justify-between mb-2">
                  <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark capitalize">
                    {step.replace(/([A-Z])/g, ' $1').toLowerCase()}
                  </h4>
                  {trend && (
                    <div className={`flex items-center gap-1 ${
                      trend.direction === 'increasing'
                        ? 'text-healthcare-critical'
                        : 'text-healthcare-success'
                    }`}>
                      <TrendIcon className="h-4 w-4" />
                      <span className="text-xs font-medium">{trend.percentage}%</span>
                    </div>
                  )}
                </div>
                
                <div className="flex items-end justify-between">
                  <div className={`text-2xl font-bold ${getStatusColor(value, step)}`}>
                    {value}
                    <span className="text-sm font-normal ml-1">min</span>
                  </div>
                  <Activity className={`h-5 w-5 ${getStatusColor(value, step)}`} />
                </div>
              </div>
            </StatusTooltip>
          );
        })}
      </div>

      {alerts.length > 0 && (
        <div className="mt-6 space-y-2">
          {alerts.map((alert, index) => (
            <div
              key={index}
              className={`flex items-center gap-3 p-3 rounded-md ${
                alert.severity === 'critical'
                  ? 'bg-healthcare-critical/10 text-healthcare-critical'
                  : 'bg-healthcare-warning/10 text-healthcare-warning'
              }`}
            >
              <AlertTriangle className="h-5 w-5" />
              <span className="text-sm font-medium">{alert.message}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

export default LiveMonitor;
