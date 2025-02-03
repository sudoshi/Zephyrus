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
  ResponsiveContainer,
  PieChart,
  Pie,
  Cell,
  BarChart,
  Bar
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
  <Card>
    <Card.Content>
      <div className="space-y-2">
        <div className="flex items-center justify-between">
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
    </Card.Content>
  </Card>
);

const COLORS = {
  "General Surgery": "#3B82F6", // blue
  "Orthopedics": "#10B981",    // green
  "OBGYN": "#EC4899",          // pink
  "Cardiac": "#EF4444",        // red
  "Cath Lab": "#F59E0B"        // yellow
};

const CaseAnalytics = ({ data, specialties }) => {
  // Calculate trends and metrics
  const currentMonth = data[data.length - 1];
  const previousMonth = data[data.length - 2];
  
  const casesChange = ((currentMonth.cases - previousMonth.cases) / previousMonth.cases * 100).toFixed(1);
  const durationChange = ((currentMonth.avgDuration - previousMonth.avgDuration) / previousMonth.avgDuration * 100).toFixed(1);
  const timeChange = ((currentMonth.totalTime - previousMonth.totalTime) / previousMonth.totalTime * 100).toFixed(1);

  return (
    <div className="space-y-6">
      {/* Date Range Selector */}
      <Card>
        <Card.Content>
          <div className="flex items-center justify-between">
            <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Analytics Overview
            </h3>
            <div className="flex items-center space-x-4">
              <select className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-3 pr-8 py-1.5 bg-healthcare-surface dark:bg-healthcare-surface-dark">
                <option value="7">Last 7 Days</option>
                <option value="30">Last 30 Days</option>
                <option value="90">Last 90 Days</option>
                <option value="365">Last Year</option>
              </select>
            </div>
          </div>
        </Card.Content>
      </Card>

      {/* Metric Cards */}
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

      {/* Trends Chart */}
      <Card>
        <Card.Header>
          <Card.Title>Case Volume & Duration Trends</Card.Title>
          <div className="flex items-center space-x-4">
            <button className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark">
              Daily
            </button>
            <button className="text-sm text-healthcare-info dark:text-healthcare-info-dark font-medium">
              Monthly
            </button>
            <button className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark">
              Yearly
            </button>
          </div>
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

      {/* Specialty Distribution */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card>
          <Card.Header>
            <Card.Title>Case Distribution by Specialty</Card.Title>
          </Card.Header>
          <Card.Content>
            <div className="h-[300px]">
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                  <Pie
                    data={Object.entries(specialties).map(([name, data]) => ({
                      name,
                      value: data.count
                    }))}
                    cx="50%"
                    cy="50%"
                    innerRadius={60}
                    outerRadius={100}
                    paddingAngle={2}
                    dataKey="value"
                  >
                    {Object.entries(specialties).map(([name]) => (
                      <Cell key={name} fill={COLORS[name]} />
                    ))}
                  </Pie>
                  <Tooltip
                    content={({ active, payload }) => {
                      if (active && payload && payload.length) {
                        const data = payload[0].payload;
                        return (
                          <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-3 rounded-lg shadow-lg border border-healthcare-border dark:border-healthcare-border-dark">
                            <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                              {data.name}
                            </p>
                            <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                              Cases: {data.value}
                            </p>
                          </div>
                        );
                      }
                      return null;
                    }}
                  />
                  <Legend />
                </PieChart>
              </ResponsiveContainer>
            </div>
          </Card.Content>
        </Card>

        <Card>
          <Card.Header>
            <Card.Title>On-Time Performance by Specialty</Card.Title>
          </Card.Header>
          <Card.Content>
            <div className="h-[300px]">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  data={Object.entries(specialties).map(([name, data]) => ({
                    name,
                    onTime: data.onTime,
                    delayed: data.delayed,
                    color: COLORS[name]
                  }))}
                  margin={{
                    top: 20,
                    right: 30,
                    left: 20,
                    bottom: 20,
                  }}
                >
                  <CartesianGrid strokeDasharray="3 3" className="stroke-healthcare-border dark:stroke-healthcare-border-dark" />
                  <XAxis dataKey="name" className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
                  <YAxis className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
                  <Tooltip
                    content={({ active, payload, label }) => {
                      if (active && payload && payload.length) {
                        const total = payload[0].value + payload[1].value;
                        const onTimePercent = ((payload[0].value / total) * 100).toFixed(1);
                        return (
                          <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-3 rounded-lg shadow-lg border border-healthcare-border dark:border-healthcare-border-dark">
                            <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-1">
                              {label}
                            </p>
                            <p className="text-sm text-healthcare-success dark:text-healthcare-success-dark">
                              On Time: {payload[0].value} ({onTimePercent}%)
                            </p>
                            <p className="text-sm text-healthcare-error dark:text-healthcare-error-dark">
                              Delayed: {payload[1].value}
                            </p>
                          </div>
                        );
                      }
                      return null;
                    }}
                  />
                  <Legend />
                  <Bar dataKey="onTime" name="On Time" fill="#10B981" stackId="a" />
                  <Bar dataKey="delayed" name="Delayed" fill="#EF4444" stackId="a" />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </Card.Content>
        </Card>
      </div>
    </div>
  );
};

export default CaseAnalytics;
