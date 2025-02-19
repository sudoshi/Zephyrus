import React, { useState, useCallback } from 'react';
import { BarChart, Bar, LineChart, Line } from 'recharts';
import { 
  ChartBar, 
  ArrowUpRight, 
  ArrowDownRight, 
  Target,
  TrendingUp,
  Calendar
} from 'lucide-react';
import MetricChart from '../Common/MetricChart';

const ComparisonTools = ({
  data,
  benchmarks,
  historicalData,
  targets,
  className = ''
}) => {
  const [selectedMetric, setSelectedMetric] = useState('wait_time');
  const [timeRange, setTimeRange] = useState('week');

  const metrics = [
    { id: 'wait_time', label: 'Wait Time' },
    { id: 'throughput', label: 'Throughput' },
    { id: 'utilization', label: 'Utilization' }
  ];

  const ranges = [
    { id: 'day', label: 'Today' },
    { id: 'week', label: 'This Week' },
    { id: 'month', label: 'This Month' },
    { id: 'quarter', label: 'This Quarter' }
  ];

  const getPerformanceData = useCallback(() => {
    if (!data?.current) return [];
    return Object.entries(data.current).map(([step, value]) => ({
      step: step.replace(/([A-Z])/g, ' $1').toLowerCase(),
      current: value || 0,
      benchmark: benchmarks?.[step] || 0,
      target: targets?.[step] || 0,
      variance: benchmarks?.[step] 
        ? Math.round(((value || 0) - benchmarks[step]) / benchmarks[step] * 100)
        : 0
    }));
  }, [data, benchmarks, targets]);

  const getTrendData = useCallback(() => {
    return (historicalData || []).map(point => ({
      ...point,
      step: point.step?.replace(/([A-Z])/g, ' $1').toLowerCase(),
      benchmark: benchmarks?.[point.step] || 0,
      target: targets?.[point.step] || 0
    }));
  }, [historicalData, benchmarks, targets]);

  const performanceData = getPerformanceData();
  const trendData = getTrendData();

  const getVarianceColor = (variance) => {
    if (variance > 20) return 'text-healthcare-critical';
    if (variance > 10) return 'text-healthcare-warning';
    if (variance < -10) return 'text-healthcare-success';
    return 'text-healthcare-text-secondary';
  };

  if (!data?.current || Object.keys(data.current).length === 0) {
    return (
      <div className={`healthcare-card ${className}`}>
        <h3 className="font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4 flex items-center gap-2">
          <ChartBar className="h-5 w-5 text-healthcare-primary" />
          Performance vs Benchmarks
        </h3>
        <div className="text-healthcare-text-secondary">
          No comparison data available
        </div>
      </div>
    );
  }

  return (
    <div className={`space-y-6 ${className}`}>
      {/* Controls */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          {metrics.map(metric => (
            <button
              key={metric.id}
              onClick={() => setSelectedMetric(metric.id)}
              className={`px-3 py-1.5 rounded-md text-sm font-medium transition-colors ${
                selectedMetric === metric.id
                  ? 'bg-healthcare-primary text-white'
                  : 'text-healthcare-text-secondary hover:text-healthcare-text-primary'
              }`}
            >
              {metric.label}
            </button>
          ))}
        </div>

        <div className="flex items-center gap-2">
          {ranges.map(range => (
            <button
              key={range.id}
              onClick={() => setTimeRange(range.id)}
              className={`px-3 py-1.5 rounded-md text-sm font-medium transition-colors ${
                timeRange === range.id
                  ? 'bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark text-healthcare-text-primary'
                  : 'text-healthcare-text-secondary hover:text-healthcare-text-primary'
              }`}
            >
              {range.label}
            </button>
          ))}
        </div>
      </div>

      {/* Performance Overview */}
      <div className="healthcare-card">
        <h3 className="font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4 flex items-center gap-2">
          <ChartBar className="h-5 w-5 text-healthcare-primary" />
          Performance vs Benchmarks
        </h3>

        <div className="grid grid-cols-3 gap-6 mb-6">
          {performanceData.map(item => (
            <div key={item.step} className="healthcare-panel">
              <div className="flex items-center justify-between mb-2">
                <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark capitalize">
                  {item.step}
                </h4>
                <div className={`flex items-center gap-1 ${getVarianceColor(item.variance)}`}>
                  {item.variance > 0 ? (
                    <ArrowUpRight className="h-4 w-4" />
                  ) : (
                    <ArrowDownRight className="h-4 w-4" />
                  )}
                  <span className="text-sm font-medium">
                    {Math.abs(Math.round(item.variance))}%
                  </span>
                </div>
              </div>
              
              <div className="space-y-2">
                <div className="flex justify-between text-sm">
                  <span className="text-healthcare-text-secondary">Current</span>
                  <span className="font-medium text-healthcare-text-primary">{item.current}min</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-healthcare-text-secondary">Benchmark</span>
                  <span className="font-medium text-healthcare-info">{item.benchmark}min</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-healthcare-text-secondary">Target</span>
                  <span className="font-medium text-healthcare-success">{item.target}min</span>
                </div>
              </div>
            </div>
          ))}
        </div>

        <MetricChart
          title="Performance Trends"
          height="64"
          yAxisLabel="Minutes"
          xAxisDataKey="timestamp"
        >
          <LineChart data={trendData}>
            <Line
              type="monotone"
              dataKey="value"
              name="Current"
              stroke="var(--healthcare-primary)"
              strokeWidth={2}
            />
            <Line
              type="monotone"
              dataKey="benchmark"
              name="Benchmark"
              stroke="var(--healthcare-info)"
              strokeWidth={2}
              strokeDasharray="5 5"
            />
            <Line
              type="monotone"
              dataKey="target"
              name="Target"
              stroke="var(--healthcare-success)"
              strokeWidth={2}
              strokeDasharray="3 3"
            />
          </LineChart>
        </MetricChart>
      </div>

      {/* Target Tracking */}
      <div className="healthcare-card">
        <h3 className="font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4 flex items-center gap-2">
          <Target className="h-5 w-5 text-healthcare-warning" />
          Target Progress
        </h3>

        <div className="grid grid-cols-2 gap-6">
          <div className="space-y-4">
            {performanceData.map(item => {
              const progress = item.target ? Math.min(100, (item.target / item.current) * 100) : 0;
              return (
                <div key={item.step} className="healthcare-panel">
                  <div className="flex items-center justify-between mb-2">
                    <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark capitalize">
                      {item.step}
                    </h4>
                    <span className="text-sm font-medium text-healthcare-text-secondary">
                      {Math.round(progress)}% of target
                    </span>
                  </div>
                  <div className="h-2 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-full overflow-hidden">
                    <div
                      className="h-full rounded-full transition-all bg-healthcare-warning"
                      style={{ width: `${progress}%` }}
                    />
                  </div>
                </div>
              );
            })}
          </div>

          <MetricChart
            title="Target vs Actual"
            height="64"
            yAxisLabel="Minutes"
            xAxisDataKey="step"
          >
            <BarChart data={performanceData}>
              <Bar
                dataKey="current"
                name="Current"
                fill="var(--healthcare-warning)"
              />
              <Bar
                dataKey="target"
                name="Target"
                fill="var(--healthcare-success)"
              />
            </BarChart>
          </MetricChart>
        </div>
      </div>
    </div>
  );
};

export default ComparisonTools;
