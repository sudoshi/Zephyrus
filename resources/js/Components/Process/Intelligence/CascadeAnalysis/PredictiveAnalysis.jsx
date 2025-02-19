import React, { useState, useCallback } from 'react';
import { LineChart, Line, AreaChart, Area } from 'recharts';
import { Clock, TrendingUp, AlertTriangle, Activity } from 'lucide-react';
import MetricChart from '../Common/MetricChart';

const PredictiveAnalysis = ({ 
  cascadeData,
  timeframe = 'hour', // 'hour', 'shift', 'day'
  onScenarioChange
}) => {
  const [selectedScenario, setSelectedScenario] = useState(null);

  const getTimelineData = useCallback(() => {
    const intervals = timeframe === 'hour' ? 12 : timeframe === 'shift' ? 8 : 24;
    const baseData = cascadeData.affectedProcesses.map(process => ({
      id: process.id,
      name: process.name,
      severity: process.severity,
      timeImpact: process.timeImpact
    }));

    return Array.from({ length: intervals }, (_, i) => {
      const timePoint = timeframe === 'hour' 
        ? `${i * 5}m`
        : timeframe === 'shift'
        ? `${i + 1}h`
        : `${i + 1}h`;

      const predictions = baseData.map(process => {
        // Simulate impact changes over time
        const variationFactor = Math.sin(i / intervals * Math.PI);
        const severityChange = process.severity * 0.2 * variationFactor;
        
        return {
          ...process,
          predictedSeverity: Math.max(0, Math.min(1, process.severity + severityChange)),
          confidence: Math.max(0.5, 1 - (i / intervals) * 0.5)
        };
      });

      return {
        timePoint,
        predictions,
        totalImpact: predictions.reduce((sum, p) => sum + p.predictedSeverity, 0) / predictions.length
      };
    });
  }, [cascadeData, timeframe]);

  const getScenarios = useCallback(() => {
    return [
      {
        id: 'baseline',
        label: 'Baseline',
        description: 'Current trajectory without intervention',
        impact: 1.0
      },
      {
        id: 'mitigation',
        label: 'With Mitigation',
        description: 'Impact with proposed mitigation steps',
        impact: 0.7
      },
      {
        id: 'optimized',
        label: 'Optimized Response',
        description: 'Impact with optimized resource allocation',
        impact: 0.5
      }
    ];
  }, []);

  const timelineData = getTimelineData();
  const scenarios = getScenarios();

  const handleScenarioSelect = (scenario) => {
    setSelectedScenario(scenario);
    onScenarioChange?.(scenario);
  };

  return (
    <div className="space-y-6">
      {/* Scenario Selection */}
      <div className="grid grid-cols-3 gap-4">
        {scenarios.map(scenario => (
          <button
            key={scenario.id}
            onClick={() => handleScenarioSelect(scenario)}
            className={`healthcare-card p-4 text-left transition-all ${
              selectedScenario?.id === scenario.id
                ? 'ring-2 ring-healthcare-primary dark:ring-healthcare-primary-dark'
                : 'hover:bg-healthcare-surface-hover dark:hover:bg-healthcare-surface-hover-dark'
            }`}
          >
            <div className="flex items-center gap-3 mb-2">
              <div className={`rounded-full p-2 ${
                scenario.id === 'baseline'
                  ? 'bg-healthcare-warning/10 text-healthcare-warning'
                  : scenario.id === 'mitigation'
                  ? 'bg-healthcare-info/10 text-healthcare-info'
                  : 'bg-healthcare-success/10 text-healthcare-success'
              }`}>
                {scenario.id === 'baseline' ? (
                  <TrendingUp className="h-5 w-5" />
                ) : scenario.id === 'mitigation' ? (
                  <Activity className="h-5 w-5" />
                ) : (
                  <Clock className="h-5 w-5" />
                )}
              </div>
              <div>
                <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  {scenario.label}
                </h4>
                <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  Impact Factor: {scenario.impact * 100}%
                </p>
              </div>
            </div>
            <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {scenario.description}
            </p>
          </button>
        ))}
      </div>

      {/* Impact Timeline */}
      <div className="healthcare-card">
        <h3 className="font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
          Predicted Impact Timeline
        </h3>
        <div className="h-64">
          <MetricChart
            height="64"
            yAxisLabel="Impact Severity %"
            xAxisDataKey="timePoint"
          >
            <AreaChart data={timelineData}>
              <defs>
                <linearGradient id="impactGradient" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%" stopColor="var(--healthcare-warning)" stopOpacity={0.8}/>
                  <stop offset="95%" stopColor="var(--healthcare-warning)" stopOpacity={0.2}/>
                </linearGradient>
              </defs>
              <Area
                type="monotone"
                dataKey="totalImpact"
                stroke="var(--healthcare-warning)"
                fill="url(#impactGradient)"
                name="Impact Severity"
              />
            </AreaChart>
          </MetricChart>
        </div>
      </div>

      {/* Process-specific Predictions */}
      <div className="grid grid-cols-2 gap-6">
        {cascadeData.affectedProcesses.map((process, index) => (
          <div key={process.id} className="healthcare-card">
            <div className="flex items-center justify-between mb-4">
              <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                {process.name}
              </h4>
              <div className={`px-2 py-1 rounded text-sm ${
                process.severity > 0.8
                  ? 'bg-healthcare-critical/10 text-healthcare-critical'
                  : process.severity > 0.6
                  ? 'bg-healthcare-warning/10 text-healthcare-warning'
                  : 'bg-healthcare-info/10 text-healthcare-info'
              }`}>
                {Math.round(process.severity * 100)}% Impact
              </div>
            </div>
            <div className="h-32">
              <MetricChart
                height="32"
                yAxisLabel="Severity %"
                xAxisDataKey="timePoint"
              >
                <LineChart data={timelineData}>
                  <Line
                    type="monotone"
                    dataKey={`predictions[${index}].predictedSeverity`}
                    stroke="var(--healthcare-primary)"
                    strokeWidth={2}
                    dot={false}
                  />
                </LineChart>
              </MetricChart>
            </div>
          </div>
        ))}
      </div>

      {/* Confidence Indicators */}
      {selectedScenario && (
        <div className="healthcare-card">
          <div className="flex items-center gap-3 mb-4">
            <AlertTriangle className="h-5 w-5 text-healthcare-warning" />
            <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Prediction Confidence
            </h4>
          </div>
          <div className="space-y-4">
            {timelineData[0].predictions.map((prediction, index) => (
              <div key={index} className="flex items-center gap-4">
                <div className="w-32 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {prediction.name}
                </div>
                <div className="flex-1 h-2 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-full overflow-hidden">
                  <div
                    className="h-full bg-healthcare-info rounded-full transition-all"
                    style={{ width: `${prediction.confidence * 100}%` }}
                  />
                </div>
                <div className="w-16 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark text-right">
                  {Math.round(prediction.confidence * 100)}%
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
};

export default PredictiveAnalysis;
