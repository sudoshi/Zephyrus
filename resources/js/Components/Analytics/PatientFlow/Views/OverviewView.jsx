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
                  <h3 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{metric.title}</h3>
                  <div className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{metric.value}</div>
                  {metric.change !== 0 && (
                    <div className={`flex items-center text-xs ${metric.change > 0 ? 'text-healthcare-success dark:text-healthcare-success-dark' : 'text-healthcare-critical dark:text-healthcare-critical-dark'}`}>
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
                  className="flex items-center p-4 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg"
                >
                  <div className={`p-2 rounded-full bg-${metric.color}-100 dark:bg-${metric.color}-800 text-${metric.color}-600 dark:text-${metric.color}-300 mr-3`}>
                    <Icon icon={metric.icon} className="w-5 h-5" />
                  </div>
                  <div>
                    <h3 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{metric.title}</h3>
                    <div className="text-xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{metric.value}</div>
                  </div>
                </motion.div>
              ))}
            </div>

            <div className="mt-6">
              <h3 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-3">Process Timeline</h3>
              <div className="relative pt-6">
                <div className="absolute top-0 left-6 h-full w-0.5 bg-healthcare-info/20 dark:bg-healthcare-info-dark/30"></div>
                
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
                          ? 'bg-healthcare-info dark:bg-healthcare-info-dark text-white'
                          : index === (data.processTimeline.length - 1)
                            ? 'bg-healthcare-success dark:bg-healthcare-success-dark text-white'
                            : 'bg-healthcare-border dark:bg-healthcare-border-dark text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
                      }`}>
                        {index + 1}
                      </div>
                    </div>
                    <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-3 rounded-lg border border-healthcare-border dark:border-healthcare-border-dark">
                      <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{step.activity}</h4>
                      <div className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark flex items-center">
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
                  <div className="text-center py-6 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
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
                  className="p-3 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg"
                >
                  <div className="flex items-start">
                    <div className={`flex-shrink-0 p-2 rounded-full mr-3 ${
                      insight.type === 'positive'
                        ? 'bg-healthcare-success/10 dark:bg-healthcare-success-dark/20 text-healthcare-success dark:text-healthcare-success-dark'
                        : insight.type === 'negative'
                          ? 'bg-healthcare-critical/10 dark:bg-healthcare-critical-dark/20 text-healthcare-critical dark:text-healthcare-critical-dark'
                          : 'bg-healthcare-info/10 dark:bg-healthcare-info-dark/20 text-healthcare-info dark:text-healthcare-info-dark'
                    }`}>
                      <Icon 
                        icon={
                          insight.type === 'positive' 
                            ? 'carbon:checkmark-filled' 
                            : insight.type === 'negative'
                              ? 'carbon:warning-alt'
                              : 'carbon:information'
                        } 
                        className={insight.type === 'positive' || insight.type === 'negative' ? 'w-5 h-5' : 'w-10 h-10'}
                      />
                    </div>
                    <div>
                      <h4 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{insight.title}</h4>
                      <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{insight.description}</p>
                      {insight.value && (
                        <div className="mt-2 text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          {insight.label}: <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{insight.value}</span>
                        </div>
                      )}
                    </div>
                  </div>
                </motion.div>
              ))}
              
              {(!insights || insights.length === 0) && (
                <div className="text-center py-6 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
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
