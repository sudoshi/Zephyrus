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
            className="bg-healthcare-critical/10 dark:bg-healthcare-critical-dark/20 p-4 rounded-lg"
          >
            <div className="flex items-center">
              <div className="p-2 rounded-full bg-healthcare-critical/10 text-healthcare-critical dark:bg-healthcare-critical-dark/20 dark:text-healthcare-critical-dark mr-3">
                <Icon icon="carbon:warning-alt" className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Total Bottlenecks</h3>
                <div className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{bottlenecks?.length || 0}</div>
                <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  Identified in the process
                </div>
              </div>
            </div>
          </motion.div>

          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3, delay: 0.1 }}
            className="bg-healthcare-warning/10 dark:bg-healthcare-warning-dark/20 p-4 rounded-lg"
          >
            <div className="flex items-center">
              <div className="p-2 rounded-full bg-healthcare-warning/10 text-healthcare-warning dark:bg-healthcare-warning-dark/20 dark:text-healthcare-warning-dark mr-3">
                <Icon icon="carbon:time" className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Avg. Wait Time</h3>
                <div className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{stats.bottlenecks?.avgWaitTime || '0 hrs'}</div>
                <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  At bottleneck points
                </div>
              </div>
            </div>
          </motion.div>

          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3, delay: 0.2 }}
            className="bg-healthcare-warning/10 dark:bg-healthcare-warning-dark/20 p-4 rounded-lg"
          >
            <div className="flex items-center">
              <div className="p-2 rounded-full bg-healthcare-warning/10 text-healthcare-warning dark:bg-healthcare-warning-dark/20 dark:text-healthcare-warning-dark mr-3">
                <Icon icon="carbon:chart-maximum" className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Max Impact</h3>
                <div className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{stats.bottlenecks?.maxImpact || 0}%</div>
                <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  Highest bottleneck impact
                </div>
              </div>
            </div>
          </motion.div>

          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3, delay: 0.3 }}
            className="bg-healthcare-info/10 dark:bg-healthcare-info-dark/20 p-4 rounded-lg"
          >
            <div className="flex items-center">
              <div className="p-2 rounded-full bg-healthcare-info/10 text-healthcare-info dark:bg-healthcare-info-dark/20 dark:text-healthcare-info-dark mr-3">
                <Icon icon="carbon:optimize" className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Potential Improvement</h3>
                <div className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{stats.bottlenecks?.potentialImprovement || '0 hrs'}</div>
                <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
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
              <label htmlFor="sortBy" className="block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">
                Sort By
              </label>
              <select
                id="sortBy"
                value={sortBy}
                onChange={(e) => setSortBy(e.target.value)}
                className="block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark shadow-sm focus:border-indigo-500 focus:ring-indigo-500 bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark sm:text-sm"
              >
                <option value="impact">Impact</option>
                <option value="waitTime">Wait Time</option>
                <option value="frequency">Frequency</option>
              </select>
            </div>
            
            <div>
              <label htmlFor="filterThreshold" className="block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">
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
          
          <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Showing {sortedBottlenecks.length} of {bottlenecks.length} bottlenecks
          </div>
        </div>

        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
            <thead className="bg-healthcare-background dark:bg-healthcare-background-dark">
              <tr>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Activity
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Wait Time
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Frequency
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Impact
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Severity
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Root Cause
                </th>
              </tr>
            </thead>
            <tbody className="bg-healthcare-surface dark:bg-healthcare-surface-dark divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
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
                          <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            {bottleneck.activity}
                          </div>
                          {bottleneck.nextActivity && (
                            <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                              Before: {bottleneck.nextActivity}
                            </div>
                          )}
                        </div>
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{bottleneck.waitTime}</div>
                      {bottleneck.avgWaitTime && (
                        <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          Avg: {bottleneck.avgWaitTime}
                        </div>
                      )}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{bottleneck.frequency || 0}</div>
                      {bottleneck.frequencyPercentage && (
                        <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          {bottleneck.frequencyPercentage}% of cases
                        </div>
                      )}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="w-full bg-healthcare-border dark:bg-healthcare-border-dark rounded-full h-2.5 mb-1">
                        <div 
                          className={`h-2.5 rounded-full bg-${severity.color}-500`}
                          style={{ width: `${bottleneck.impact}%` }}
                        ></div>
                      </div>
                      <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        {bottleneck.impact}% impact
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-${severity.color}-100 dark:bg-${severity.color}-900 text-${severity.color}-800 dark:text-${severity.color}-200`}>
                        {severity.level}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {bottleneck.rootCause || 'Unknown'}
                    </td>
                  </motion.tr>
                );
              })}
              
              {sortedBottlenecks.length === 0 && (
                <tr>
                  <td colSpan="6" className="px-6 py-4 text-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
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
              className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg border border-healthcare-border dark:border-healthcare-border-dark shadow-sm"
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
                    className={recommendation.priority === 'low' ? 'w-10 h-10' : 'w-5 h-5'}
                  />
                </div>
                <div>
                  <div className="flex items-center">
                    <h4 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{recommendation.title}</h4>
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
                  <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{recommendation.description}</p>
                  
                  {recommendation.impact && (
                    <div className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      <span className="font-medium">Potential Impact:</span> {recommendation.impact}
                    </div>
                  )}
                  
                  {recommendation.effort && (
                    <div className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      <span className="font-medium">Implementation Effort:</span> {recommendation.effort}
                    </div>
                  )}
                </div>
              </div>
            </motion.div>
          ))}
          
          {(!data?.recommendations || data.recommendations.length === 0) && (
            <div className="text-center py-6 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
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
