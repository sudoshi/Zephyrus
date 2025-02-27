import React from 'react';
import { mockBlockUtilization } from '@/mock-data/block-utilization';
import { ResponsiveLine } from '@nivo/line';
import MetricCard from '@/Components/ui/MetricCard';
import Panel from '@/Components/ui/Panel';

const TrendView = ({ filters }) => {
  // Prepare data for the line chart
  const lineChartData = [
    {
      id: 'In-Block Utilization',
      color: '#3B82F6',
      data: mockBlockUtilization.trendData.inBlock
    },
    {
      id: 'Total Block Utilization',
      color: '#10B981',
      data: mockBlockUtilization.trendData.total
    }
  ];

  return (
    <div className="animate-fadeIn">
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <MetricCard 
          title="In-Block Utilization" 
          value={mockBlockUtilization.overallMetrics.inBlockUtilization} 
          trend="+2.3%" 
          trendDirection="up"
          icon="chart-bar"
          iconColor="text-blue-500"
        />
        <MetricCard 
          title="Total Block Utilization" 
          value={mockBlockUtilization.overallMetrics.totalBlockUtilization} 
          trend="+1.8%" 
          trendDirection="up"
          icon="chart-pie"
          iconColor="text-emerald-500"
        />
        <MetricCard 
          title="Non-Prime Time" 
          value={mockBlockUtilization.overallMetrics.nonPrimePercentage} 
          trend="-0.7%" 
          trendDirection="down"
          icon="clock"
          iconColor="text-purple-500"
        />
      </div>

      <div className="grid grid-cols-1 gap-6">
        <Panel title="Utilization Trends" dropLightIntensity="medium">
          <div className="h-96">
            <ResponsiveLine
              data={lineChartData}
              margin={{ top: 10, right: 110, bottom: 50, left: 60 }}
              xScale={{ 
                type: 'time',
                format: '%Y-%m-%d',
                useUTC: false,
                precision: 'day' 
              }}
              xFormat="time:%Y-%m-%d"
              yScale={{ 
                type: 'linear', 
                min: 'auto', 
                max: 'auto', 
                stacked: false, 
                reverse: false 
              }}
              yFormat=" >-.1f"
              axisTop={null}
              axisRight={null}
              axisBottom={{
                format: '%b %d',
                tickValues: 'every 2 weeks',
                legend: 'Date',
                legendOffset: 36,
                legendPosition: 'middle',
                tickTextColor: 'var(--color-gray-700)',
                legendColor: 'var(--color-gray-700)'
              }}
              axisLeft={{
                legend: 'Utilization (%)',
                legendOffset: -40,
                legendPosition: 'middle',
                tickTextColor: 'var(--color-gray-700)',
                legendColor: 'var(--color-gray-700)'
              }}
              pointSize={10}
              pointColor={{ theme: 'background' }}
              pointBorderWidth={2}
              pointBorderColor={{ from: 'serieColor' }}
              pointLabelYOffset={-12}
              useMesh={true}
              legends={[
                {
                  anchor: 'bottom-right',
                  direction: 'column',
                  justify: false,
                  translateX: 100,
                  translateY: 0,
                  itemsSpacing: 0,
                  itemDirection: 'left-to-right',
                  itemWidth: 80,
                  itemHeight: 20,
                  itemOpacity: 0.75,
                  symbolSize: 12,
                  symbolShape: 'circle',
                  symbolBorderColor: 'rgba(0, 0, 0, .5)',
                  itemTextColor: 'var(--color-gray-700)',
                  effects: [
                    {
                      on: 'hover',
                      style: {
                        itemBackground: 'rgba(0, 0, 0, .03)',
                        itemOpacity: 1
                      }
                    }
                  ]
                }
              ]}
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
                },
                tooltip: {
                  container: {
                    background: 'var(--color-gray-100)',
                    color: 'var(--color-gray-800)'
                  }
                }
              }}
            />
          </div>
        </Panel>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <h2 className="text-lg font-semibold mb-4 dark:text-white">Utilization by Location</h2>
          <div className="space-y-4">
            {Object.keys(mockBlockUtilization.sites).map((siteName, index) => {
              const site = mockBlockUtilization.sites[siteName];
              return (
                <div key={index} className="border-b pb-3 last:border-0">
                  <div className="flex justify-between mb-2">
                    <h3 className="font-medium">{siteName}</h3>
                    <span className="text-blue-600">{site.metrics.inBlockUtilization}</span>
                  </div>
                  <div className="w-full bg-gray-200 rounded-full h-2.5">
                    <div 
                      className="bg-blue-600 h-2.5 rounded-full" 
                      style={{ width: site.metrics.inBlockUtilization }}
                    />
                  </div>
                </div>
              );
            })}
          </div>
        </div>

        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <h2 className="text-lg font-semibold mb-4 dark:text-white">Non-Prime Time Trend</h2>
          <div className="h-64">
            <ResponsiveLine
              data={[
                {
                  id: 'Non-Prime Time',
                  color: '#8B5CF6',
                  data: mockBlockUtilization.nonPrimeTimeTrendData
                }
              ]}
              margin={{ top: 10, right: 30, bottom: 50, left: 60 }}
              xScale={{ 
                type: 'time',
                format: '%Y-%m-%d',
                useUTC: false,
                precision: 'day' 
              }}
              xFormat="time:%Y-%m-%d"
              yScale={{ 
                type: 'linear', 
                min: 'auto', 
                max: 'auto', 
                stacked: false, 
                reverse: false 
              }}
              yFormat=" >-.1f"
              axisTop={null}
              axisRight={null}
              axisBottom={{
                format: '%b %d',
                tickValues: 5,
                legend: 'Date',
                legendOffset: 36,
                legendPosition: 'middle'
              }}
              axisLeft={{
                legend: 'Non-Prime Time (%)',
                legendOffset: -40,
                legendPosition: 'middle'
              }}
              enableGridX={false}
              curve="monotoneX"
              colors={['#8B5CF6']}
              pointSize={8}
              pointColor={{ theme: 'background' }}
              pointBorderWidth={2}
              pointBorderColor={{ from: 'serieColor' }}
              pointLabelYOffset={-12}
              useMesh={true}
            />
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
        <Panel title="Trend Analysis">
          <div className="space-y-4">
            <Panel title="Performance Insights" isSubpanel={true} dropLightIntensity="medium">
              <div className="space-y-3">
                <div className="p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                  <h4 className="font-medium text-blue-800 dark:text-blue-300 mb-1">Steady Improvement</h4>
                  <p className="text-sm text-gray-600 dark:text-gray-300">
                    In-block utilization has shown a consistent upward trend over the past 8 weeks, indicating successful optimization efforts.
                  </p>
                </div>
                <div className="p-3 bg-emerald-50 dark:bg-emerald-900/20 rounded-lg">
                  <h4 className="font-medium text-emerald-800 dark:text-emerald-300 mb-1">Peak Performance</h4>
                  <p className="text-sm text-gray-600 dark:text-gray-300">
                    Week 10 showed the highest total block utilization at 72.8%, which coincided with the implementation of new scheduling protocols.
                  </p>
                </div>
                <div className="p-3 bg-amber-50 dark:bg-amber-900/20 rounded-lg">
                  <h4 className="font-medium text-amber-800 dark:text-amber-300 mb-1">Seasonal Patterns</h4>
                  <p className="text-sm text-gray-600 dark:text-gray-300">
                    A slight dip in weeks 5-6 corresponds with the holiday season, suggesting a need for adjusted block allocation during these periods.
                  </p>
                </div>
              </div>
            </Panel>
          </div>
        </Panel>

        <Panel title="Recommendations">
          <div className="space-y-4">
            <Panel title="Action Items" isSubpanel={true} dropLightIntensity="medium">
              <ul className="space-y-3">
                <li className="flex items-start">
                  <div className="flex-shrink-0 h-5 w-5 text-blue-500">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                      <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clipRule="evenodd" />
                    </svg>
                  </div>
                  <p className="ml-2 text-gray-700 dark:text-gray-300">
                    Continue with the current block allocation strategy that has led to the recent improvement trend.
                  </p>
                </li>
                <li className="flex items-start">
                  <div className="flex-shrink-0 h-5 w-5 text-blue-500">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                      <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clipRule="evenodd" />
                    </svg>
                  </div>
                  <p className="ml-2 text-gray-700 dark:text-gray-300">
                    Develop a holiday season block adjustment plan to address the seasonal dip observed in weeks 5-6.
                  </p>
                </li>
                <li className="flex items-start">
                  <div className="flex-shrink-0 h-5 w-5 text-blue-500">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                      <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clipRule="evenodd" />
                    </svg>
                  </div>
                  <p className="ml-2 text-gray-700 dark:text-gray-300">
                    Analyze the factors that contributed to the peak performance in week 10 and document best practices.
                  </p>
                </li>
                <li className="flex items-start">
                  <div className="flex-shrink-0 h-5 w-5 text-blue-500">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                      <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clipRule="evenodd" />
                    </svg>
                  </div>
                  <p className="ml-2 text-gray-700 dark:text-gray-300">
                    Set a target of 75% in-block utilization for the next quarter based on the current improvement trajectory.
                  </p>
                </li>
              </ul>
            </Panel>
          </div>
        </Panel>
      </div>
    </div>
  );
};

export default TrendView;
