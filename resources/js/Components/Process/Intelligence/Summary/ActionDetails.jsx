import React, { useState, useEffect, useCallback } from 'react';
import { AlertTriangle, Activity, Clock, Users, ArrowRight } from 'lucide-react';
import MetricChart from '../Common/MetricChart';
import LoadingSpinner from './LoadingSpinner';
import ErrorState from './ErrorState';

const ActionDetails = ({ action }) => {
  const [state, setState] = useState({
    isLoading: true,
    error: null,
    impactData: [],
    timelineData: [],
    relatedActions: []
  });

  const getImpactData = () => {
    if (action.type === 'risk') {
      return [
        { name: 'Staff Impact', value: action.severity * 0.8 },
        { name: 'Patient Flow', value: action.severity * 0.9 },
        { name: 'Resource Usage', value: action.severity * 0.7 },
        { name: 'Quality of Care', value: action.severity * 0.85 }
      ];
    }
    return [
      { name: 'Efficiency Gain', value: action.severity * 0.9 },
      { name: 'Cost Reduction', value: action.severity * 0.7 },
      { name: 'Staff Satisfaction', value: action.severity * 0.8 },
      { name: 'Patient Experience', value: action.severity * 0.85 }
    ];
  };

  const getTimelineData = () => {
    return [
      { phase: 'Identification', status: 'complete', time: '2 hours ago' },
      { phase: 'Analysis', status: 'complete', time: '1 hour ago' },
      { phase: 'Planning', status: 'active', time: 'In Progress' },
      { phase: 'Implementation', status: 'pending', time: 'Not Started' },
      { phase: 'Review', status: 'pending', time: 'Not Started' }
    ];
  };

  const getRelatedActions = () => {
    return [
      {
        title: 'Update Staffing Schedule',
        impact: 65,
        timeframe: 'Next Shift'
      },
      {
        title: 'Reallocate Resources',
        impact: 55,
        timeframe: 'Today'
      },
      {
        title: 'Adjust Process Flow',
        impact: 45,
        timeframe: 'This Week'
      }
    ];
  };

  const loadData = useCallback(async () => {
    setState(prev => ({ ...prev, isLoading: true, error: null }));
    try {
      // Simulate API delay and random error
      await new Promise((resolve, reject) => {
        setTimeout(() => {
          // Simulate 10% chance of error
          if (Math.random() < 0.1) {
            reject(new Error('Failed to fetch action details'));
          }
          resolve();
        }, 1000);
      });
      
      setState(prev => ({
        ...prev,
        isLoading: false,
        impactData: getImpactData(),
        timelineData: getTimelineData(),
        relatedActions: getRelatedActions()
      }));
    } catch (error) {
      setState(prev => ({
        ...prev,
        isLoading: false,
        error: error.message
      }));
    }
  }, [action]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  if (state.isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (state.error) {
    return (
      <ErrorState
        message={state.error}
        onRetry={loadData}
      />
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <div className={`rounded-full p-3 ${
            action.type === 'risk'
              ? 'bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark'
              : 'bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark'
          }`}>
            {action.type === 'risk' ? <AlertTriangle className="h-6 w-6" /> : <Activity className="h-6 w-6" />}
          </div>
          <div>
            <h3 className="text-xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {action.title}
            </h3>
            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {action.type === 'risk' ? 'Risk Probability' : 'Expected Impact'}: {action.severity}%
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Clock className="h-5 w-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
          <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {action.timeframe}
          </span>
        </div>
      </div>

      {/* Impact Analysis */}
      <div className="grid grid-cols-2 gap-6 animate-fadeIn">
        <div className="healthcare-card animate-slideIn">
          <h4 className="font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
            Impact Analysis
          </h4>
          <div className="space-y-4">
            {state.impactData.map((impact, index) => (
              <div key={index} className="healthcare-panel">
                <div className="flex justify-between items-center mb-2">
                  <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {impact.name}
                  </span>
                  <span className={`font-medium ${
                    impact.value >= 80
                      ? 'text-healthcare-critical dark:text-healthcare-critical-dark'
                      : impact.value >= 60
                      ? 'text-healthcare-warning dark:text-healthcare-warning-dark'
                      : 'text-healthcare-success dark:text-healthcare-success-dark'
                  }`}>
                    {Math.round(impact.value)}%
                  </span>
                </div>
                <div className="h-2 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-full overflow-hidden">
                  <div 
                    className={`h-full rounded-full ${
                      impact.value >= 80
                        ? 'bg-healthcare-critical dark:bg-healthcare-critical-dark'
                        : impact.value >= 60
                        ? 'bg-healthcare-warning dark:bg-healthcare-warning-dark'
                        : 'bg-healthcare-success dark:bg-healthcare-success-dark'
                    }`}
                    style={{ width: `${impact.value}%` }}
                  />
                </div>
              </div>
            ))}
          </div>
        </div>

        <div className="healthcare-card animate-slideIn" style={{ animationDelay: '100ms' }}>
          <h4 className="font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
            Action Timeline
          </h4>
          <div className="space-y-4">
            {state.timelineData.map((phase, index) => (
              <div key={index} className="healthcare-panel flex items-center gap-4">
                <div className={`rounded-full w-3 h-3 ${
                  phase.status === 'complete'
                    ? 'bg-healthcare-success dark:bg-healthcare-success-dark'
                    : phase.status === 'active'
                    ? 'bg-healthcare-warning dark:bg-healthcare-warning-dark'
                    : 'bg-healthcare-border dark:bg-healthcare-border-dark'
                }`} />
                <div className="flex-1">
                  <div className="flex justify-between">
                    <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {phase.phase}
                    </span>
                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {phase.time}
                    </span>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* Related Actions */}
      <div className="healthcare-card">
        <h4 className="font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
          Related Actions
        </h4>
        <div className="grid grid-cols-3 gap-4">
          {state.relatedActions.map((related, index) => (
            <div 
              key={index}
              className="healthcare-panel flex items-center justify-between hover:bg-healthcare-surface-hover dark:hover:bg-healthcare-surface-hover-dark cursor-pointer healthcare-transition"
            >
              <div>
                <h5 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  {related.title}
                </h5>
                <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  Impact: {related.impact}%
                </p>
                <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {related.timeframe}
                </p>
              </div>
              <ArrowRight className="h-5 w-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
            </div>
          ))}
        </div>
      </div>
    </div>
  );
};

export default ActionDetails;
