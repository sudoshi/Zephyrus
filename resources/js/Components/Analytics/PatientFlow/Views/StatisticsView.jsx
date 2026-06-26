import React from 'react';
import PropTypes from 'prop-types';
import Panel from '@/Components/ui/Panel';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';

const StatisticsView = ({ data }) => {
  // Destructure data for easier access
  const { stats = {}, timeDistribution = [], caseDistribution = [] } = data || {};

  return (
    <div className="space-y-6">
      {/* Summary Statistics */}
      <Panel title="Summary Statistics">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3 }}
            className="bg-healthcare-background dark:bg-healthcare-background-dark p-4 rounded-lg"
          >
            <div className="flex items-center">
              <div className="p-2 rounded-full bg-blue-100 dark:bg-blue-800 text-blue-600 dark:text-blue-300 mr-3">
                <Icon icon="carbon:user-multiple" className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Total Cases</h3>
                <div className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{stats.cases?.count || 0}</div>
                <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {stats.cases?.completedCount || 0} completed / {stats.cases?.inProgressCount || 0} in progress
                </div>
              </div>
            </div>
          </motion.div>

          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3, delay: 0.1 }}
            className="bg-healthcare-background dark:bg-healthcare-background-dark p-4 rounded-lg"
          >
            <div className="flex items-center">
              <div className="p-2 rounded-full bg-green-100 dark:bg-green-800 text-green-600 dark:text-green-300 mr-3">
                <Icon icon="carbon:checkmark-filled" className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Completion Rate</h3>
                <div className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{stats.cases?.completionRate || 0}%</div>
                <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {stats.cases?.change > 0 ? '+' : ''}{stats.cases?.change || 0}% from previous period
                </div>
              </div>
            </div>
          </motion.div>

          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3, delay: 0.2 }}
            className="bg-healthcare-background dark:bg-healthcare-background-dark p-4 rounded-lg"
          >
            <div className="flex items-center">
              <div className="p-2 rounded-full bg-purple-100 dark:bg-purple-800 text-purple-600 dark:text-purple-300 mr-3">
                <Icon icon="carbon:time" className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Avg. Process Time</h3>
                <div className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{stats.time?.avgProcessTime || '0 hrs'}</div>
                <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {stats.time?.avgProcessTimeChange > 0 ? '+' : ''}{stats.time?.avgProcessTimeChange || 0}% from previous
                </div>
              </div>
            </div>
          </motion.div>

          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3, delay: 0.3 }}
            className="bg-healthcare-background dark:bg-healthcare-background-dark p-4 rounded-lg"
          >
            <div className="flex items-center">
              <div className="p-2 rounded-full bg-yellow-100 dark:bg-yellow-800 text-yellow-600 dark:text-yellow-300 mr-3">
                <Icon icon="carbon:hourglass" className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Avg. Wait Time</h3>
                <div className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{stats.time?.avgWaitTime || '0 hrs'}</div>
                <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {stats.time?.avgWaitTimeChange > 0 ? '+' : ''}{stats.time?.avgWaitTimeChange || 0}% from previous
                </div>
              </div>
            </div>
          </motion.div>
        </div>
      </Panel>

      {/* Detailed Statistics */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Panel title="Time Distribution" isSubpanel={true} dropLightIntensity="medium">
          <div className="space-y-4">
            <div className="flex justify-between text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              <span>Activity</span>
              <span>Time (hours)</span>
            </div>
            
            {(timeDistribution || []).map((item, index) => (
              <motion.div 
                key={index}
                initial={{ opacity: 0, x: -20 }}
                animate={{ opacity: 1, x: 0 }}
                transition={{ duration: 0.3, delay: index * 0.05 + 0.4 }}
                className="bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg"
              >
                <div className="flex justify-between items-center">
                  <div className="flex items-center">
                    <div className={`w-3 h-3 rounded-full mr-2 ${
                      index === 0 ? 'bg-blue-500' : 
                      index === 1 ? 'bg-indigo-500' : 
                      index === 2 ? 'bg-purple-500' : 
                      index === 3 ? 'bg-pink-500' : 
                      'bg-gray-500'
                    }`}></div>
                    <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{item.activity}</span>
                  </div>
                  <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{item.time}</div>
                </div>
                
                <div className="mt-2 w-full bg-healthcare-border dark:bg-healthcare-border-dark rounded-full h-2.5">
                  <div 
                    className={`h-2.5 rounded-full ${
                      index === 0 ? 'bg-blue-500' : 
                      index === 1 ? 'bg-indigo-500' : 
                      index === 2 ? 'bg-purple-500' : 
                      index === 3 ? 'bg-pink-500' : 
                      'bg-gray-500'
                    }`}
                    style={{ width: `${item.percentage}%` }}
                  ></div>
                </div>
                
                <div className="mt-1 flex justify-between text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  <span>{item.percentage}% of total time</span>
                  {item.waitTime && <span>Wait: {item.waitTime}</span>}
                </div>
              </motion.div>
            ))}
            
            {(!timeDistribution || timeDistribution.length === 0) && (
              <div className="text-center py-6 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                No time distribution data available
              </div>
            )}
          </div>
        </Panel>

        <Panel title="Case Distribution" isSubpanel={true} dropLightIntensity="medium">
          <div className="space-y-4">
            <div className="flex justify-between text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              <span>Category</span>
              <span>Cases</span>
            </div>
            
            {(caseDistribution || []).map((item, index) => (
              <motion.div 
                key={index}
                initial={{ opacity: 0, x: -20 }}
                animate={{ opacity: 1, x: 0 }}
                transition={{ duration: 0.3, delay: index * 0.05 + 0.4 }}
                className="bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg"
              >
                <div className="flex justify-between items-center">
                  <div className="flex items-center">
                    <div className={`w-3 h-3 rounded-full mr-2 ${
                      index === 0 ? 'bg-green-500' : 
                      index === 1 ? 'bg-teal-500' : 
                      index === 2 ? 'bg-cyan-500' : 
                      index === 3 ? 'bg-blue-500' : 
                      'bg-gray-500'
                    }`}></div>
                    <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{item.category}</span>
                  </div>
                  <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{item.count}</div>
                </div>
                
                <div className="mt-2 w-full bg-healthcare-border dark:bg-healthcare-border-dark rounded-full h-2.5">
                  <div 
                    className={`h-2.5 rounded-full ${
                      index === 0 ? 'bg-green-500' : 
                      index === 1 ? 'bg-teal-500' : 
                      index === 2 ? 'bg-cyan-500' : 
                      index === 3 ? 'bg-blue-500' : 
                      'bg-gray-500'
                    }`}
                    style={{ width: `${item.percentage}%` }}
                  ></div>
                </div>
                
                <div className="mt-1 flex justify-between text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  <span>{item.percentage}% of total cases</span>
                  <span>Avg. time: {item.avgTime}</span>
                </div>
              </motion.div>
            ))}
            
            {(!caseDistribution || caseDistribution.length === 0) && (
              <div className="text-center py-6 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                No case distribution data available
              </div>
            )}
          </div>
        </Panel>
      </div>

      {/* Additional Statistics */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <Panel title="Activity Statistics" isSubpanel={true} dropLightIntensity="medium">
          <div className="space-y-4">
            <div className="flex items-center justify-between bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
              <div className="flex items-center">
                <Icon icon="carbon:activity" className="w-5 h-5 mr-2 text-blue-500 dark:text-blue-400" />
                <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Total Activities</span>
              </div>
              <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{stats.activities?.count || 0}</div>
            </div>
            
            <div className="flex items-center justify-between bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
              <div className="flex items-center">
                <Icon icon="carbon:chart-maximum" className="w-5 h-5 mr-2 text-green-500 dark:text-green-400" />
                <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Most Common</span>
              </div>
              <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{stats.activities?.mostCommon || 'N/A'}</div>
            </div>
            
            <div className="flex items-center justify-between bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
              <div className="flex items-center">
                <Icon icon="carbon:chart-minimum" className="w-5 h-5 mr-2 text-red-500 dark:text-red-400" />
                <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Least Common</span>
              </div>
              <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{stats.activities?.leastCommon || 'N/A'}</div>
            </div>
          </div>
        </Panel>

        <Panel title="Time Statistics" isSubpanel={true} dropLightIntensity="medium">
          <div className="space-y-4">
            <div className="flex items-center justify-between bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
              <div className="flex items-center">
                <Icon icon="carbon:time" className="w-5 h-5 mr-2 text-purple-500 dark:text-purple-400" />
                <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Min Process Time</span>
              </div>
              <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{stats.time?.minProcessTime || '0 hrs'}</div>
            </div>
            
            <div className="flex items-center justify-between bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
              <div className="flex items-center">
                <Icon icon="carbon:time" className="w-5 h-5 mr-2 text-indigo-500 dark:text-indigo-400" />
                <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Max Process Time</span>
              </div>
              <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{stats.time?.maxProcessTime || '0 hrs'}</div>
            </div>
            
            <div className="flex items-center justify-between bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
              <div className="flex items-center">
                <Icon icon="carbon:hourglass" className="w-5 h-5 mr-2 text-yellow-500 dark:text-yellow-400" />
                <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Max Wait Time</span>
              </div>
              <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{stats.time?.maxWaitTime || '0 hrs'}</div>
            </div>
          </div>
        </Panel>

        <Panel title="Variant Statistics" isSubpanel={true} dropLightIntensity="medium">
          <div className="space-y-4">
            <div className="flex items-center justify-between bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
              <div className="flex items-center">
                <Icon icon="carbon:flow" className="w-5 h-5 mr-2 text-indigo-500 dark:text-indigo-400" />
                <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Total Variants</span>
              </div>
              <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{stats.variants?.count || 0}</div>
            </div>
            
            <div className="flex items-center justify-between bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
              <div className="flex items-center">
                <Icon icon="carbon:chart-line" className="w-5 h-5 mr-2 text-blue-500 dark:text-blue-400" />
                <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Main Variant %</span>
              </div>
              <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{stats.variants?.mainPercentage || 0}%</div>
            </div>
            
            <div className="flex items-center justify-between bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
              <div className="flex items-center">
                <Icon icon="carbon:tree-view" className="w-5 h-5 mr-2 text-green-500 dark:text-green-400" />
                <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Variant Complexity</span>
              </div>
              <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{stats.variants?.complexity || 'Low'}</div>
            </div>
          </div>
        </Panel>
      </div>
    </div>
  );
};

StatisticsView.propTypes = {
  data: PropTypes.object
};

export default StatisticsView;
