import React from 'react';
import PropTypes from 'prop-types';
import Panel from '@/Components/ui/Panel';
import { Icon } from '@iconify/react';

/**
 * Optimization View Component
 * Displays optimization recommendations and simulations for patient flow
 */
const OptimizationView = ({ data }) => {
  // Sample optimization scenarios (in a real implementation, this would come from the data prop)
  const optimizationScenarios = [
    {
      id: 'scenario-1',
      name: 'Resource Reallocation',
      description: 'Reallocate nursing staff from low-demand areas to high-demand areas during peak hours',
      impact: {
        throughput: '+12%',
        waitTime: '-18%',
        cycleTime: '-8%',
        cost: '-2%'
      },
      difficulty: 'Medium',
      timeframe: 'Short-term'
    },
    {
      id: 'scenario-2',
      name: 'Process Standardization',
      description: 'Implement standardized discharge protocols across all departments',
      impact: {
        throughput: '+5%',
        waitTime: '-10%',
        cycleTime: '-15%',
        cost: '-7%'
      },
      difficulty: 'Medium',
      timeframe: 'Medium-term'
    },
    {
      id: 'scenario-3',
      name: 'Technology Enhancement',
      description: 'Deploy mobile notification system for care team coordination',
      impact: {
        throughput: '+8%',
        waitTime: '-25%',
        cycleTime: '-12%',
        cost: '+3%'
      },
      difficulty: 'High',
      timeframe: 'Medium-term'
    }
  ];

  // Current vs. Optimized metrics
  const comparisonMetrics = [
    {
      name: 'Average Length of Stay',
      current: '4.2 days',
      optimized: '3.5 days',
      improvement: '16.7%'
    },
    {
      name: 'Patient Throughput',
      current: '142/day',
      optimized: '165/day',
      improvement: '16.2%'
    },
    {
      name: 'Resource Utilization',
      current: '78%',
      optimized: '85%',
      improvement: '9.0%'
    },
    {
      name: 'Average Wait Time',
      current: '47 min',
      optimized: '32 min',
      improvement: '31.9%'
    }
  ];

  return (
    <div className="space-y-6">
      <Panel title="Optimization Scenarios" isSubpanel={false}>
        <div className="p-4">
          <div className="mb-6">
            <h3 className="text-lg font-medium mb-2">Process Optimization Recommendations</h3>
            <p className="text-gray-600 dark:text-gray-300">
              Based on analysis of your patient flow data, the following optimization scenarios 
              could improve efficiency and patient experience.
            </p>
          </div>

          <div className="space-y-4">
            {optimizationScenarios.map((scenario) => (
              <div key={scenario.id} className="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
                <div className="flex justify-between items-start">
                  <div>
                    <h4 className="font-medium text-lg">{scenario.name}</h4>
                    <p className="text-gray-600 dark:text-gray-300 mt-1">{scenario.description}</p>
                  </div>
                  <div className="flex space-x-2">
                    <span className="px-2 py-1 bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200 rounded text-xs">
                      {scenario.difficulty}
                    </span>
                    <span className="px-2 py-1 bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200 rounded text-xs">
                      {scenario.timeframe}
                    </span>
                  </div>
                </div>
                
                <div className="grid grid-cols-4 gap-4 mt-4">
                  <div className="text-center">
                    <p className="text-sm text-gray-500 dark:text-gray-400">Throughput</p>
                    <p className="text-lg font-semibold text-green-600 dark:text-green-400">{scenario.impact.throughput}</p>
                  </div>
                  <div className="text-center">
                    <p className="text-sm text-gray-500 dark:text-gray-400">Wait Time</p>
                    <p className="text-lg font-semibold text-green-600 dark:text-green-400">{scenario.impact.waitTime}</p>
                  </div>
                  <div className="text-center">
                    <p className="text-sm text-gray-500 dark:text-gray-400">Cycle Time</p>
                    <p className="text-lg font-semibold text-green-600 dark:text-green-400">{scenario.impact.cycleTime}</p>
                  </div>
                  <div className="text-center">
                    <p className="text-sm text-gray-500 dark:text-gray-400">Cost</p>
                    <p className={`text-lg font-semibold ${
                      scenario.impact.cost.startsWith('-') 
                        ? 'text-green-600 dark:text-green-400' 
                        : 'text-red-600 dark:text-red-400'
                    }`}>
                      {scenario.impact.cost}
                    </p>
                  </div>
                </div>
                
                <div className="mt-4 flex justify-end">
                  <button className="px-3 py-1 bg-blue-600 text-white rounded-md text-sm hover:bg-blue-700 transition-colors">
                    Run Simulation
                  </button>
                </div>
              </div>
            ))}
          </div>
        </div>
      </Panel>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <Panel title="Current vs. Optimized Metrics" isSubpanel={false}>
          <div className="p-4">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
              <thead className="bg-gray-50 dark:bg-gray-700">
                <tr>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Metric
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Current
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Optimized
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Improvement
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                {comparisonMetrics.map((metric, index) => (
                  <tr key={index}>
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                      {metric.name}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                      {metric.current}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                      {metric.optimized}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-green-600 dark:text-green-400">
                      {metric.improvement}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Panel>

        <Panel title="Optimization Simulation" isSubpanel={false}>
          <div className="p-4 flex justify-center items-center h-64 bg-gray-50 dark:bg-gray-700 rounded">
            <div className="text-center">
              <Icon icon="carbon:chart-custom" className="h-12 w-12 text-gray-400 dark:text-gray-500 mx-auto mb-4" />
              <p className="text-gray-500 dark:text-gray-400">
                Select a scenario to run a simulation and visualize the optimized process
              </p>
            </div>
          </div>
        </Panel>
      </div>

      <Panel title="Implementation Roadmap" isSubpanel={false}>
        <div className="p-4">
          <div className="relative">
            <div className="absolute h-full w-0.5 bg-blue-200 dark:bg-blue-800 left-6 top-0"></div>
            
            <div className="relative z-10 mb-8">
              <div className="flex items-start">
                <div className="flex items-center justify-center w-12 h-12 rounded-full bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-300">
                  <Icon icon="carbon:analytics" className="h-6 w-6" />
                </div>
                <div className="ml-4">
                  <h4 className="text-lg font-medium">Phase 1: Analysis & Planning</h4>
                  <p className="mt-1 text-gray-600 dark:text-gray-300">
                    Detailed analysis of current process, stakeholder alignment, and resource planning.
                  </p>
                  <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">Timeframe: 2-4 weeks</p>
                </div>
              </div>
            </div>
            
            <div className="relative z-10 mb-8">
              <div className="flex items-start">
                <div className="flex items-center justify-center w-12 h-12 rounded-full bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-300">
                  <Icon icon="carbon:development" className="h-6 w-6" />
                </div>
                <div className="ml-4">
                  <h4 className="text-lg font-medium">Phase 2: Pilot Implementation</h4>
                  <p className="mt-1 text-gray-600 dark:text-gray-300">
                    Implement changes in a controlled environment, gather feedback, and refine approach.
                  </p>
                  <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">Timeframe: 4-6 weeks</p>
                </div>
              </div>
            </div>
            
            <div className="relative z-10 mb-8">
              <div className="flex items-start">
                <div className="flex items-center justify-center w-12 h-12 rounded-full bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-300">
                  <Icon icon="carbon:deployment-policy" className="h-6 w-6" />
                </div>
                <div className="ml-4">
                  <h4 className="text-lg font-medium">Phase 3: Full Deployment</h4>
                  <p className="mt-1 text-gray-600 dark:text-gray-300">
                    Organization-wide implementation with training and change management support.
                  </p>
                  <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">Timeframe: 2-3 months</p>
                </div>
              </div>
            </div>
            
            <div className="relative z-10">
              <div className="flex items-start">
                <div className="flex items-center justify-center w-12 h-12 rounded-full bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-300">
                  <Icon icon="carbon:chart-evaluation" className="h-6 w-6" />
                </div>
                <div className="ml-4">
                  <h4 className="text-lg font-medium">Phase 4: Monitoring & Refinement</h4>
                  <p className="mt-1 text-gray-600 dark:text-gray-300">
                    Continuous monitoring of KPIs, gathering feedback, and iterative improvements.
                  </p>
                  <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">Timeframe: Ongoing</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </Panel>
    </div>
  );
};

OptimizationView.propTypes = {
  data: PropTypes.object.isRequired
};

export default OptimizationView;
