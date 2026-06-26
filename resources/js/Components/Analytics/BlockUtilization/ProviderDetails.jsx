import React from 'react';
import PropTypes from 'prop-types';
import { utilizationRanges } from '../../../mock-data/block-utilization';
import { ProviderMetricsPropType } from './types';

const ProviderDetails = ({ providerData }) => {
  const getUtilizationColor = (value) => {
    if (value === null || value === undefined) return utilizationRanges.noBlock.color;
    if (value <= utilizationRanges.low.max) return utilizationRanges.low.color;
    if (value <= utilizationRanges.medium.max) return utilizationRanges.medium.color;
    return utilizationRanges.high.color;
  };

  return (
    <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow overflow-hidden">
      <div className="p-6">
        <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
          Provider Block Utilization
        </h3>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
            <thead className="bg-healthcare-background dark:bg-healthcare-background-dark">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Provider
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Service
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Cases
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Before Block
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  In Block
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Overusage
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Out of Block
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  After Block
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  % Non Prime
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Block Time
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  In Block %
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Total %
                </th>
              </tr>
            </thead>
            <tbody className="bg-healthcare-surface dark:bg-healthcare-surface-dark divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
              {Object.entries(providerData).map(([provider, data]) => (
                <tr key={provider}>
                  <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {provider}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {data.service}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-right text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {data.numof_cases}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-right text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {data.before_block_start}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-right text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {data.in_block}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-right text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {data.overusage}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-right text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {data.out_of_block}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-right text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {data.after_block_finish}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-right text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {data.non_prime_percentage?.toFixed(2)}%
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-right text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {data.block_time}
                  </td>
                  <td 
                    className="px-6 py-4 whitespace-nowrap text-sm text-right"
                    style={{
                      backgroundColor: getUtilizationColor(data.in_block_utilization)
                    }}
                  >
                    {data.in_block_utilization?.toFixed(2)}%
                  </td>
                  <td 
                    className="px-6 py-4 whitespace-nowrap text-sm text-right"
                    style={{
                      backgroundColor: getUtilizationColor(data.total_block_utilization)
                    }}
                  >
                    {data.total_block_utilization?.toFixed(2)}%
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* Legend */}
      <div className="px-6 pb-4 flex items-center space-x-6">
        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Utilization Range:</div>
        <div className="flex items-center space-x-4">
          <div className="flex items-center">
            <div 
              className="w-4 h-4 mr-2" 
              style={{ backgroundColor: utilizationRanges.low.color }}
            />
            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">&lt; {utilizationRanges.low.max}%</span>
          </div>
          <div className="flex items-center">
            <div 
              className="w-4 h-4 mr-2" 
              style={{ backgroundColor: utilizationRanges.medium.color }}
            />
            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{utilizationRanges.medium.min}-{utilizationRanges.medium.max}%</span>
          </div>
          <div className="flex items-center">
            <div 
              className="w-4 h-4 mr-2" 
              style={{ backgroundColor: utilizationRanges.high.color }}
            />
            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">&gt; {utilizationRanges.medium.max}%</span>
          </div>
        </div>
      </div>
    </div>
  );
};

ProviderDetails.propTypes = {
  providerData: ProviderMetricsPropType
};

ProviderDetails.defaultProps = {
  providerData: {}
};

export default ProviderDetails;
