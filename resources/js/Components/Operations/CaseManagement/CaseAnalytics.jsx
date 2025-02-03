import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';
import {
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  ResponsiveContainer
} from 'recharts';

const CustomTooltip = ({ active, payload, label }) => {
  if (active && payload && payload.length) {
    return (
      <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg shadow-lg border border-healthcare-border dark:border-healthcare-border-dark">
        <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">{label}</p>
        {payload.map((entry, index) => (
          <div key={index} className="flex items-center space-x-2">
            <div
              className="h-2 w-2 rounded-full"
              style={{ backgroundColor: entry.color }}
            />
            <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {entry.name}: {entry.value}
            </p>
          </div>
        ))}
      </div>
    );
  }
  return null;
};

const MetricCard = ({ title, icon, value, trend, trendValue }) => (
  <div className="p-4 border rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark">
    <div className="flex items-center justify-between mb-2">
      <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        {title}
      </span>
      <Icon icon={icon} className="h-4 w-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
    </div>
    <div className="flex items-baseline justify-between">
      <span className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        {value}
      </span>
      <span className={`text-sm ${trend === 'up' ? 'text-healthcare-success dark:text-healthcare-success-dark' : 'text-healthcare-error dark:text-healthcare-error-dark'}`}>
        {trend === 'up' ? '↑' : '↓'} {trendValue}
      </span>
    </div>
  </div>
);

const CaseAnalytics = ({ data }) => {
  // Calculate trends and metrics
  const currentMonth = data[data.length - 1];
  const previousMonth = data[data.length - 2];
  
  const casesChange = ((currentMonth.cases - previousMonth.cases) / previousMonth.cases * 100).toFixed(1);
  const durationChange = ((currentMonth.avgDuration - previousMonth.avgDuration) / previousMonth.avgDuration * 100).toFixed(1);
  const timeChange = ((currentMonth.totalTime - previousMonth.totalTime) / previousMonth.totalTime * 100).toFixed(1);

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <MetricCard
          title="Total Cases"
          icon="heroicons:users"
          value={currentMonth.cases}
          trend={casesChange > 0 ? 'up' : 'down'}
          trendValue={`${Math.abs(casesChange)}%`}
        />
        <MetricCard
          title="Average Duration"
          icon="heroicons:clock"
          value={`${currentMonth.avgDuration}m`}
          trend={durationChange < 0 ? 'up' : 'down'}
          trendValue={`${Math.abs(durationChange)}%`}
        />
        <MetricCard
          title="Total OR Time"
          icon="heroicons:chart-bar"
          value={`${(currentMonth.totalTime / 60).toFixed(0)}h`}
          trend={timeChange > 0 ? 'up' : 'down'}
          trendValue={`${Math.abs(timeChange)}%`}
        />
      </div>

      <Card>
        <Card.Header>
          <Card.Title>Case Volume & Duration Trends</Card.Title>
        </Card.Header>
        <Card.Content>
          <div className="h-[400px]">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart
                data={data}
                margin={{
                  top: 20,
                  right: 30,
                  left: 20,
                  bottom: 20,
                }}
              >
                <CartesianGrid
                  strokeDasharray="3 3"
                  className="stroke-healthcare-border dark:stroke-healthcare-border-dark"
                />
                <XAxis
                  dataKey="month"
                  className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                />
                <YAxis
                  yAxisId="left"
                  className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                />
                <YAxis
                  yAxisId="right"
                  orientation="right"
                  className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                />
                <Tooltip content={<CustomTooltip />} />
                <Legend />
                <Line
                  yAxisId="left"
                  type="monotone"
                  dataKey="cases"
                  name="Cases"
                  stroke="#3B82F6"
                  activeDot={{ r: 8 }}
                  strokeWidth={2}
                />
                <Line
                  yAxisId="right"
                  type="monotone"
                  dataKey="avgDuration"
                  name="Avg Duration (min)"
                  stroke="#10B981"
                  activeDot={{ r: 8 }}
                  strokeWidth={2}
                />
              </LineChart>
            </ResponsiveContainer>
          </div>
        </Card.Content>
      </Card>
    </div>
  );
};

export default CaseAnalytics;
