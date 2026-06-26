import React from 'react';
import PropTypes from 'prop-types';
import Panel from '@/Components/ui/Panel';
import { Icon } from '@iconify/react';

/**
 * Process Variants View Component
 * Displays different process variants and their frequencies
 */
const VariantsView = ({ data }) => {
  // Sample variants data (in a real implementation, this would come from the data prop)
  const variants = [
    {
      id: 'variant-1',
      name: 'Primary Path',
      frequency: 68,
      steps: 5,
      avgDuration: '3.2 days',
      color: 'green'
    },
    {
      id: 'variant-2',
      name: 'Secondary Path',
      frequency: 22,
      steps: 7,
      avgDuration: '4.5 days',
      color: 'blue'
    },
    {
      id: 'variant-3',
      name: 'Complex Path',
      frequency: 10,
      steps: 9,
      avgDuration: '6.8 days',
      color: 'orange'
    }
  ];

  return (
    <div className="space-y-6">
      <Panel title="Process Variants Analysis" isSubpanel={false}>
        <div className="p-4">
          <div className="mb-6">
            <h3 className="text-lg font-medium mb-2">Process Variants Overview</h3>
            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              This analysis identifies the different paths patients take through the process.
              Understanding variants helps identify opportunities for standardization and optimization.
            </p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg shadow">
              <div className="flex items-center justify-between mb-2">
                <h4 className="font-medium">Total Variants</h4>
                <Icon icon="carbon:flow" className="h-5 w-5 text-healthcare-info dark:text-healthcare-info-dark" />
              </div>
              <p className="text-2xl font-semibold">{variants.length}</p>
            </div>
            <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg shadow">
              <div className="flex items-center justify-between mb-2">
                <h4 className="font-medium">Primary Variant %</h4>
                <Icon icon="carbon:chart-line" className="h-5 w-5 text-healthcare-success dark:text-healthcare-success-dark" />
              </div>
              <p className="text-2xl font-semibold">{variants[0].frequency}%</p>
            </div>
            <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg shadow">
              <div className="flex items-center justify-between mb-2">
                <h4 className="font-medium">Variant Complexity</h4>
                <Icon icon="carbon:chart-evaluation" className="h-5 w-5 text-healthcare-warning dark:text-healthcare-warning-dark" />
              </div>
              <p className="text-2xl font-semibold">Medium</p>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
              <thead className="bg-healthcare-background dark:bg-healthcare-background-dark">
                <tr>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                    Variant
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                    Frequency
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                    Steps
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                    Avg. Duration
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody className="bg-healthcare-surface dark:bg-healthcare-surface-dark divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                {variants.map((variant) => (
                  <tr key={variant.id}>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="flex items-center">
                        <div className={`h-3 w-3 rounded-full bg-${variant.color}-500 mr-2`}></div>
                        <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{variant.name}</div>
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{variant.frequency}%</div>
                      <div className="w-full bg-healthcare-border dark:bg-healthcare-border-dark rounded-full h-2.5 mt-1">
                        <div 
                          className={`bg-${variant.color}-500 h-2.5 rounded-full`} 
                          style={{ width: `${variant.frequency}%` }}
                        ></div>
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {variant.steps}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {variant.avgDuration}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                      <button className="text-healthcare-primary hover:text-healthcare-primary dark:text-healthcare-primary-dark dark:hover:text-healthcare-primary-dark mr-3">
                        View
                      </button>
                      <button className="text-healthcare-primary hover:text-healthcare-primary dark:text-healthcare-primary-dark dark:hover:text-healthcare-primary-dark">
                        Compare
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </Panel>

      <Panel title="Variant Visualization" isSubpanel={false}>
        <div className="p-4 flex justify-center items-center h-64 bg-healthcare-background dark:bg-healthcare-background-dark rounded">
          <div className="text-center">
            <Icon icon="carbon:chart-network" className="h-12 w-12 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mx-auto mb-4" />
            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Select a variant from the table above to visualize its path
            </p>
          </div>
        </div>
      </Panel>
    </div>
  );
};

VariantsView.propTypes = {
  data: PropTypes.object.isRequired
};

export default VariantsView;
