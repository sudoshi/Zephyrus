import React from 'react';
import { mockBlockUtilization } from '@/mock-data/block-utilization';
import { ResponsiveBar } from '@nivo/bar';
import MetricCard from '@/Components/ui/MetricCard';
import Panel from '@/Components/ui/Panel';

const BlockView = ({ filters }) => {
  // For the demo, we'll use the serviceData as a proxy for block groups
  // In a real implementation, this would be actual block group data
  const blockData = mockBlockUtilization.serviceData.map(service => ({
    name: `Block ${service.name}`,
    inBlockUtilization: service.metrics.inBlockUtilization,
    totalBlockUtilization: service.metrics.totalBlockUtilization,
    nonPrimePercentage: service.metrics.nonPrimePercentage
  }));

  // Helper function to format percentages
  const formatPercentage = (value) => {
    if (typeof value === 'number') {
      return `${value.toFixed(1)}%`;
    } else if (typeof value === 'string') {
      // If it's already a string, just return it (it might already have % sign)
      return value;
    }
    return 'N/A';
  };

  return (
    <div className="animate-fadeIn">
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <MetricCard 
          title="Average Block Utilization" 
          value="72.3%" 
          trend="+1.5%" 
          trendDirection="up"
          icon="chart-bar"
          iconColor="text-blue-500"
          isSubpanel={true}
        />
        <MetricCard 
          title="Highest Block" 
          value="Orthopedics (74.1%)" 
          icon="arrow-trending-up"
          iconColor="text-emerald-500"
          isSubpanel={true}
        />
        <MetricCard 
          title="Lowest Block" 
          value="Urology (65.2%)" 
          icon="arrow-trending-down"
          iconColor="text-red-500"
          isSubpanel={true}
        />
      </div>

      <Panel title="Block Group Utilization" dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveBar
            data={blockData}
            keys={['inBlockUtilization', 'totalBlockUtilization']}
            indexBy="name"
            margin={{ top: 10, right: 130, bottom: 50, left: 60 }}
            padding={0.3}
            valueScale={{ type: 'linear' }}
            indexScale={{ type: 'band', round: true }}
            colors={['#3B82F6', '#10B981']}
            borderColor={{ from: 'color', modifiers: [['darker', 1.6]] }}
            axisTop={null}
            axisRight={null}
            axisBottom={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: -45,
              legend: 'Block Group',
              legendPosition: 'middle',
              legendOffset: 40,
              tickColor: 'var(--color-gray-400)',
              legendColor: 'var(--color-gray-700)'
            }}
            axisLeft={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: 'Utilization (%)',
              legendPosition: 'middle',
              legendOffset: -40,
              tickColor: 'var(--color-gray-400)',
              legendColor: 'var(--color-gray-700)'
            }}
            labelSkipWidth={12}
            labelSkipHeight={12}
            labelTextColor={{ from: 'color', modifiers: [['darker', 1.6]] }}
            legends={[
              {
                dataFrom: 'keys',
                anchor: 'bottom-right',
                direction: 'column',
                justify: false,
                translateX: 120,
                translateY: 0,
                itemsSpacing: 2,
                itemWidth: 100,
                itemHeight: 20,
                itemDirection: 'left-to-right',
                itemOpacity: 0.85,
                symbolSize: 20,
                itemTextColor: 'var(--color-gray-700)',
                effects: [
                  {
                    on: 'hover',
                    style: {
                      itemOpacity: 1
                    }
                  }
                ]
              }
            ]}
            animate={true}
            motionStiffness={90}
            motionDamping={15}
            theme={{
              axis: {
                ticks: {
                  text: {
                    fill: 'var(--color-gray-600)'
                  },
                  line: {
                    stroke: 'var(--color-gray-400)'
                  }
                },
                legend: {
                  text: {
                    fill: 'var(--color-gray-600)'
                  }
                }
              },
              grid: {
                line: {
                  stroke: 'var(--color-gray-200)'
                }
              },
              legends: {
                text: {
                  fill: 'var(--color-gray-600)'
                }
              }
            }}
          />
        </div>
      </Panel>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
        <Panel title="Block Analysis" isSubpanel={false} dropLightIntensity="medium">
          <p className="text-gray-700 dark:text-gray-300 mb-4">
            Block groups are analyzed based on their specialty and utilization patterns. The data shows significant variations in utilization across different block groups.
          </p>
          <div className="mt-4">
            <h3 className="font-medium mb-2 dark:text-white">Recommendations:</h3>
            <ul className="list-disc list-inside space-y-1 text-gray-700 dark:text-gray-300">
              <li>Review allocation for low-performing blocks (below 70% utilization)</li>
              <li>Consider redistributing time from underutilized blocks to high-demand services</li>
              <li>Implement regular block utilization reviews on a quarterly basis</li>
            </ul>
          </div>
        </Panel>
        
        <Panel title="Block Utilization Ranking" isSubpanel={false} dropLightIntensity="medium">
          <div className="space-y-4">
            {blockData
              .sort((a, b) => b.inBlockUtilization - a.inBlockUtilization)
              .map((block, index) => (
                <div key={index} className="border-b pb-3 last:border-0">
                  <div className="flex justify-between mb-2">
                    <h3 className="font-medium dark:text-white">{block.name}</h3>
                    <span className="text-blue-600 dark:text-blue-400">{formatPercentage(block.inBlockUtilization)}</span>
                  </div>
                  <div className="w-full bg-gray-200 dark:bg-gray-600 rounded-full h-2.5">
                    <div 
                      className={`h-2.5 rounded-full ${
                        block.inBlockUtilization > 75 ? 'bg-emerald-500' : 
                        block.inBlockUtilization > 70 ? 'bg-blue-600' : 
                        block.inBlockUtilization > 65 ? 'bg-amber-500' : 'bg-red-500'
                      }`} 
                      style={{ width: `${block.inBlockUtilization}%` }}
                    />
                  </div>
                </div>
              ))}
          </div>
        </Panel>
      </div>

      <div className="mt-8">
        <Panel title="Nested Panels Example" dropLightIntensity="medium">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <Panel title="Subtle Drop Light" isSubpanel={true} dropLightIntensity="subtle">
              <p className="text-gray-600 dark:text-gray-300">
                This panel uses a subtle drop light effect, providing minimal visual distinction.
              </p>
            </Panel>
            <Panel title="Medium Drop Light" isSubpanel={true} dropLightIntensity="medium">
              <p className="text-gray-600 dark:text-gray-300">
                This panel uses a medium drop light effect, the default setting for subpanels.
              </p>
            </Panel>
          </div>
          <Panel title="Strong Drop Light" isSubpanel={true} dropLightIntensity="strong">
            <p className="text-gray-600 dark:text-gray-300">
              This panel uses a strong drop light effect, providing maximum visual distinction from the parent panel.
            </p>
          </Panel>
        </Panel>
      </div>

      <Panel title="Detailed Block Analysis" className="mt-6" dropLightIntensity="medium">
        <p className="text-gray-700 dark:text-gray-300 mb-4">
          The following detailed analysis provides insights into block utilization patterns and opportunities for improvement.
        </p>
        
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
          <Panel title="Detailed Metrics" isSubpanel={true} dropLightIntensity="medium">
            <div className="space-y-2">
              <div className="flex justify-between">
                <span className="text-sm text-gray-500 dark:text-gray-400">Average Block Duration</span>
                <span className="font-medium">4.2 hours</span>
              </div>
              <div className="flex justify-between">
                <span className="text-sm text-gray-500 dark:text-gray-400">Blocks per Service</span>
                <span className="font-medium">3.5</span>
              </div>
              <div className="flex justify-between">
                <span className="text-sm text-gray-500 dark:text-gray-400">Release Time Compliance</span>
                <span className="font-medium">87.5%</span>
              </div>
              <div className="flex justify-between">
                <span className="text-sm text-gray-500 dark:text-gray-400">Block Efficiency</span>
                <span className="font-medium">64.2%</span>
              </div>
            </div>
          </Panel>
          
          <Panel title="Allocation Distribution" isSubpanel={true} dropLightIntensity="medium">
            <div className="space-y-2">
              <div className="flex justify-between">
                <span className="text-sm text-gray-500 dark:text-gray-400">Morning Blocks</span>
                <span className="text-sm font-medium text-gray-900 dark:text-white">45%</span>
              </div>
              <div className="flex justify-between">
                <span className="text-sm text-gray-500 dark:text-gray-400">Afternoon Blocks</span>
                <span className="text-sm font-medium text-gray-900 dark:text-white">35%</span>
              </div>
              <div className="flex justify-between">
                <span className="text-sm text-gray-500 dark:text-gray-400">Evening Blocks</span>
                <span className="text-sm font-medium text-gray-900 dark:text-white">20%</span>
              </div>
              <div className="flex justify-between">
                <span className="text-sm text-gray-500 dark:text-gray-400">Weekend Blocks</span>
                <span className="text-sm font-medium text-gray-900 dark:text-white">15%</span>
              </div>
            </div>
          </Panel>
        </div>
        
        <div className="mt-4">
          <h3 className="font-medium mb-2 dark:text-white">Key Observations:</h3>
          <ul className="list-disc list-inside space-y-1 text-gray-700 dark:text-gray-300">
            <li>Morning blocks show higher utilization rates compared to afternoon blocks</li>
            <li>Weekend blocks have the lowest utilization but highest revenue per minute</li>
            <li>Services with dedicated coordinators show 12% higher utilization rates</li>
          </ul>
        </div>
      </Panel>

      <Panel title="Block Utilization Recommendations" className="mt-6" dropLightIntensity="medium">
        <ul className="space-y-3">
          <li className="flex items-start">
            <div className="flex-shrink-0 h-5 w-5 text-blue-500">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clipRule="evenodd" />
              </svg>
            </div>
            <p className="ml-2 text-gray-700 dark:text-gray-300">
              Redistribute blocks from low-utilization services to high-demand services to improve overall efficiency.
            </p>
          </li>
          <li className="flex items-start">
            <div className="flex-shrink-0 h-5 w-5 text-blue-500">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clipRule="evenodd" />
              </svg>
            </div>
            <p className="ml-2 text-gray-700 dark:text-gray-300">
              Implement a more flexible block release policy to encourage early release of unused block time.
            </p>
          </li>
          <li className="flex items-start">
            <div className="flex-shrink-0 h-5 w-5 text-blue-500">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clipRule="evenodd" />
              </svg>
            </div>
            <p className="ml-2 text-gray-700 dark:text-gray-300">
              Consider adjusting block durations to better match actual case lengths and reduce idle time.
            </p>
          </li>
          <li className="flex items-start">
            <div className="flex-shrink-0 h-5 w-5 text-blue-500">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clipRule="evenodd" />
              </svg>
            </div>
            <p className="ml-2 text-gray-700 dark:text-gray-300">
              Establish a regular review process for block allocation to ensure alignment with changing service needs.
            </p>
          </li>
        </ul>
      </Panel>
    </div>
  );
};

export default BlockView;
