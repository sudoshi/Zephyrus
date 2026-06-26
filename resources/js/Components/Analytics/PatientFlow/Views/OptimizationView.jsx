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
            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Based on analysis of your patient flow data, the following optimization scenarios 
              could improve efficiency and patient experience.
            </p>
          </div>

          <div className="space-y-4">
            {optimizationScenarios.map((scenario) => (
              <div key={scenario.id} className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg shadow">
                <div className="flex justify-between items-start">
                  <div>
                    <h4 className="font-medium text-lg">{scenario.name}</h4>
                    <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">{scenario.description}</p>
                  </div>
                  <div className="flex space-x-2">
                    <span className="px-2 py-1 bg-healthcare-info/10 text-healthcare-info dark:bg-healthcare-info-dark/20 dark:text-healthcare-info-dark rounded text-xs">
                      {scenario.difficulty}
                    </span>
                    <span className="px-2 py-1 bg-healthcare-purple/10 text-healthcare-purple dark:bg-healthcare-purple-dark/20 dark:text-healthcare-purple-dark rounded text-xs">
                      {scenario.timeframe}
                    </span>
                  </div>
                </div>
                
                <div className="grid grid-cols-4 gap-4 mt-4">
                  <div className="text-center">
                    <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Throughput</p>
                    <p className="text-lg font-semibold text-healthcare-success dark:text-healthcare-success-dark">{scenario.impact.throughput}</p>
                  </div>
                  <div className="text-center">
                    <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Wait Time</p>
                    <p className="text-lg font-semibold text-healthcare-success dark:text-healthcare-success-dark">{scenario.impact.waitTime}</p>
                  </div>
                  <div className="text-center">
                    <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Cycle Time</p>
                    <p className="text-lg font-semibold text-healthcare-success dark:text-healthcare-success-dark">{scenario.impact.cycleTime}</p>
                  </div>
                  <div className="text-center">
                    <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Cost</p>
                    <p className={`text-lg font-semibold ${
                      scenario.impact.cost.startsWith('-')
                        ? 'text-healthcare-success dark:text-healthcare-success-dark'
                        : 'text-healthcare-critical dark:text-healthcare-critical-dark'
                    }`}>
                      {scenario.impact.cost}
                    </p>
                  </div>
                </div>

                <div className="mt-4 flex justify-end">
                  <button className="px-3 py-1 bg-healthcare-primary dark:bg-healthcare-primary-dark text-white rounded-md text-sm hover:bg-healthcare-primary-hover dark:hover:bg-healthcare-primary-hover-dark transition-colors">
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
            <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
              <thead className="bg-healthcare-background dark:bg-healthcare-background-dark">
                <tr>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                    Metric
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                    Current
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                    Optimized
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                    Improvement
                  </th>
                </tr>
              </thead>
              <tbody className="bg-healthcare-surface dark:bg-healthcare-surface-dark divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                {comparisonMetrics.map((metric, index) => (
                  <tr key={index}>
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {metric.name}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {metric.current}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {metric.optimized}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-success dark:text-healthcare-success-dark">
                      {metric.improvement}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Panel>

        <Panel title="Optimization Simulation" isSubpanel={false}>
          <div className="p-4 flex justify-center items-center h-64 bg-healthcare-background dark:bg-healthcare-background-dark rounded">
            <div className="text-center">
              <Icon icon="carbon:chart-custom" className="h-12 w-12 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mx-auto mb-4" />
              <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                Select a scenario to run a simulation and visualize the optimized process
              </p>
            </div>
          </div>
        </Panel>
      </div>

      <Panel title="Implementation Roadmap" isSubpanel={false}>
        <div className="p-4">
          <div className="relative">
            <div className="absolute h-full w-0.5 bg-healthcare-info/20 dark:bg-healthcare-info-dark/30 left-6 top-0"></div>
            
            <div className="relative z-10 mb-6">
              <div className="flex items-start">
                <div className="flex items-center justify-center w-12 h-12 rounded-full bg-healthcare-info/10 dark:bg-healthcare-info-dark/20 text-healthcare-info dark:text-healthcare-info-dark">
                  <Icon icon="carbon:analytics" className="h-6 w-6" />
                </div>
                <div className="ml-4">
                  <h4 className="text-lg font-medium">Phase 1: Analysis & Planning</h4>
                  <p className="mt-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Detailed analysis of current process, stakeholder alignment, and resource planning.
                  </p>
                  <p className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Timeframe: 2-4 weeks</p>
                </div>
              </div>
            </div>
            
            <div className="relative z-10 mb-6">
              <div className="flex items-start">
                <div className="flex items-center justify-center w-12 h-12 rounded-full bg-healthcare-info/10 dark:bg-healthcare-info-dark/20 text-healthcare-info dark:text-healthcare-info-dark">
                  <Icon icon="carbon:development" className="h-6 w-6" />
                </div>
                <div className="ml-4">
                  <h4 className="text-lg font-medium">Phase 2: Pilot Implementation</h4>
                  <p className="mt-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Implement changes in a controlled environment, gather feedback, and refine approach.
                  </p>
                  <p className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Timeframe: 4-6 weeks</p>
                </div>
              </div>
            </div>
            
            <div className="relative z-10 mb-6">
              <div className="flex items-start">
                <div className="flex items-center justify-center w-12 h-12 rounded-full bg-healthcare-info/10 dark:bg-healthcare-info-dark/20 text-healthcare-info dark:text-healthcare-info-dark">
                  <Icon icon="carbon:deployment-policy" className="h-6 w-6" />
                </div>
                <div className="ml-4">
                  <h4 className="text-lg font-medium">Phase 3: Full Deployment</h4>
                  <p className="mt-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Organization-wide implementation with training and change management support.
                  </p>
                  <p className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Timeframe: 2-3 months</p>
                </div>
              </div>
            </div>
            
            <div className="relative z-10">
              <div className="flex items-start">
                <div className="flex items-center justify-center w-12 h-12 rounded-full bg-healthcare-info/10 dark:bg-healthcare-info-dark/20 text-healthcare-info dark:text-healthcare-info-dark">
                  <Icon icon="carbon:chart-evaluation" className="h-6 w-6" />
                </div>
                <div className="ml-4">
                  <h4 className="text-lg font-medium">Phase 4: Monitoring & Refinement</h4>
                  <p className="mt-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Continuous monitoring of KPIs, gathering feedback, and iterative improvements.
                  </p>
                  <p className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Timeframe: Ongoing</p>
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
