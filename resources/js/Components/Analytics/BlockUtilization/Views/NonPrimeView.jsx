import React from 'react';
import { mockBlockUtilization } from '@/mock-data/block-utilization';
import { ResponsivePie } from '@nivo/pie';
import { ResponsiveLine } from '@nivo/line';
import MetricCard from '@/Components/ui/MetricCard';
import Panel from '@/Components/ui/Panel';

const NonPrimeView = ({ filters }) => {
  // Extract non-prime time data for visualization
  const nonPrimeData = mockBlockUtilization.serviceData.map(service => ({
    id: service.name,
    label: service.name,
    value: parseFloat(service.metrics.nonPrimePercentage),
    color: `hsl(${Math.random() * 360}, 70%, 50%)`
  }));

  // Mock time trend data for non-prime usage
  const timeTrendData = [
    {
      id: "weekdays",
      data: [
        { x: "00:00", y: 6 },
        { x: "02:00", y: 4 },
        { x: "04:00", y: 2 },
        { x: "06:00", y: 5 },
        { x: "08:00", y: 8 },
        { x: "10:00", y: 12 },
        { x: "12:00", y: 9 },
        { x: "14:00", y: 15 },
        { x: "16:00", y: 18 },
        { x: "18:00", y: 22 },
        { x: "20:00", y: 16 },
        { x: "22:00", y: 10 }
      ]
    },
    {
      id: "weekends",
      data: [
        { x: "00:00", y: 2 },
        { x: "02:00", y: 1 },
        { x: "04:00", y: 0 },
        { x: "06:00", y: 3 },
        { x: "08:00", y: 5 },
        { x: "10:00", y: 8 },
        { x: "12:00", y: 10 },
        { x: "14:00", y: 9 },
        { x: "16:00", y: 8 },
        { x: "18:00", y: 12 },
        { x: "20:00", y: 6 },
        { x: "22:00", y: 4 }
      ]
    }
  ];

  return (
    <div className="animate-fadeIn">
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <MetricCard 
          title="Average Non-Prime" 
          value={`${mockBlockUtilization.overallMetrics.nonPrimePercentage}`}
          icon="clock"
          iconColor="text-blue-500"
        />
        <MetricCard 
          title="Highest Non-Prime" 
          value="General Surgery (17.1%)" 
          icon="arrow-trending-up"
          iconColor="text-amber-500"
        />
        <MetricCard 
          title="Lowest Non-Prime" 
          value="Neurosurgery (8.6%)" 
          icon="arrow-trending-down"
          iconColor="text-emerald-500"
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Panel title="Non-Prime Time Distribution" isSubpanel={true} dropLightIntensity="medium">
          <div className="h-80">
            <ResponsivePie
              data={nonPrimeData}
              margin={{ top: 40, right: 80, bottom: 80, left: 80 }}
              innerRadius={0.5}
              padAngle={0.7}
              cornerRadius={3}
              activeOuterRadiusOffset={8}
              borderWidth={1}
              borderColor={{ from: 'color', modifiers: [['darker', 0.2]] }}
              arcLinkLabelsSkipAngle={10}
              arcLinkLabelsTextColor="var(--color-gray-700)"
              arcLinkLabelsThickness={2}
              arcLinkLabelsColor={{ from: 'color' }}
              arcLabelsSkipAngle={10}
              arcLabelsTextColor={{ from: 'color', modifiers: [['darker', 2]] }}
              legends={[
                {
                  anchor: 'bottom',
                  direction: 'row',
                  justify: false,
                  translateX: 0,
                  translateY: 56,
                  itemsSpacing: 0,
                  itemWidth: 100,
                  itemHeight: 18,
                  itemTextColor: 'var(--color-gray-700)',
                  itemDirection: 'left-to-right',
                  itemOpacity: 1,
                  symbolSize: 18,
                  symbolShape: 'circle',
                  effects: [
                    {
                      on: 'hover',
                      style: {
                        itemTextColor: 'var(--color-gray-900)'
                      }
                    }
                  ]
                }
              ]}
              theme={{
                text: {
                  fill: 'var(--color-gray-700)'
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

        <Panel title="Non-Prime Time Trend" isSubpanel={true} dropLightIntensity="medium">
          <div className="h-80">
            <ResponsiveLine
              data={timeTrendData}
              margin={{ top: 10, right: 30, bottom: 50, left: 60 }}
              xScale={{ type: 'point' }}
              yScale={{ 
                type: 'linear', 
                min: 'auto', 
                max: 'auto', 
                stacked: false, 
                reverse: false 
              }}
              yFormat=" >-.2f"
              curve="monotoneX"
              axisTop={null}
              axisRight={null}
              axisBottom={{
                orient: 'bottom',
                tickSize: 5,
                tickPadding: 5,
                tickRotation: -45,
                legend: 'Time of Day',
                legendOffset: 40,
                legendPosition: 'middle',
                tickTextColor: 'var(--color-gray-700)',
                legendColor: 'var(--color-gray-700)'
              }}
              axisLeft={{
                orient: 'left',
                tickSize: 5,
                tickPadding: 5,
                tickRotation: 0,
                legend: 'Utilization',
                legendOffset: -40,
                legendPosition: 'middle',
                tickTextColor: 'var(--color-gray-700)',
                legendColor: 'var(--color-gray-700)'
              }}
              colors={['#6366f1']}
              pointSize={10}
              pointColor={{ theme: 'background' }}
              pointBorderWidth={2}
              pointBorderColor={{ from: 'serieColor' }}
              pointLabelYOffset={-12}
              useMesh={true}
              legends={[
                {
                  anchor: 'top-right',
                  direction: 'column',
                  justify: false,
                  translateX: 0,
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
                }
              }}
            />
          </div>
        </Panel>
      </div>

      <div className="mt-6">
        <Panel title="Non-Prime Time Analysis" isSubpanel={true} dropLightIntensity="medium">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Panel title="Time of Day Distribution" isSubpanel={true} dropLightIntensity="subtle">
              <div className="h-80">
                <ResponsivePie
                  data={nonPrimeData}
                  margin={{ top: 40, right: 80, bottom: 80, left: 80 }}
                  innerRadius={0.5}
                  padAngle={0.7}
                  cornerRadius={3}
                  activeOuterRadiusOffset={8}
                  borderWidth={1}
                  borderColor={{ from: 'color', modifiers: [['darker', 0.2]] }}
                  arcLinkLabelsSkipAngle={10}
                  arcLinkLabelsTextColor="var(--color-gray-700)"
                  arcLinkLabelsThickness={2}
                  arcLinkLabelsColor={{ from: 'color' }}
                  arcLabelsSkipAngle={10}
                  arcLabelsTextColor={{ from: 'color', modifiers: [['darker', 2]] }}
                  legends={[
                    {
                      anchor: 'bottom',
                      direction: 'row',
                      justify: false,
                      translateX: 0,
                      translateY: 56,
                      itemsSpacing: 0,
                      itemWidth: 100,
                      itemHeight: 18,
                      itemTextColor: 'var(--color-gray-700)',
                      itemDirection: 'left-to-right',
                      itemOpacity: 1,
                      symbolSize: 18,
                      symbolShape: 'circle',
                      effects: [
                        {
                          on: 'hover',
                          style: {
                            itemTextColor: 'var(--color-gray-900)'
                          }
                        }
                      ]
                    }
                  ]}
                  theme={{
                    text: {
                      fill: 'var(--color-gray-700)'
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
            
            <Panel title="Non-Prime Recommendations" isSubpanel={true} dropLightIntensity="subtle">
              <ul className="space-y-3">
                <li className="flex items-start">
                  <div className="flex-shrink-0 h-5 w-5 text-purple-500">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                      <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clipRule="evenodd" />
                    </svg>
                  </div>
                  <p className="ml-2 text-gray-700 dark:text-gray-300">
                    Review Orthopedics case scheduling to identify opportunities for shifting cases to prime time.
                  </p>
                </li>
                <li className="flex items-start">
                  <div className="flex-shrink-0 h-5 w-5 text-purple-500">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                      <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clipRule="evenodd" />
                    </svg>
                  </div>
                  <p className="ml-2 text-gray-700 dark:text-gray-300">
                    Implement Neurosurgery's scheduling practices across other services, particularly Cardiology.
                  </p>
                </li>
                <li className="flex items-start">
                  <div className="flex-shrink-0 h-5 w-5 text-purple-500">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                      <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clipRule="evenodd" />
                    </svg>
                  </div>
                  <p className="ml-2 text-gray-700 dark:text-gray-300">
                    Conduct a detailed analysis of non-prime time usage patterns by day of week for each service.
                  </p>
                </li>
                <li className="flex items-start">
                  <div className="flex-shrink-0 h-5 w-5 text-purple-500">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                      <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clipRule="evenodd" />
                    </svg>
                  </div>
                  <p className="ml-2 text-gray-700 dark:text-gray-300">
                    Set service-specific targets for non-prime time reduction in the next quarter.
                  </p>
                </li>
              </ul>
            </Panel>
          </div>
        </Panel>
      </div>

      <div className="mt-6">
        <Panel title="Service Non-Prime Time Details" isSubpanel={true} dropLightIntensity="medium">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
              <thead className="bg-gray-50 dark:bg-gray-700">
                <tr>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Service
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Non-Prime %
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Prime %
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Trend
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Status
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                {mockBlockUtilization.serviceNonPrime && Object.entries(mockBlockUtilization.serviceNonPrime).map(([service, data], index) => (
                  <tr key={index} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                      {service}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                      {data.nonPrime}%
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                      {data.prime}%
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm">
                      <span className={`inline-flex items-center ${data.trend.includes('+') ? 'text-red-500' : 'text-green-500'}`}>
                        {data.trend.includes('+') ? (
                          <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 15l7-7 7 7"></path>
                          </svg>
                        ) : (
                          <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 9l-7 7-7-7"></path>
                          </svg>
                        )}
                        {data.trend}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm">
                      <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${
                        data.status === 'Improving' ? 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-300' : 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-300'
                      }`}>
                        {data.status}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Panel>
      </div>
    </div>
  );
};

export default NonPrimeView;
