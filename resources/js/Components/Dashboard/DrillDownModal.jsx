import React, { Fragment } from 'react';
import { Dialog, Transition } from '@headlessui/react';
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

const DrillDownModal = ({ isOpen, onClose, metric, data }) => {
  // Map metric names to display titles
  const metricTitles = {
    ontime: 'On Time Starts',
    turnover: 'Average Turnover',
    accuracy: 'Case Length Accuracy',
    cases: 'Performed Cases',
    cancellations: 'DoS Cancellations',
    utilization: 'Block Utilization',
    primetime: 'Primetime Utilization'
  };

  // Transform data for chart display
  const chartData = data ? Array(24).fill(0).map((_, i) => ({
    date: `${i}:00`,
    value: data.value + (Math.random() - 0.5) * 20 // Mock data variation
  })) : [];

  return (
    <Transition appear show={isOpen} as={Fragment}>
      <Dialog as="div" className="relative z-10" onClose={onClose}>
        <Transition.Child
          as={Fragment}
          enter="ease-out duration-300"
          enterFrom="opacity-0"
          enterTo="opacity-100"
          leave="ease-in duration-200"
          leaveFrom="opacity-100"
          leaveTo="opacity-0"
        >
          <div className="fixed inset-0 bg-black bg-opacity-25" />
        </Transition.Child>

        <div className="fixed inset-0 overflow-y-auto">
          <div className="flex min-h-full items-center justify-center p-4 text-center">
            <Transition.Child
              as={Fragment}
              enter="ease-out duration-300"
              enterFrom="opacity-0 scale-95"
              enterTo="opacity-100 scale-100"
              leave="ease-in duration-200"
              leaveFrom="opacity-100 scale-100"
              leaveTo="opacity-0 scale-95"
            >
              <Dialog.Panel className="w-full max-w-4xl transform overflow-hidden rounded-2xl bg-white p-6 text-left align-middle shadow-xl transition-all">
                {/* Header */}
                <div className="flex items-center justify-between mb-6">
                  <div className="flex items-center space-x-2">
                    <Dialog.Title className="text-xl font-semibold text-gray-900">
                      {metricTitles[metric]} Details
                    </Dialog.Title>
                    <span className="text-sm text-gray-500">Last 24 Hours</span>
                  </div>
                  <button
                    className="text-gray-400 hover:text-gray-500 focus:outline-none"
                    onClick={onClose}
                  >
                    <Icon icon="heroicons:x-mark" className="w-5 h-5" />
                  </button>
                </div>

                {/* Content */}
                <div className="space-y-6">
                  {/* Summary Stats */}
                  <div className="grid grid-cols-3 gap-4">
                    <div className="bg-white p-4 rounded-lg border border-gray-100 hover:border-indigo-100 transition-colors duration-200">
                      <div className="flex items-center justify-between">
                        <div className="text-sm text-gray-600">Current</div>
                        <Icon icon="heroicons:clock" className="w-5 h-5 text-indigo-500" />
                      </div>
                      <div className="mt-2">
                        <div className="text-2xl font-bold">{data?.value || 0}%</div>
                        <div className="text-xs text-gray-500">vs. last period</div>
                      </div>
                    </div>
                    <div className="bg-white p-4 rounded-lg border border-gray-100 hover:border-indigo-100 transition-colors duration-200">
                      <div className="flex items-center justify-between">
                        <div className="text-sm text-gray-600">Average</div>
                        <Icon icon="heroicons:chart-bar" className="w-5 h-5 text-indigo-500" />
                      </div>
                      <div className="mt-2">
                        <div className="text-2xl font-bold">{data?.average || 0}%</div>
                        <div className="text-xs text-gray-500">last 30 days</div>
                      </div>
                    </div>
                    <div className="bg-white p-4 rounded-lg border border-gray-100 hover:border-indigo-100 transition-colors duration-200">
                      <div className="flex items-center justify-between">
                        <div className="text-sm text-gray-600">Target</div>
                        <Icon icon="heroicons:flag" className="w-5 h-5 text-indigo-500" />
                      </div>
                      <div className="mt-2">
                        <div className="text-2xl font-bold">{data?.target || 0}%</div>
                        <div className="text-xs text-gray-500">benchmark</div>
                      </div>
                    </div>
                  </div>

                  {/* Trend Chart */}
                  <div className="h-64">
                    <ResponsiveContainer width="100%" height="100%">
                      <LineChart data={chartData}>
                        <defs>
                          <linearGradient id="valueGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="#4F46E5" stopOpacity={0.3}/>
                            <stop offset="100%" stopColor="#4F46E5" stopOpacity={0.1}/>
                          </linearGradient>
                        </defs>
                        <CartesianGrid strokeDasharray="3 3" stroke="#E5E7EB" />
                        <XAxis 
                          dataKey="date" 
                          tick={{ fill: '#6B7280', fontSize: 12 }}
                        />
                        <YAxis 
                          tick={{ fill: '#6B7280', fontSize: 12 }}
                          tickFormatter={(value) => `${value}%`}
                        />
                        <RechartsTooltip 
                          content={({ active, payload, label }) => {
                            if (active && payload && payload.length) {
                              return (
                                <div className="bg-white p-3 shadow-lg rounded-lg border border-gray-200">
                                  <p className="font-medium text-gray-900">{label}</p>
                                  <p className="text-sm text-gray-600 mt-1">
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
                          stroke="#4F46E5"
                          strokeWidth={2}
                          dot={{ r: 3, fill: '#4F46E5' }}
                          activeDot={{ r: 5, fill: '#4F46E5' }}
                          fill="url(#valueGradient)"
                        />
                      </LineChart>
                    </ResponsiveContainer>
                  </div>

                  {/* Service Breakdown */}
                  <div>
                    <h3 className="text-lg font-medium mb-4">Service Breakdown</h3>
                    <div className="bg-white rounded-lg overflow-hidden border border-gray-200">
                      <table className="min-w-full divide-y divide-gray-200">
                        <thead>
                          <tr>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider bg-gray-50">Service</th>
                            <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider bg-gray-50">Value</th>
                            <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider bg-gray-50">Change</th>
                          </tr>
                        </thead>
                        <tbody>
                          {['Orthopedics', 'Cardiology', 'General'].map((service, i) => (
                            <tr key={service} className="hover:bg-gray-50 transition-colors duration-150">
                              <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-900">{service}</td>
                              <td className="px-4 py-3 whitespace-nowrap text-sm text-right">
                                <span className="font-medium">
                                  {(data?.value || 0) + (Math.random() - 0.5) * 10}%
                                </span>
                              </td>
                              <td className="px-4 py-3 whitespace-nowrap text-sm text-right">
                                <div className="flex items-center justify-end space-x-1">
                                  <Icon 
                                    icon={i % 2 === 0 ? 'heroicons:arrow-up' : 'heroicons:arrow-down'} 
                                    className={`w-4 h-4 ${i % 2 === 0 ? 'text-green-500' : 'text-red-500'}`} 
                                  />
                                  <span className={i % 2 === 0 ? 'text-green-600' : 'text-red-600'}>
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

                {/* Footer */}
                <div className="mt-6 flex justify-end">
                  <button
                    type="button"
                    className="inline-flex justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                    onClick={onClose}
                  >
                    Close
                  </button>
                </div>
              </Dialog.Panel>
            </Transition.Child>
          </div>
        </div>
      </Dialog>
    </Transition>
  );
};

export default DrillDownModal;
