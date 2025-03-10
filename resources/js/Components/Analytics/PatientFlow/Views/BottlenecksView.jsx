import React, { useState } from 'react';
import PropTypes from 'prop-types';
import Panel from '@/Components/ui/Panel';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';

const BottlenecksView = ({ data }) => {
  const [sortBy, setSortBy] = useState('impact');
  const [filterThreshold, setFilterThreshold] = useState(0);
  
  // Destructure data for easier access
  const { bottlenecks = [], stats = {} } = data || {};
  
  // Sort bottlenecks based on selected criteria
  const sortedBottlenecks = [...(bottlenecks || [])].sort((a, b) => {
    if (sortBy === 'impact') {
      return b.impact - a.impact;
    } else if (sortBy === 'waitTime') {
      // Convert time strings to comparable values (assuming format like "2.5 hrs")
      const aTime = parseFloat(a.waitTime);
      const bTime = parseFloat(b.waitTime);
      return bTime - aTime;
    } else if (sortBy === 'frequency') {
      return b.frequency - a.frequency;
    }
    return 0;
  }).filter(bottleneck => bottleneck.impact >= filterThreshold);

  // Get severity level for a bottleneck
  const getSeverityLevel = (impact) => {
    if (impact >= 75) return { level: 'Critical', color: 'red' };
    if (impact >= 50) return { level: 'High', color: 'orange' };
    if (impact >= 25) return { level: 'Medium', color: 'yellow' };
    return { level: 'Low', color: 'blue' };
  };

  return (
    <div className="space-y-6">
      {/* Summary Panel */}
      <Panel title="Bottlenecks Summary">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3 }}
            className="bg-red-50 dark:bg-red-900/30 p-4 rounded-lg"
          >
            <div className="flex items-center">
              <div className="p-2 rounded-full bg-red-100 dark:bg-red-800 text-red-600 dark:text-red-300 mr-3">
                <Icon icon="carbon:warning-alt" className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-sm font-medium text-gray-700 dark:text-gray-300">Total Bottlenecks</h3>
                <div className="text-2xl font-bold text-gray-900 dark:text-gray-100">{bottlenecks?.length || 0}</div>
                <div className="text-xs text-gray-500 dark:text-gray-400">
                  Identified in the process
                </div>
              </div>
            </div>
          </motion.div>

          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3, delay: 0.1 }}
            className="bg-orange-50 dark:bg-orange-900/30 p-4 rounded-lg"
          >
            <div className="flex items-center">
              <div className="p-2 rounded-full bg-orange-100 dark:bg-orange-800 text-orange-600 dark:text-orange-300 mr-3">
                <Icon icon="carbon:time" className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-sm font-medium text-gray-700 dark:text-gray-300">Avg. Wait Time</h3>
                <div className="text-2xl font-bold text-gray-900 dark:text-gray-100">{stats.bottlenecks?.avgWaitTime || '0 hrs'}</div>
                <div className="text-xs text-gray-500 dark:text-gray-400">
                  At bottleneck points
                </div>
              </div>
            </div>
          </motion.div>

          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3, delay: 0.2 }}
            className="bg-yellow-50 dark:bg-yellow-900/30 p-4 rounded-lg"
          >
            <div className="flex items-center">
              <div className="p-2 rounded-full bg-yellow-100 dark:bg-yellow-800 text-yellow-600 dark:text-yellow-300 mr-3">
                <Icon icon="carbon:chart-maximum" className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-sm font-medium text-gray-700 dark:text-gray-300">Max Impact</h3>
                <div className="text-2xl font-bold text-gray-900 dark:text-gray-100">{stats.bottlenecks?.maxImpact || 0}%</div>
                <div className="text-xs text-gray-500 dark:text-gray-400">
                  Highest bottleneck impact
                </div>
              </div>
            </div>
          </motion.div>

          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3, delay: 0.3 }}
            className="bg-blue-50 dark:bg-blue-900/30 p-4 rounded-lg"
          >
            <div className="flex items-center">
              <div className="p-2 rounded-full bg-blue-100 dark:bg-blue-800 text-blue-600 dark:text-blue-300 mr-3">
                <Icon icon="carbon:optimize" className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-sm font-medium text-gray-700 dark:text-gray-300">Potential Improvement</h3>
                <div className="text-2xl font-bold text-gray-900 dark:text-gray-100">{stats.bottlenecks?.potentialImprovement || '0 hrs'}</div>
                <div className="text-xs text-gray-500 dark:text-gray-400">
                  If all bottlenecks resolved
                </div>
              </div>
            </div>
          </motion.div>
        </div>
      </Panel>

      {/* Bottlenecks List Panel */}
      <Panel title="Bottlenecks Analysis" className="overflow-hidden">
        <div className="mb-4 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div className="flex items-center space-x-4">
            <div>
              <label htmlFor="sortBy" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                Sort By
              </label>
              <select
                id="sortBy"
                value={sortBy}
                onChange={(e) => setSortBy(e.target.value)}
                className="block w-full rounded-md border-gray-300 dark:border-gray-700 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-800 dark:text-gray-200 sm:text-sm"
              >
                <option value="impact">Impact</option>
                <option value="waitTime">Wait Time</option>
                <option value="frequency">Frequency</option>
              </select>
            </div>
            
            <div>
              <label htmlFor="filterThreshold" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                Min Impact: {filterThreshold}%
              </label>
              <input
                id="filterThreshold"
                type="range"
                min="0"
                max="100"
                value={filterThreshold}
                onChange={(e) => setFilterThreshold(parseInt(e.target.value))}
                className="block w-full rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
              />
            </div>
          </div>
          
          <div className="text-sm text-gray-500 dark:text-gray-400">
            Showing {sortedBottlenecks.length} of {bottlenecks.length} bottlenecks
          </div>
        </div>

        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead className="bg-gray-50 dark:bg-gray-800">
              <tr>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Activity
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Wait Time
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Frequency
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Impact
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Severity
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Root Cause
                </th>
              </tr>
            </thead>
            <tbody className="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-800">
              {sortedBottlenecks.map((bottleneck, index) => {
                const severity = getSeverityLevel(bottleneck.impact);
                return (
                  <motion.tr 
                    key={index}
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.2, delay: index * 0.05 + 0.4 }}
                  >
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="flex items-center">
                        <div className={`flex-shrink-0 h-8 w-8 rounded-full bg-${severity.color}-100 dark:bg-${severity.color}-900 flex items-center justify-center`}>
                          <Icon 
                            icon="carbon:warning-alt" 
                            className={`h-5 w-5 text-${severity.color}-600 dark:text-${severity.color}-400`} 
                          />
                        </div>
                        <div className="ml-4">
                          <div className="text-sm font-medium text-gray-900 dark:text-gray-100">
                            {bottleneck.activity}
                          </div>
                          {bottleneck.nextActivity && (
                            <div className="text-xs text-gray-500 dark:text-gray-400">
                              Before: {bottleneck.nextActivity}
                            </div>
                          )}
                        </div>
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm text-gray-900 dark:text-gray-100">{bottleneck.waitTime}</div>
                      {bottleneck.avgWaitTime && (
                        <div className="text-xs text-gray-500 dark:text-gray-400">
                          Avg: {bottleneck.avgWaitTime}
                        </div>
                      )}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm text-gray-900 dark:text-gray-100">{bottleneck.frequency || 0}</div>
                      {bottleneck.frequencyPercentage && (
                        <div className="text-xs text-gray-500 dark:text-gray-400">
                          {bottleneck.frequencyPercentage}% of cases
                        </div>
                      )}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5 mb-1">
                        <div 
                          className={`h-2.5 rounded-full bg-${severity.color}-500`}
                          style={{ width: `${bottleneck.impact}%` }}
                        ></div>
                      </div>
                      <div className="text-xs text-gray-500 dark:text-gray-400">
                        {bottleneck.impact}% impact
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-${severity.color}-100 dark:bg-${severity.color}-900 text-${severity.color}-800 dark:text-${severity.color}-200`}>
                        {severity.level}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                      {bottleneck.rootCause || 'Unknown'}
                    </td>
                  </motion.tr>
                );
              })}
              
              {sortedBottlenecks.length === 0 && (
                <tr>
                  <td colSpan="6" className="px-6 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                    No bottlenecks match the current filter criteria
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </Panel>

      {/* Recommendations Panel */}
      <Panel title="Bottleneck Recommendations" isSubpanel={true} dropLightIntensity="medium">
        <div className="space-y-4">
          {(data?.recommendations || []).map((recommendation, index) => (
            <motion.div 
              key={index}
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.3, delay: index * 0.1 }}
              className="bg-white dark:bg-gray-800 p-4 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm"
            >
              <div className="flex items-start">
                <div className={`flex-shrink-0 p-2 rounded-full mr-3 bg-${
                  recommendation.priority === 'high' ? 'red' : 
                  recommendation.priority === 'medium' ? 'yellow' : 
                  'blue'
                }-100 dark:bg-${
                  recommendation.priority === 'high' ? 'red' : 
                  recommendation.priority === 'medium' ? 'yellow' : 
                  'blue'
                }-900 text-${
                  recommendation.priority === 'high' ? 'red' : 
                  recommendation.priority === 'medium' ? 'yellow' : 
                  'blue'
                }-600 dark:text-${
                  recommendation.priority === 'high' ? 'red' : 
                  recommendation.priority === 'medium' ? 'yellow' : 
                  'blue'
                }-300`}>
                  <Icon 
                    icon={
                      recommendation.priority === 'high' 
                        ? 'carbon:warning-alt' 
                        : recommendation.priority === 'medium'
                          ? 'carbon:idea'
                          : 'carbon:information'
                    } 
                    className="w-5 h-5" 
                  />
                </div>
                <div>
                  <div className="flex items-center">
                    <h4 className="text-sm font-medium text-gray-900 dark:text-gray-100">{recommendation.title}</h4>
                    <span className={`ml-2 px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-${
                      recommendation.priority === 'high' ? 'red' : 
                      recommendation.priority === 'medium' ? 'yellow' : 
                      'blue'
                    }-100 dark:bg-${
                      recommendation.priority === 'high' ? 'red' : 
                      recommendation.priority === 'medium' ? 'yellow' : 
                      'blue'
                    }-900 text-${
                      recommendation.priority === 'high' ? 'red' : 
                      recommendation.priority === 'medium' ? 'yellow' : 
                      'blue'
                    }-800 dark:text-${
                      recommendation.priority === 'high' ? 'red' : 
                      recommendation.priority === 'medium' ? 'yellow' : 
                      'blue'
                    }-200`}>
                      {recommendation.priority.charAt(0).toUpperCase() + recommendation.priority.slice(1)}
                    </span>
                  </div>
                  <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">{recommendation.description}</p>
                  
                  {recommendation.impact && (
                    <div className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                      <span className="font-medium">Potential Impact:</span> {recommendation.impact}
                    </div>
                  )}
                  
                  {recommendation.effort && (
                    <div className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                      <span className="font-medium">Implementation Effort:</span> {recommendation.effort}
                    </div>
                  )}
                </div>
              </div>
            </motion.div>
          ))}
          
          {(!data?.recommendations || data.recommendations.length === 0) && (
            <div className="text-center py-6 text-gray-500 dark:text-gray-400">
              No recommendations available
            </div>
          )}
        </div>
      </Panel>
    </div>
  );
};

BottlenecksView.propTypes = {
  data: PropTypes.object
};

export default BottlenecksView;
