import React from 'react';
import PropTypes from 'prop-types';
import Panel from '@/Components/ui/Panel';
import { Icon } from '@iconify/react';

/**
 * Performance View Component
 * Displays performance metrics and KPIs for patient flow
 */
const PerformanceView = ({ data }) => {
  // Sample performance data (in a real implementation, this would come from the data prop)
  const performanceMetrics = [
    {
      id: 'throughput',
      name: 'Patient Throughput',
      value: '142',
      unit: 'patients/day',
      trend: 'up',
      change: '+8%',
      icon: 'carbon:user-multiple'
    },
    {
      id: 'cycle-time',
      name: 'Average Cycle Time',
      value: '3.2',
      unit: 'days',
      trend: 'down',
      change: '-12%',
      icon: 'carbon:time'
    },
    {
      id: 'wait-time',
      name: 'Average Wait Time',
      value: '47',
      unit: 'minutes',
      trend: 'down',
      change: '-15%',
      icon: 'carbon:hourglass'
    },
    {
      id: 'utilization',
      name: 'Resource Utilization',
      value: '78',
      unit: '%',
      trend: 'up',
      change: '+5%',
      icon: 'carbon:chart-evaluation'
    }
  ];

  const departments = [
    {
      name: 'Emergency',
      performance: 92,
      color: 'green'
    },
    {
      name: 'Surgery',
      performance: 87,
      color: 'blue'
    },
    {
      name: 'Medical/Surgical',
      performance: 76,
      color: 'yellow'
    },
    {
      name: 'ICU',
      performance: 95,
      color: 'green'
    },
    {
      name: 'Cardiology',
      performance: 82,
      color: 'blue'
    }
  ];

  return (
    <div className="space-y-6">
      <Panel title="Performance Overview" isSubpanel={false}>
        <div className="p-4">
          <div className="mb-6">
            <h3 className="text-lg font-medium mb-2">Performance Metrics</h3>
            <p className="text-gray-600 dark:text-gray-300">
              Key performance indicators for the selected patient flow process.
              Metrics are compared to the previous time period.
            </p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            {performanceMetrics.map((metric) => (
              <div key={metric.id} className="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
                <div className="flex items-center justify-between mb-2">
                  <h4 className="font-medium text-gray-700 dark:text-gray-300">{metric.name}</h4>
                  <Icon icon={metric.icon} className="h-5 w-5 text-blue-500" />
                </div>
                <div className="flex items-end">
                  <p className="text-2xl font-bold mr-2">{metric.value}</p>
                  <p className="text-sm text-gray-500 dark:text-gray-400 mb-1">{metric.unit}</p>
                </div>
                <div className={`flex items-center mt-2 ${
                  metric.trend === 'up' 
                    ? 'text-green-500' 
                    : 'text-red-500'
                }`}>
                  <Icon 
                    icon={metric.trend === 'up' ? 'carbon:arrow-up' : 'carbon:arrow-down'} 
                    className="h-4 w-4 mr-1" 
                  />
                  <span className="text-sm">{metric.change}</span>
                </div>
              </div>
            ))}
          </div>
        </div>
      </Panel>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <Panel title="Departmental Performance" isSubpanel={false}>
          <div className="p-4">
            <div className="space-y-4">
              {departments.map((dept) => (
                <div key={dept.name} className="bg-white dark:bg-gray-800 p-3 rounded-lg shadow">
                  <div className="flex justify-between items-center mb-2">
                    <h4 className="font-medium">{dept.name}</h4>
                    <span className={`px-2 py-1 rounded text-xs font-medium ${
                      dept.performance >= 90 ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' :
                      dept.performance >= 80 ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' :
                      'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'
                    }`}>
                      {dept.performance}/100
                    </span>
                  </div>
                  <div className="w-full bg-gray-200 dark:bg-gray-600 rounded-full h-2.5">
                    <div 
                      className={`bg-${dept.color}-500 h-2.5 rounded-full`} 
                      style={{ width: `${dept.performance}%` }}
                    ></div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </Panel>

        <Panel title="Performance Trends" isSubpanel={false}>
          <div className="p-4 flex justify-center items-center h-64 bg-gray-50 dark:bg-gray-700 rounded">
            <div className="text-center">
              <Icon icon="carbon:chart-line" className="h-12 w-12 text-gray-400 dark:text-gray-500 mx-auto mb-4" />
              <p className="text-gray-500 dark:text-gray-400">
                Performance trend visualization will be displayed here
              </p>
            </div>
          </div>
        </Panel>
      </div>

      <Panel title="Improvement Opportunities" isSubpanel={false}>
        <div className="p-4">
          <div className="bg-blue-50 dark:bg-blue-900 border-l-4 border-blue-500 p-4 mb-4">
            <div className="flex">
              <div className="flex-shrink-0">
                <Icon icon="carbon:idea" className="h-5 w-5 text-blue-500" />
              </div>
              <div className="ml-3">
                <h3 className="text-sm font-medium text-blue-800 dark:text-blue-200">
                  Reduce Wait Times in Medical/Surgical
                </h3>
                <div className="mt-2 text-sm text-blue-700 dark:text-blue-300">
                  <p>
                    Analysis suggests that adding one additional nurse during peak hours could reduce wait times by up to 25%.
                  </p>
                </div>
              </div>
            </div>
          </div>
          
          <div className="bg-blue-50 dark:bg-blue-900 border-l-4 border-blue-500 p-4">
            <div className="flex">
              <div className="flex-shrink-0">
                <Icon icon="carbon:idea" className="h-5 w-5 text-blue-500" />
              </div>
              <div className="ml-3">
                <h3 className="text-sm font-medium text-blue-800 dark:text-blue-200">
                  Optimize Discharge Process
                </h3>
                <div className="mt-2 text-sm text-blue-700 dark:text-blue-300">
                  <p>
                    Standardizing the discharge documentation process could improve cycle time by approximately 18%.
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </Panel>
    </div>
  );
};

PerformanceView.propTypes = {
  data: PropTypes.object.isRequired
};

export default PerformanceView;
