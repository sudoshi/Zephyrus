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
            <p className="text-gray-600 dark:text-gray-300">
              This analysis identifies the different paths patients take through the process.
              Understanding variants helps identify opportunities for standardization and optimization.
            </p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div className="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
              <div className="flex items-center justify-between mb-2">
                <h4 className="font-medium">Total Variants</h4>
                <Icon icon="carbon:flow" className="h-5 w-5 text-blue-500" />
              </div>
              <p className="text-2xl font-bold">{variants.length}</p>
            </div>
            <div className="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
              <div className="flex items-center justify-between mb-2">
                <h4 className="font-medium">Primary Variant %</h4>
                <Icon icon="carbon:chart-line" className="h-5 w-5 text-green-500" />
              </div>
              <p className="text-2xl font-bold">{variants[0].frequency}%</p>
            </div>
            <div className="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
              <div className="flex items-center justify-between mb-2">
                <h4 className="font-medium">Variant Complexity</h4>
                <Icon icon="carbon:chart-evaluation" className="h-5 w-5 text-orange-500" />
              </div>
              <p className="text-2xl font-bold">Medium</p>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
              <thead className="bg-gray-50 dark:bg-gray-700">
                <tr>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Variant
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Frequency
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Steps
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Avg. Duration
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                {variants.map((variant) => (
                  <tr key={variant.id}>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="flex items-center">
                        <div className={`h-3 w-3 rounded-full bg-${variant.color}-500 mr-2`}></div>
                        <div className="text-sm font-medium text-gray-900 dark:text-gray-100">{variant.name}</div>
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm text-gray-900 dark:text-gray-100">{variant.frequency}%</div>
                      <div className="w-full bg-gray-200 dark:bg-gray-600 rounded-full h-2.5 mt-1">
                        <div 
                          className={`bg-${variant.color}-500 h-2.5 rounded-full`} 
                          style={{ width: `${variant.frequency}%` }}
                        ></div>
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                      {variant.steps}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                      {variant.avgDuration}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                      <button className="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300 mr-3">
                        View
                      </button>
                      <button className="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300">
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
        <div className="p-4 flex justify-center items-center h-64 bg-gray-50 dark:bg-gray-700 rounded">
          <div className="text-center">
            <Icon icon="carbon:chart-network" className="h-12 w-12 text-gray-400 dark:text-gray-500 mx-auto mb-4" />
            <p className="text-gray-500 dark:text-gray-400">
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
