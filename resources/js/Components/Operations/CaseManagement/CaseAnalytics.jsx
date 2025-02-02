import React from 'react';
import {
  LineChart,
  Line,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer
} from 'recharts';
import { Card, CardHeader, CardTitle, CardContent } from '@/Components/ui/Card';

const formatMinutesToTime = (minutes) => {
  const hours = Math.floor(minutes / 60);
  const mins = minutes % 60;
  return `${hours} Hours and ${mins} Minutes`;
};

const CustomTimeTooltip = ({ active, payload, label }) => {
  if (active && payload && payload.length) {
    return (
      <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 border border-healthcare-border dark:border-healthcare-border-dark rounded-lg shadow-lg">
        <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</p>
        <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          Total Time: {payload[0].value}
        </p>
        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {formatMinutesToTime(payload[0].value)}
        </p>
      </div>
    );
  }
  return null;
};

const AnalyticsCard = ({ title, children }) => (
  <Card>
    <CardHeader>
      <CardTitle className="text-lg text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        {title}
      </CardTitle>
    </CardHeader>
    <CardContent>
      <div className="h-96">
        {children}
      </div>
    </CardContent>
  </Card>
);

const CaseAnalytics = ({ data }) => {
  return (
    <div className="space-y-8">
      <AnalyticsCard title="Total # of Cases / Month Trend">
        <ResponsiveContainer width="100%" height="100%">
          <LineChart data={data} margin={{ top: 20, right: 30, left: 20, bottom: 20 }}>
            <CartesianGrid 
              strokeDasharray="3 3" 
              stroke="var(--healthcare-border)"
              opacity={0.5}
            />
            <XAxis 
              dataKey="month" 
              stroke="var(--healthcare-text-secondary)"
              fontSize={12}
            />
            <YAxis 
              domain={[350, 500]} 
              stroke="var(--healthcare-text-secondary)"
              fontSize={12}
            />
            <Tooltip
              contentStyle={{
                backgroundColor: 'var(--healthcare-surface)',
                borderColor: 'var(--healthcare-border)',
                borderRadius: '0.5rem'
              }}
              labelStyle={{
                color: 'var(--healthcare-text-secondary)'
              }}
              itemStyle={{
                color: 'var(--healthcare-text-primary)'
              }}
            />
            <Line
              type="monotone"
              dataKey="cases"
              stroke="var(--healthcare-info)"
              strokeWidth={2}
              dot={{ fill: 'var(--healthcare-info)' }}
              activeDot={{ r: 6, fill: 'var(--healthcare-info)' }}
            />
          </LineChart>
        </ResponsiveContainer>
      </AnalyticsCard>

      <AnalyticsCard title="Average Case Duration by Site">
        <ResponsiveContainer width="100%" height="100%">
          <LineChart data={data} margin={{ top: 20, right: 30, left: 20, bottom: 20 }}>
            <CartesianGrid 
              strokeDasharray="3 3" 
              stroke="var(--healthcare-border)"
              opacity={0.5}
            />
            <XAxis 
              dataKey="month" 
              stroke="var(--healthcare-text-secondary)"
              fontSize={12}
            />
            <YAxis 
              domain={[80, 110]} 
              stroke="var(--healthcare-text-secondary)"
              fontSize={12}
            />
            <Tooltip
              contentStyle={{
                backgroundColor: 'var(--healthcare-surface)',
                borderColor: 'var(--healthcare-border)',
                borderRadius: '0.5rem'
              }}
              labelStyle={{
                color: 'var(--healthcare-text-secondary)'
              }}
              itemStyle={{
                color: 'var(--healthcare-text-primary)'
              }}
            />
            <Line
              type="monotone"
              dataKey="avgDuration"
              stroke="var(--healthcare-success)"
              strokeWidth={2}
              dot={{ fill: 'var(--healthcare-success)' }}
              activeDot={{ r: 6, fill: 'var(--healthcare-success)' }}
            />
          </LineChart>
        </ResponsiveContainer>
      </AnalyticsCard>

      <AnalyticsCard title="Total # of Cases / Month">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={data} margin={{ top: 20, right: 30, left: 20, bottom: 20 }}>
            <CartesianGrid 
              strokeDasharray="3 3" 
              stroke="var(--healthcare-border)"
              opacity={0.5}
            />
            <XAxis 
              dataKey="month" 
              stroke="var(--healthcare-text-secondary)"
              fontSize={12}
            />
            <YAxis 
              domain={[350, 500]} 
              stroke="var(--healthcare-text-secondary)"
              fontSize={12}
            />
            <Tooltip
              contentStyle={{
                backgroundColor: 'var(--healthcare-surface)',
                borderColor: 'var(--healthcare-border)',
                borderRadius: '0.5rem'
              }}
              labelStyle={{
                color: 'var(--healthcare-text-secondary)'
              }}
              itemStyle={{
                color: 'var(--healthcare-text-primary)'
              }}
            />
            <Bar 
              dataKey="cases" 
              fill="var(--healthcare-info)"
              radius={[4, 4, 0, 0]}
            />
          </BarChart>
        </ResponsiveContainer>
      </AnalyticsCard>

      <AnalyticsCard title="Total Time in OR / Month (Minutes)">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={data} margin={{ top: 20, right: 30, left: 20, bottom: 20 }}>
            <CartesianGrid 
              strokeDasharray="3 3" 
              stroke="var(--healthcare-border)"
              opacity={0.5}
            />
            <XAxis 
              dataKey="month" 
              stroke="var(--healthcare-text-secondary)"
              fontSize={12}
            />
            <YAxis 
              domain={[30000, 45000]} 
              stroke="var(--healthcare-text-secondary)"
              fontSize={12}
            />
            <Tooltip content={<CustomTimeTooltip />} />
            <Bar 
              dataKey="totalTime" 
              fill="var(--healthcare-primary)"
              radius={[4, 4, 0, 0]}
            />
          </BarChart>
        </ResponsiveContainer>
      </AnalyticsCard>
    </div>
  );
};

export default CaseAnalytics;
