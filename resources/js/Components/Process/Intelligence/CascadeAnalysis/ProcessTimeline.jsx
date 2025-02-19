import React, { useState, useCallback } from 'react';
import { Clock, AlertTriangle, CheckCircle, ArrowRight } from 'lucide-react';
import StatusTooltip from '../ResourceAnalysis/StatusTooltip';

const ProcessTimeline = ({ 
  cascadeData,
  onPhaseClick,
  className = ''
}) => {
  const [expandedProcess, setExpandedProcess] = useState(null);

  const getPhaseStatus = useCallback((phase, process) => {
    const severity = process.severity || 0;
    const timeImpact = process.timeImpact || 0;

    if (phase.status === 'complete') return 'complete';
    if (phase.status === 'blocked') return 'blocked';
    if (severity > 0.8) return 'critical';
    if (severity > 0.6) return 'warning';
    if (timeImpact > 60) return 'delayed';
    return 'active';
  }, []);

  const getStatusColor = useCallback((status) => {
    switch (status) {
      case 'complete':
        return 'bg-healthcare-success text-healthcare-success';
      case 'blocked':
        return 'bg-healthcare-critical text-healthcare-critical';
      case 'critical':
        return 'bg-healthcare-critical text-healthcare-critical';
      case 'warning':
        return 'bg-healthcare-warning text-healthcare-warning';
      case 'delayed':
        return 'bg-healthcare-info text-healthcare-info';
      default:
        return 'bg-healthcare-primary text-healthcare-primary';
    }
  }, []);

  const getStatusIcon = useCallback((status) => {
    switch (status) {
      case 'complete':
        return CheckCircle;
      case 'blocked':
      case 'critical':
        return AlertTriangle;
      default:
        return Clock;
    }
  }, []);

  const getPhaseTooltip = useCallback((phase, process) => {
    return (
      <div className="space-y-2">
        <div className="font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          {phase.name}
        </div>
        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Process: {process.name}
        </div>
        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Status: {phase.status}
        </div>
        {phase.dependencies?.length > 0 && (
          <div className="pt-2 border-t border-healthcare-border dark:border-healthcare-border-dark">
            <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Dependencies:
            </div>
            <ul className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {phase.dependencies.map((dep, i) => (
                <li key={i}>• {dep}</li>
              ))}
            </ul>
          </div>
        )}
      </div>
    );
  }, []);

  return (
    <div className={`space-y-6 ${className}`}>
      {cascadeData.affectedProcesses.map(process => {
        const isExpanded = expandedProcess === process.id;
        const phases = [
          { name: 'Impact Start', status: 'complete', time: 'Now' },
          { name: 'Resource Allocation', status: 'active', time: '+15m', dependencies: ['Staff Availability'] },
          { name: 'Process Adjustment', status: process.severity > 0.8 ? 'blocked' : 'active', time: '+30m', dependencies: ['Resource Allocation'] },
          { name: 'Stabilization', status: 'pending', time: '+1h', dependencies: ['Process Adjustment'] },
          { name: 'Recovery', status: 'pending', time: '+2h', dependencies: ['Stabilization'] }
        ];

        return (
          <div key={process.id} className="healthcare-card">
            {/* Process Header */}
            <button
              onClick={() => setExpandedProcess(isExpanded ? null : process.id)}
              className="w-full flex items-center justify-between p-4 hover:bg-healthcare-surface dark:hover:bg-healthcare-surface-dark rounded-t-lg healthcare-transition"
            >
              <div className="flex items-center gap-3">
                <div className={`rounded-full p-2 ${
                  process.severity > 0.8
                    ? 'bg-healthcare-critical/10 text-healthcare-critical'
                    : process.severity > 0.6
                    ? 'bg-healthcare-warning/10 text-healthcare-warning'
                    : 'bg-healthcare-info/10 text-healthcare-info'
                }`}>
                  <Clock className="h-5 w-5" />
                </div>
                <div>
                  <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {process.name}
                  </h4>
                  <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Impact Duration: {process.timeImpact} minutes
                  </p>
                </div>
              </div>
              <ArrowRight className={`h-5 w-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-transform ${isExpanded ? 'rotate-90' : ''}`} />
            </button>

            {/* Timeline */}
            {isExpanded && (
              <div className="p-4 pt-2">
                <div className="relative">
                  {phases.map((phase, index) => {
                    const status = getPhaseStatus(phase, process);
                    const StatusIcon = getStatusIcon(status);
                    const isLast = index === phases.length - 1;

                    return (
                      <div key={index} className="relative">
                        <div className="flex items-center gap-4 mb-4">
                          <StatusTooltip content={getPhaseTooltip(phase, process)}>
                            <div className={`relative z-10 rounded-full p-2 ${getStatusColor(status)}/10`}>
                              <StatusIcon className={`h-5 w-5 ${getStatusColor(status)}`} />
                            </div>
                          </StatusTooltip>
                          <div className="flex-1">
                            <div className="flex items-center justify-between">
                              <h5 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {phase.name}
                              </h5>
                              <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                {phase.time}
                              </span>
                            </div>
                            {phase.dependencies?.length > 0 && (
                              <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                                Depends on: {phase.dependencies.join(', ')}
                              </p>
                            )}
                          </div>
                        </div>
                        {!isLast && (
                          <div className="absolute left-[23px] top-[36px] bottom-0 w-px bg-healthcare-border dark:bg-healthcare-border-dark" />
                        )}
                      </div>
                    );
                  })}
                </div>
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
};

export default ProcessTimeline;
