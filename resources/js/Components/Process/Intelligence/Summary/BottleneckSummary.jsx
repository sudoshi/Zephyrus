import React, { useState } from 'react';
import { BarChart, Bar, LineChart, Line, RadarChart, Radar, PolarGrid, PolarAngleAxis, PolarRadiusAxis } from 'recharts';
import { Brain, AlertTriangle, Clock, Activity, Share2, TrendingUp, TrendingDown, ChevronRight, AlertCircle } from 'lucide-react';
import ScoreCard from '../Common/ScoreCard';
import AlertPanel from '../Common/AlertPanel';
import MetricChart, { ChartTooltip } from '../Common/MetricChart';
import ActionModal from './ActionModal';

const BottleneckSummary = ({ metrics }) => {
  const predictions = metrics?.predictions || {
    resourceUtilization: {
      nextHour: {},
      nextShift: {},
      nextDay: {}
    },
    patternAnalysis: {
      peakHours: [],
      quietHours: [],
      weeklyPatterns: {}
    },
    correlations: {
      resourceImpact: [],
      bottleneckTriggers: []
    },
    optimizationSuggestions: {
      staffing: [],
      resources: [],
      workflow: []
    }
  };

  // Score calculations (same as before)
  const calculateHealthScore = () => {
    const scores = {
      resource: calculateResourceScore(),
      cascade: calculateCascadeScore(),
      waitTime: calculateWaitTimeScore(),
      acuity: calculateAcuityScore()
    };

    return {
      totalScore: Object.values(scores).reduce((sum, score) => sum + score, 0),
      maxScore: 80,
      componentScores: scores
    };
  };

  // Individual score calculations (same as before)
  const calculateResourceScore = () => {
    const staffing = metrics?.staffing || {};
    const space = metrics?.space || {};
    
    const staffUtilization = Object.values(staffing).reduce((sum, role) => {
      return sum + (role.assigned / role.required);
    }, 0) / Object.keys(staffing).length;

    const spaceUtilization = Object.values(space).reduce((sum, area) => {
      return sum + (area.occupied / area.capacity);
    }, 0) / Object.keys(space).length;

    return Math.round((1 - ((staffUtilization + spaceUtilization) / 2)) * 20);
  };

  const calculateCascadeScore = () => {
    const cascade = metrics?.cascade?.affectedProcesses || [];
    if (cascade.length === 0) return 0;

    const avgSeverity = cascade.reduce((sum, proc) => sum + proc.severity, 0) / cascade.length;
    return Math.round((1 - avgSeverity) * 20);
  };

  const calculateWaitTimeScore = () => {
    const waitTime = metrics?.waitTime || { current: {}, benchmark: {} };
    const steps = Object.keys(waitTime.current);
    if (steps.length === 0) return 0;

    const avgDeviation = steps.reduce((sum, step) => {
      const current = waitTime.current[step];
      const benchmark = waitTime.benchmark[step];
      return sum + ((current - benchmark) / benchmark);
    }, 0) / steps.length;

    return Math.round((1 - avgDeviation) * 25);
  };

  const calculateAcuityScore = () => {
    const acuity = metrics?.acuity || { patientVolume: { count: 0, acuityBreakdown: {} } };
    if (acuity.patientVolume.count === 0) return 0;

    const weights = { high: 1.0, medium: 0.6, low: 0.2 };
    const total = acuity.patientVolume.count;
    
    const weightedScore = Object.entries(acuity.patientVolume.acuityBreakdown)
      .reduce((sum, [level, count]) => {
        return sum + (count / total) * weights[level];
      }, 0);

    return Math.round(weightedScore * 15);
  };

  // Data preparation functions (enhanced)
  const getResourcePredictionData = () => {
    const { nextHour, nextShift, nextDay } = predictions.resourceUtilization;
    const currentStaffing = metrics?.staffing || {};
    const currentSpace = metrics?.space || {};

    return [
      { 
        name: 'Current',
        nurses: {
          assigned: currentStaffing.nurses?.assigned || 0,
          required: currentStaffing.nurses?.required || 0
        },
        physicians: {
          assigned: currentStaffing.physicians?.assigned || 0,
          required: currentStaffing.physicians?.required || 0
        },
        rooms: {
          occupied: currentSpace.rooms?.occupied || 0,
          capacity: currentSpace.rooms?.capacity || 0
        }
      },
      { name: 'Next Hour', ...nextHour },
      { name: 'Next Shift', ...nextShift },
      { name: 'Next Day', ...nextDay }
    ].map(data => ({
      period: data.name,
      nurses: Math.round((data.nurses?.assigned / data.nurses?.required || 0) * 100),
      physicians: Math.round((data.physicians?.assigned / data.physicians?.required || 0) * 100),
      rooms: Math.round((data.rooms?.occupied / data.rooms?.capacity || 0) * 100),
      threshold: 80,
      critical: 90
    }));
  };

  const getCriticalActions = () => {
    const actions = [];
    
    // Add critical bottleneck triggers
    predictions.correlations.bottleneckTriggers
      .filter(trigger => trigger.probability > 0.7)
      .forEach(trigger => {
        actions.push({
          type: 'risk',
          title: trigger.trigger.split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' '),
          severity: Math.round(trigger.probability * 100),
          impact: 'High',
          timeframe: 'Immediate'
        });
      });

    // Add critical optimization suggestions
    predictions.optimizationSuggestions.staffing
      .concat(predictions.optimizationSuggestions.resources)
      .concat(predictions.optimizationSuggestions.workflow)
      .filter(suggestion => suggestion.urgency === 'high' && suggestion.impact > 0.7)
      .forEach(suggestion => {
        actions.push({
          type: 'action',
          title: suggestion.action.split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' '),
          severity: Math.round(suggestion.impact * 100),
          impact: 'High',
          timeframe: 'Next 24 hours'
        });
      });

    return actions.sort((a, b) => b.severity - a.severity);
  };

  const healthScore = calculateHealthScore();
  const criticalActions = getCriticalActions();
  const resourcePredictions = getResourcePredictionData();

  // Get trend indicators
  const getTrendIndicator = (score, threshold) => {
    if (score >= threshold) {
      return {
        icon: TrendingUp,
        color: 'text-healthcare-success dark:text-healthcare-success-dark',
        label: 'Improving'
      };
    }
    return {
      icon: TrendingDown,
      color: 'text-healthcare-critical dark:text-healthcare-critical-dark',
      label: 'Declining'
    };
  };

  const trend = getTrendIndicator(healthScore.totalScore, 60);

  const [selectedAction, setSelectedAction] = useState(null);
  const [isActionModalOpen, setIsActionModalOpen] = useState(false);

  const handleActionClick = (action) => {
    setSelectedAction(action);
    setIsActionModalOpen(true);
  };

  return (
    <div className="space-y-6">
      <ActionModal 
        isOpen={isActionModalOpen}
        onClose={() => setIsActionModalOpen(false)}
        action={selectedAction}
      />
      {/* Header Section */}
      <div className="healthcare-card p-6">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-full p-4">
              <Brain className="h-8 w-8 text-healthcare-primary dark:text-healthcare-primary-dark" />
            </div>
            <div>
              <h2 className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Process Health Score
              </h2>
              <div className="flex items-center gap-2 mt-1">
                <span className="text-4xl font-bold text-healthcare-primary dark:text-healthcare-primary-dark">
                  {healthScore.totalScore}
                </span>
                <span className="text-xl text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  / {healthScore.maxScore}
                </span>
                <div className={`flex items-center gap-1 ml-4 ${trend.color}`}>
                  <trend.icon className="h-5 w-5" />
                  <span className="text-sm font-medium">{trend.label}</span>
                </div>
              </div>
            </div>
          </div>
          <div className="grid grid-cols-4 gap-6">
            {Object.entries(healthScore.componentScores).map(([key, score]) => {
              const maxScore = key === 'waitTime' ? 25 : key === 'acuity' ? 15 : 20;
              const percentage = (score / maxScore) * 100;
              return (
                <div key={key} className="text-center">
                  <div className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">
                    {key.charAt(0).toUpperCase() + key.slice(1)}
                  </div>
                  <div className={`text-lg font-bold ${
                    percentage >= 70
                      ? 'text-healthcare-success dark:text-healthcare-success-dark'
                      : percentage >= 50
                      ? 'text-healthcare-warning dark:text-healthcare-warning-dark'
                      : 'text-healthcare-critical dark:text-healthcare-critical-dark'
                  }`}>
                    {score}/{maxScore}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </div>

      {/* Critical Actions Section */}
      <div className="healthcare-card p-6">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark flex items-center gap-2">
            <AlertCircle className="h-6 w-6 text-healthcare-critical dark:text-healthcare-critical-dark" />
            Critical Actions Required
          </h3>
          <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {criticalActions.length} items need attention
          </span>
        </div>
        <div className="space-y-4">
          {criticalActions.map((action, index) => (
            <div 
              key={index}
              className="healthcare-panel flex items-center justify-between hover:bg-healthcare-surface-hover dark:hover:bg-healthcare-surface-hover-dark cursor-pointer healthcare-transition"
              onClick={() => handleActionClick(action)}
            >
              <div className="flex items-center gap-4">
                <div className={`rounded-full p-2 ${
                  action.type === 'risk'
                    ? 'bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark'
                    : 'bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark'
                }`}>
                  {action.type === 'risk' ? <AlertTriangle className="h-5 w-5" /> : <Activity className="h-5 w-5" />}
                </div>
                <div>
                  <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {action.title}
                  </h4>
                  <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {action.type === 'risk' ? 'Risk Probability' : 'Expected Impact'}: {action.severity}%
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-4">
                <div className="text-right">
                  <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {action.timeframe}
                  </div>
                  <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {action.impact} Impact
                  </div>
                </div>
                <ChevronRight className="h-5 w-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Resource Predictions Section */}
      <div className="grid grid-cols-2 gap-6">
        <MetricChart
          title="Resource Utilization Forecast"
          height="64"
          yAxisLabel="Utilization %"
          xAxisDataKey="period"
          tooltipContent={({ active, payload, label }) => {
            if (!active || !payload || !payload.length) return null;
            return (
              <div className="healthcare-panel border border-healthcare-border dark:border-healthcare-border-dark">
                <p className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  {label}
                </p>
                {payload.map((entry, index) => (
                  <p 
                    key={index} 
                    className={`text-sm ${
                      entry.value >= entry.payload.critical
                        ? 'text-healthcare-critical dark:text-healthcare-critical-dark'
                        : entry.value >= entry.payload.threshold
                        ? 'text-healthcare-warning dark:text-healthcare-warning-dark'
                        : 'text-healthcare-success dark:text-healthcare-success-dark'
                    }`}
                  >
                    {entry.name}: {entry.value}%
                  </p>
                ))}
              </div>
            );
          }}
        >
          <BarChart data={resourcePredictions}>
            <Bar 
              dataKey="nurses" 
              name="Nurses" 
              fill={(data) => {
                if (data.nurses >= data.critical) return 'var(--healthcare-critical)';
                if (data.nurses >= data.threshold) return 'var(--healthcare-warning)';
                return 'var(--healthcare-primary)';
              }}
            />
            <Bar 
              dataKey="physicians" 
              name="Physicians" 
              fill={(data) => {
                if (data.physicians >= data.critical) return 'var(--healthcare-critical)';
                if (data.physicians >= data.threshold) return 'var(--healthcare-warning)';
                return 'var(--healthcare-success)';
              }}
            />
            <Bar 
              dataKey="rooms" 
              name="Rooms" 
              fill={(data) => {
                if (data.rooms >= data.critical) return 'var(--healthcare-critical)';
                if (data.rooms >= data.threshold) return 'var(--healthcare-warning)';
                return 'var(--healthcare-warning)';
              }}
            />
          </BarChart>
        </MetricChart>

        <div className="healthcare-card">
          <h3 className="font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
            Resource Impact Analysis
          </h3>
          <div className="space-y-4">
            {Object.entries(predictions.resourceUtilization.nextHour).map(([resource, data]) => {
              const utilization = Math.round((data.assigned / data.required) * 100);
              return (
                <div key={resource} className="healthcare-panel">
                  <div className="flex justify-between items-center">
                    <div>
                      <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark capitalize">
                        {resource}
                      </h4>
                      <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        {data.assigned} / {data.required} assigned
                      </p>
                    </div>
                    <div className="text-right">
                      <div className={`text-lg font-bold ${
                        utilization >= 90
                          ? 'text-healthcare-critical dark:text-healthcare-critical-dark'
                          : utilization >= 75
                          ? 'text-healthcare-warning dark:text-healthcare-warning-dark'
                          : 'text-healthcare-success dark:text-healthcare-success-dark'
                      }`}>
                        {utilization}%
                      </div>
                      <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        Utilization
                      </div>
                    </div>
                  </div>
                  <div className="mt-2 h-2 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-full overflow-hidden">
                    <div 
                      className={`h-full rounded-full ${
                        utilization >= 90
                          ? 'bg-healthcare-critical dark:bg-healthcare-critical-dark'
                          : utilization >= 75
                          ? 'bg-healthcare-warning dark:bg-healthcare-warning-dark'
                          : 'bg-healthcare-success dark:bg-healthcare-success-dark'
                      }`}
                      style={{ width: `${utilization}%` }}
                    />
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </div>

      {/* Optimization Recommendations */}
      <div className="healthcare-card">
        <h3 className="font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
          Optimization Recommendations
        </h3>
        <div className="grid grid-cols-3 gap-6">
          {['staffing', 'resources', 'workflow'].map(category => (
            <div key={category} className="space-y-4">
              <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark capitalize">
                {category} Optimizations
              </h4>
              <div className="space-y-2">
                {predictions.optimizationSuggestions[category].map((suggestion, index) => (
                  <div 
                    key={index}
                    className="healthcare-panel"
                  >
                    <div className="flex justify-between items-start">
                      <div>
                        <h5 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                          {suggestion.action.split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ')}
                        </h5>
                        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          Impact: {Math.round(suggestion.impact * 100)}%
                        </p>
                      </div>
                      <span className={`px-2 py-1 rounded text-sm font-medium ${
                        suggestion.urgency === 'high'
                          ? 'bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark'
                          : 'bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark'
                      }`}>
                        {suggestion.urgency.toUpperCase()}
                      </span>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
};

export default BottleneckSummary;
