import React, { useMemo } from 'react';
import { ResponsiveBar } from '@nivo/bar';
import MetricCard from '@/Components/ui/MetricCard';
import Panel from '@/Components/ui/Panel';
import { formatDurationHours } from '@/lib/duration';

const BlockView = ({ filters, data }) => {
  // Extract filter values from the new filter structure
  const { selectedHospital, selectedLocation, selectedSpecialty, dateRange } = filters;
  
  // Filter data based on hierarchical filters
  const filteredData = useMemo(() => {
    let filteredBlockData = [...data.blockData];
    
    // Filter by hospital if selected
    if (selectedHospital) {
      filteredBlockData = filteredBlockData.filter(block => 
        block.sites && block.sites.some(site => site.includes(selectedHospital))
      );
    }
    
    // Filter by location if selected
    if (selectedLocation) {
      filteredBlockData = filteredBlockData.filter(block => 
        block.sites && block.sites.some(site => site.includes(selectedLocation))
      );
    }
    
    // Filter by specialty if selected
    if (selectedSpecialty) {
      filteredBlockData = filteredBlockData.filter(block => 
        block.specialty === selectedSpecialty
      );
    }
    
    return filteredBlockData;
  }, [selectedHospital, selectedLocation, selectedSpecialty, dateRange]);

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

  // Calculate metrics based on filtered data
  const calculateMetrics = () => {
    if (!filteredData || filteredData.length === 0) {
      return {
        totalBlocks: 0,
        averageUtilization: 0,
        releaseRate: 0
      };
    }
    
    const totalBlocks = filteredData.length;
    const totalUtilization = filteredData.reduce((sum, block) => sum + (block.utilization || 0), 0);
    const averageUtilization = totalUtilization / totalBlocks;
    
    // Count blocks that were released
    const releasedBlocks = filteredData.filter(block => block.released).length;
    const releaseRate = (releasedBlocks / totalBlocks) * 100;
    
    return {
      totalBlocks,
      averageUtilization,
      releaseRate
    };
  };
  
  const metrics = calculateMetrics();

  // Sort blocks by utilization for the chart
  const sortedBlocks = [...filteredData].sort((a, b) => b.utilization - a.utilization).slice(0, 10);
  
  // Prepare data for the bar chart
  const barChartData = sortedBlocks.map(block => ({
    block: block.name,
    utilization: block.utilization
  }));

  return (
    <div className="animate-fadeIn">
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <MetricCard 
          title="Total Blocks" 
          value={metrics.totalBlocks.toString()} 
          icon="calendar-check"
          iconColor="text-blue-500"
          isSubpanel={true}
        />
        <MetricCard 
          title="Average Utilization" 
          value={formatPercentage(metrics.averageUtilization)} 
          icon="chart-pie"
          iconColor="text-emerald-500"
          isSubpanel={true}
        />
        <MetricCard 
          title="Release Rate" 
          value={formatPercentage(metrics.releaseRate)} 
          icon="arrow-up-right"
          iconColor="text-healthcare-info dark:text-healthcare-info-dark"
          isSubpanel={true}
        />
      </div>

      <Panel title="Block Group Utilization" dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveBar
            data={barChartData}
            keys={['utilization']}
            indexBy="block"
            margin={{ top: 10, right: 130, bottom: 50, left: 60 }}
            padding={0.3}
            valueScale={{ type: 'linear' }}
            indexScale={{ type: 'band', round: true }}
            colors={['#3B82F6']}
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
          <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
            Block groups are analyzed based on their specialty and utilization patterns. The data shows significant variations in utilization across different block groups.
          </p>
          <div className="mt-4">
            <h3 className="font-medium mb-2 dark:text-white">Recommendations:</h3>
            <ul className="list-disc list-inside space-y-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              <li>Review allocation for low-performing blocks (below 70% utilization)</li>
              <li>Consider redistributing time from underutilized blocks to high-demand services</li>
              <li>Implement regular block utilization reviews on a quarterly basis</li>
            </ul>
          </div>
        </Panel>
        
        <Panel title="Block Utilization Ranking" isSubpanel={false} dropLightIntensity="medium">
          <div className="space-y-4">
            {filteredData
              .sort((a, b) => b.utilization - a.utilization)
              .map((block, index) => (
                <div key={index} className="border-b pb-3 last:border-0">
                  <div className="flex justify-between mb-2">
                    <h3 className="font-medium dark:text-white">{block.name}</h3>
                    <span className="text-healthcare-info dark:text-healthcare-info-dark">{formatPercentage(block.utilization)}</span>
                  </div>
                  <div className="w-full bg-healthcare-border dark:bg-healthcare-border-dark rounded-full h-2.5">
                    <div 
                      className={`h-2.5 rounded-full ${
                        block.utilization > 75 ? 'bg-healthcare-success dark:bg-healthcare-success-dark' :
                        block.utilization > 70 ? 'bg-healthcare-info dark:bg-healthcare-info-dark' :
                        block.utilization > 65 ? 'bg-healthcare-warning dark:bg-healthcare-warning-dark' : 'bg-healthcare-critical dark:bg-healthcare-critical-dark'
                      }`}
                      style={{ width: `${block.utilization}%` }}
                    />
                  </div>
                </div>
              ))}
          </div>
        </Panel>
      </div>

      <div className="mt-6">
        <Panel title="Nested Panels Example" dropLightIntensity="medium">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <Panel title="Subtle Drop Light" isSubpanel={true} dropLightIntensity="subtle">
              <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                This panel uses a subtle drop light effect, providing minimal visual distinction.
              </p>
            </Panel>
            <Panel title="Medium Drop Light" isSubpanel={true} dropLightIntensity="medium">
              <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                This panel uses a medium drop light effect, the default setting for subpanels.
              </p>
            </Panel>
          </div>
          <Panel title="Strong Drop Light" isSubpanel={true} dropLightIntensity="strong">
            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              This panel uses a strong drop light effect, providing maximum visual distinction from the parent panel.
            </p>
          </Panel>
        </Panel>
      </div>

      <Panel title="Detailed Block Analysis" className="mt-6" dropLightIntensity="medium">
        <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
          The following detailed analysis provides insights into block utilization patterns and opportunities for improvement.
        </p>
        
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
          <Panel title="Detailed Metrics" isSubpanel={true} dropLightIntensity="medium">
            <div className="space-y-2">
              <div className="flex justify-between">
                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Average Block Duration</span>
                <span className="font-medium">{formatDurationHours(4.2)}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Blocks per Service</span>
                <span className="font-medium">3.5</span>
              </div>
              <div className="flex justify-between">
                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Release Time Compliance</span>
                <span className="font-medium">87.5%</span>
              </div>
              <div className="flex justify-between">
                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Block Efficiency</span>
                <span className="font-medium">64.2%</span>
              </div>
            </div>
          </Panel>
          
          <Panel title="Allocation Distribution" isSubpanel={true} dropLightIntensity="medium">
            <div className="space-y-2">
              <div className="flex justify-between">
                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Morning Blocks</span>
                <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">45%</span>
              </div>
              <div className="flex justify-between">
                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Afternoon Blocks</span>
                <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">35%</span>
              </div>
              <div className="flex justify-between">
                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Evening Blocks</span>
                <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">20%</span>
              </div>
              <div className="flex justify-between">
                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Weekend Blocks</span>
                <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">15%</span>
              </div>
            </div>
          </Panel>
        </div>
        
        <div className="mt-4">
          <h3 className="font-medium mb-2 dark:text-white">Key Observations:</h3>
          <ul className="list-disc list-inside space-y-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <li>Morning blocks show higher utilization rates compared to afternoon blocks</li>
            <li>Weekend blocks have the lowest utilization but highest revenue per minute</li>
            <li>Services with dedicated coordinators show 12% higher utilization rates</li>
          </ul>
        </div>
      </Panel>

      <Panel title="Block Utilization Recommendations" className="mt-6" dropLightIntensity="medium">
        <ul className="space-y-3">
          <li className="flex items-start">
            <div className="flex-shrink-0 h-5 w-5 text-healthcare-info dark:text-healthcare-info-dark">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clipRule="evenodd" />
              </svg>
            </div>
            <p className="ml-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Redistribute blocks from low-utilization services to high-demand services to improve overall efficiency.
            </p>
          </li>
          <li className="flex items-start">
            <div className="flex-shrink-0 h-5 w-5 text-healthcare-info dark:text-healthcare-info-dark">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clipRule="evenodd" />
              </svg>
            </div>
            <p className="ml-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Implement a more flexible block release policy to encourage early release of unused block time.
            </p>
          </li>
          <li className="flex items-start">
            <div className="flex-shrink-0 h-5 w-5 text-healthcare-info dark:text-healthcare-info-dark">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clipRule="evenodd" />
              </svg>
            </div>
            <p className="ml-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Consider adjusting block durations to better match actual case lengths and reduce idle time.
            </p>
          </li>
          <li className="flex items-start">
            <div className="flex-shrink-0 h-5 w-5 text-healthcare-info dark:text-healthcare-info-dark">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clipRule="evenodd" />
              </svg>
            </div>
            <p className="ml-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Establish a regular review process for block allocation to ensure alignment with changing service needs.
            </p>
          </li>
        </ul>
      </Panel>
    </div>
  );
};

export default BlockView;
