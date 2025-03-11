import React from 'react';
import { Icon } from '@iconify/react';
import {
  ResponsiveContainer,
  LineChart,
  Line,
  XAxis,
  YAxis,
  Tooltip as RechartsTooltip,
  CartesianGrid
} from 'recharts';
import { useDarkMode, HEALTHCARE_COLORS } from '@/hooks/useDarkMode';
import Modal from '@/Components/Common/Modal';

const DrillDownModal = ({ isOpen, onClose, metric, data }) => {
  const [isDarkMode] = useDarkMode();
  const colors = HEALTHCARE_COLORS[isDarkMode ? 'dark' : 'light'];

  const metricTitles = {
    ontime: 'On Time Starts',
    turnover: 'Average Turnover',
    accuracy: 'Case Length Accuracy',
    cases: 'Performed Cases',
    cancellations: 'DoS Cancellations',
    utilization: 'Block Utilization',
    primetime: 'Primetime Utilization'
  };

  const chartData = data ? Array(24).fill(0).map((_, i) => ({
    date: `${i}:00`,
    value: data.value + (Math.random() - 0.5) * 20
  })) : [];

  const modalContent = (
    <>
      {/* Content */}
      <div className="space-y-6">
        {/* Summary Stats */}
        <div className="grid grid-cols-3 gap-4">
          {[
            { title: 'Current', icon: 'heroicons:clock', value: data?.value || 0, label: 'vs. last period' },
            { title: 'Average', icon: 'heroicons:chart-bar', value: data?.average || 0, label: 'last 30 days' },
            { title: 'Target', icon: 'heroicons:flag', value: data?.target || 0, label: 'benchmark' }
          ].map((stat, index) => (
            <div 
              key={stat.title}
              className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg border border-healthcare-border dark:border-healthcare-border-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-all duration-300"
            >
              <div className="flex items-center justify-between">
                <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300">
                  {stat.title}
                </div>
                <Icon 
                  icon={stat.icon} 
                  className="w-5 h-5 text-healthcare-info dark:text-healthcare-info-dark transition-colors duration-300" 
                />
              </div>
              <div className="mt-2">
                <div className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                  {stat.value}%
                </div>
                <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300">
                  {stat.label}
                </div>
              </div>
            </div>
          ))}
        </div>

        {/* Trend Chart */}
        <div className="h-64">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={chartData}>
              <defs>
                <linearGradient id="valueGradient" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor={colors.info} stopOpacity={0.3}/>
                  <stop offset="100%" stopColor={colors.info} stopOpacity={0.1}/>
                </linearGradient>
              </defs>
              <CartesianGrid 
                strokeDasharray="3 3" 
                stroke={colors.border}
                opacity={0.5}
              />
              <XAxis 
                dataKey="date" 
                tick={{ fill: colors.text.secondary, fontSize: 12 }}
                stroke={colors.border}
              />
              <YAxis 
                tick={{ fill: colors.text.secondary, fontSize: 12 }}
                tickFormatter={(value) => `${value}%`}
                stroke={colors.border}
              />
              <RechartsTooltip 
                content={({ active, payload, label }) => {
                  if (active && payload && payload.length) {
                    return (
                      <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-3 shadow-lg rounded-lg border border-healthcare-border dark:border-healthcare-border-dark transition-all duration-300">
                        <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                          {label}
                        </p>
                        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                          Value: <span className="font-medium">{payload[0].value}%</span>
                        </p>
                      </div>
                    );
                  }
                  return null;
                }}
              />
              <Line 
                type="monotone" 
                dataKey="value" 
                stroke={colors.info}
                strokeWidth={2}
                dot={{ r: 3, fill: colors.info }}
                activeDot={{ r: 5, fill: colors.info }}
                fill="url(#valueGradient)"
              />
            </LineChart>
          </ResponsiveContainer>
        </div>

        {/* Service Breakdown */}
        <div>
          <h3 className="text-lg font-medium mb-4 text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
            Service Breakdown
          </h3>
          <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg overflow-hidden border border-healthcare-border dark:border-healthcare-border-dark transition-all duration-300">
            <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
              <thead>
                <tr className="bg-healthcare-background dark:bg-healthcare-background-dark transition-colors duration-300">
                  <th className="px-4 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider transition-colors duration-300">
                    Service
                  </th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider transition-colors duration-300">
                    Value
                  </th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider transition-colors duration-300">
                    Change
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                {['Orthopedics', 'Cardiology', 'General'].map((service, i) => (
                  <tr 
                    key={service} 
                    className="hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark transition-colors duration-150"
                  >
                    <td className="px-4 py-3 whitespace-nowrap text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                      {service}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-sm text-right">
                      <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                        {(data?.value || 0) + (Math.random() - 0.5) * 10}%
                      </span>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-sm text-right">
                      <div className="flex items-center justify-end space-x-1">
                        <Icon 
                          icon={i % 2 === 0 ? 'heroicons:arrow-up' : 'heroicons:arrow-down'} 
                          className={`w-4 h-4 ${
                            i % 2 === 0 
                              ? 'text-healthcare-success dark:text-healthcare-success-dark' 
                              : 'text-healthcare-critical dark:text-healthcare-critical-dark'
                          } transition-colors duration-300`} 
                        />
                        <span className={`
                          ${i % 2 === 0 
                            ? 'text-healthcare-success dark:text-healthcare-success-dark' 
                            : 'text-healthcare-critical dark:text-healthcare-critical-dark'
                          } transition-colors duration-300
                        `}>
                          {Math.round(Math.random() * 5)}%
                        </span>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </>
  );

  return (
    <Modal
      open={isOpen}
      onClose={onClose}
      title={`${metricTitles[metric]} Details`}
      maxWidth="4xl"
    >
      {modalContent}
    </Modal>
  );
};

export default DrillDownModal;
