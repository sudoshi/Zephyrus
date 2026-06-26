import React from 'react';
import PropTypes from 'prop-types';
import { utilizationRanges } from '../../../mock-data/block-utilization';
import { DayOfWeekDataPropType } from './types';

const DayOfWeekAnalysis = ({ dayOfWeekData }) => {
  const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

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
          Block Utilization by Day of Week
        </h3>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
            <thead className="bg-healthcare-background dark:bg-healthcare-background-dark">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Service
                </th>
                {days.map(day => (
                  <th key={day} className="px-6 py-3 text-center text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                    {day}
                  </th>
                ))}
                <th className="px-6 py-3 text-center text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Total
                </th>
              </tr>
            </thead>
            <tbody className="bg-healthcare-surface dark:bg-healthcare-surface-dark divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
              {Object.entries(dayOfWeekData).map(([service, data]) => (
                <tr key={service}>
                  <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {service}
                  </td>
                  {days.map(day => {
                    const value = data[day];
                    return (
                      <td 
                        key={day} 
                        className="px-6 py-4 whitespace-nowrap text-sm text-center"
                        style={{
                          backgroundColor: getUtilizationColor(value)
                        }}
                      >
                        {value?.toFixed(2)}%
                      </td>
                    );
                  })}
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-center font-semibold">
                    {data.total?.toFixed(2)}%
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
          <div className="flex items-center">
            <div
              className="w-4 h-4 mr-2"
              style={{ backgroundColor: utilizationRanges.noBlock.color }}
            />
            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No Block Time</span>
          </div>
        </div>
      </div>
    </div>
  );
};

DayOfWeekAnalysis.propTypes = {
  dayOfWeekData: DayOfWeekDataPropType
};

DayOfWeekAnalysis.defaultProps = {
  dayOfWeekData: {}
};

export default DayOfWeekAnalysis;
