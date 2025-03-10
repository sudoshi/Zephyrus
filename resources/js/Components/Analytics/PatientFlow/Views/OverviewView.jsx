import React from 'react';
import PropTypes from 'prop-types';
import Panel from '@/Components/ui/Panel';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';

const OverviewView = ({ data, derivedMetrics }) => {
  // Destructure data for easier access
  const { stats = {}, insights = [] } = data || {};
  
  // Define key metrics to display
  const keyMetrics = [
    {
      title: 'Total Cases',
      value: stats.cases?.count || 0,
      change: stats.cases?.change || 0,
      icon: 'carbon:user-multiple',
      color: 'blue'
    },
    {
      title: 'Avg. Process Time',
      value: stats.time?.avgProcessTime || '0 hrs',
      change: stats.time?.avgProcessTimeChange || 0,
      icon: 'carbon:time',
      color: 'purple'
    },
    {
      title: 'Completion Rate',
      value: `${stats.cases?.completionRate || 0}%`,
      change: stats.cases?.completionRateChange || 0,
      icon: 'carbon:checkmark-filled',
      color: 'green'
    },
    {
      title: 'Avg. Wait Time',
      value: stats.time?.avgWaitTime || '0 hrs',
      change: stats.time?.avgWaitTimeChange || 0,
      icon: 'carbon:hourglass',
      color: 'yellow'
    }
  ];

  // Define process metrics to display
  const processMetrics = [
    {
      title: 'Process Variants',
      value: derivedMetrics?.variantCount || stats.variants?.count || 0,
      icon: 'carbon:flow',
      color: 'indigo'
    },
    {
      title: 'Activities',
      value: derivedMetrics?.activityCount || stats.activities?.count || 0,
      icon: 'carbon:activity',
      color: 'teal'
    },
    {
      title: 'Bottlenecks',
      value: derivedMetrics?.bottleneckCount || stats.bottlenecks?.count || 0,
      icon: 'carbon:warning-alt',
      color: 'red'
    },
    {
      title: 'Optimization Potential',
      value: `${derivedMetrics?.optimizationPotential || stats.optimization?.potential || 0}%`,
      icon: 'carbon:chart-maximum',
      color: 'emerald'
    }
  ];

  return (
    <div className="space-y-6">
      {/* Key Metrics */}
      <Panel title="Key Metrics" className="overflow-hidden">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          {keyMetrics.map((metric, index) => (
            <motion.div
              key={index}
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.3, delay: index * 0.1 }}
              className={`bg-${metric.color}-50 dark:bg-${metric.color}-900/30 p-4 rounded-lg`}
            >
              <div className="flex items-center">
                <div className={`p-2 rounded-full bg-${metric.color}-100 dark:bg-${metric.color}-800 text-${metric.color}-600 dark:text-${metric.color}-300 mr-3`}>
                  <Icon icon={metric.icon} className="w-6 h-6" />
                </div>
                <div>
                  <h3 className="text-sm font-medium text-gray-700 dark:text-gray-300">{metric.title}</h3>
                  <div className="text-2xl font-bold text-gray-900 dark:text-gray-100">{metric.value}</div>
                  {metric.change !== 0 && (
                    <div className={`flex items-center text-xs ${metric.change > 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}>
                      <Icon 
                        icon={metric.change > 0 ? 'carbon:arrow-up' : 'carbon:arrow-down'} 
                        className="w-3 h-3 mr-1" 
                      />
                      <span>{Math.abs(metric.change)}% from previous period</span>
                    </div>
                  )}
                </div>
              </div>
            </motion.div>
          ))}
        </div>
      </Panel>

      {/* Process Overview */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2">
          <Panel title="Process Overview" className="h-full">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              {processMetrics.map((metric, index) => (
                <motion.div
                  key={index}
                  initial={{ opacity: 0, y: 20 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.3, delay: index * 0.1 + 0.4 }}
                  className="flex items-center p-4 bg-gray-50 dark:bg-gray-800 rounded-lg"
                >
                  <div className={`p-2 rounded-full bg-${metric.color}-100 dark:bg-${metric.color}-800 text-${metric.color}-600 dark:text-${metric.color}-300 mr-3`}>
                    <Icon icon={metric.icon} className="w-5 h-5" />
                  </div>
                  <div>
                    <h3 className="text-sm font-medium text-gray-700 dark:text-gray-300">{metric.title}</h3>
                    <div className="text-xl font-bold text-gray-900 dark:text-gray-100">{metric.value}</div>
                  </div>
                </motion.div>
              ))}
            </div>

            <div className="mt-6">
              <h3 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Process Timeline</h3>
              <div className="relative pt-6">
                <div className="absolute top-0 left-6 h-full w-0.5 bg-blue-200 dark:bg-blue-800"></div>
                
                {(data?.processTimeline || []).map((step, index) => (
                  <motion.div 
                    key={index}
                    initial={{ opacity: 0, x: -20 }}
                    animate={{ opacity: 1, x: 0 }}
                    transition={{ duration: 0.3, delay: index * 0.1 + 0.8 }}
                    className="relative mb-6 pl-12"
                  >
                    <div className="absolute left-0 top-0 w-12 flex items-center justify-center">
                      <div className={`w-6 h-6 rounded-full flex items-center justify-center ${
                        index === 0 
                          ? 'bg-blue-500 text-white' 
                          : index === (data.processTimeline.length - 1)
                            ? 'bg-green-500 text-white'
                            : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300'
                      }`}>
                        {index + 1}
                      </div>
                    </div>
                    <div className="bg-white dark:bg-gray-800 p-3 rounded-lg border border-gray-200 dark:border-gray-700">
                      <h4 className="font-medium text-gray-900 dark:text-gray-100">{step.activity}</h4>
                      <div className="mt-1 text-sm text-gray-500 dark:text-gray-400 flex items-center">
                        <Icon icon="carbon:time" className="w-4 h-4 mr-1" />
                        <span>Avg: {step.avgDuration}</span>
                        {step.waitTime && (
                          <>
                            <span className="mx-2">•</span>
                            <Icon icon="carbon:hourglass" className="w-4 h-4 mr-1" />
                            <span>Wait: {step.waitTime}</span>
                          </>
                        )}
                      </div>
                    </div>
                  </motion.div>
                ))}
                
                {(!data?.processTimeline || data.processTimeline.length === 0) && (
                  <div className="text-center py-6 text-gray-500 dark:text-gray-400">
                    No timeline data available
                  </div>
                )}
              </div>
            </div>
          </Panel>
        </div>

        <div>
          <Panel title="Key Insights" className="h-full">
            <div className="space-y-4">
              {(insights || []).map((insight, index) => (
                <motion.div
                  key={index}
                  initial={{ opacity: 0, y: 20 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.3, delay: index * 0.1 + 0.4 }}
                  className="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg"
                >
                  <div className="flex items-start">
                    <div className={`flex-shrink-0 p-2 rounded-full mr-3 ${
                      insight.type === 'positive' 
                        ? 'bg-green-100 dark:bg-green-900 text-green-600 dark:text-green-300' 
                        : insight.type === 'negative'
                          ? 'bg-red-100 dark:bg-red-900 text-red-600 dark:text-red-300' 
                          : 'bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-300'
                    }`}>
                      <Icon 
                        icon={
                          insight.type === 'positive' 
                            ? 'carbon:checkmark-filled' 
                            : insight.type === 'negative'
                              ? 'carbon:warning-alt'
                              : 'carbon:information'
                        } 
                        className="w-5 h-5" 
                      />
                    </div>
                    <div>
                      <h4 className="text-sm font-medium text-gray-900 dark:text-gray-100">{insight.title}</h4>
                      <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">{insight.description}</p>
                      {insight.value && (
                        <div className="mt-2 text-xs font-medium text-gray-500 dark:text-gray-400">
                          {insight.label}: <span className="text-gray-900 dark:text-gray-100">{insight.value}</span>
                        </div>
                      )}
                    </div>
                  </div>
                </motion.div>
              ))}
              
              {(!insights || insights.length === 0) && (
                <div className="text-center py-6 text-gray-500 dark:text-gray-400">
                  No insights available
                </div>
              )}
            </div>
          </Panel>
        </div>
      </div>
    </div>
  );
};

OverviewView.propTypes = {
  data: PropTypes.object,
  derivedMetrics: PropTypes.object
};

export default OverviewView;
