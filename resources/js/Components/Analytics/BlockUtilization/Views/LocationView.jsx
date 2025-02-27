import React from 'react';
import { ResponsiveBar } from '@nivo/bar';
import { mockBlockUtilization } from '@/mock-data/block-utilization';
import { MetricCard, Panel } from '@/Components/ui';

const LocationView = ({ filters }) => {
  // Helper function to parse percentage strings into numeric values
  const parsePercentage = (value) => {
    if (typeof value === 'number') {
      return value;
    } else if (typeof value === 'string') {
      // Extract number from string like "67.8%"
      const match = value.match(/(\d+(\.\d+)?)/);
      return match ? parseFloat(match[0]) : 0;
    }
    return 0;
  };

  // Transform location data and ensure all values are numeric
  const locationData = Object.entries(mockBlockUtilization.sites).map(([name, data]) => ({
    name,
    inBlockUtilization: parsePercentage(data.metrics.inBlockUtilization),
    totalBlockUtilization: parsePercentage(data.metrics.totalBlockUtilization)
  }));

  // Helper function to format percentages for display
  const formatPercentage = (value) => {
    if (value === null || value === undefined || isNaN(value)) {
      return 'N/A';
    }
    if (typeof value === 'number') {
      return `${value.toFixed(1)}%`;
    } else if (typeof value === 'string') {
      // If it's already a string with % sign, just return it
      return value.includes('%') ? value : `${value}%`;
    }
    return 'N/A';
  };

  return (
    <div className="animate-fadeIn">
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <MetricCard 
          title="Total Locations" 
          value={Object.keys(mockBlockUtilization.sites).length.toString()} 
          icon="building-office-2"
          iconColor="text-blue-500"
        />
        <MetricCard 
          title="Highest Utilization" 
          value="VORH JRI OR (71.2%)" 
          icon="arrow-trending-up"
          iconColor="text-emerald-500"
        />
        <MetricCard 
          title="Lowest Utilization" 
          value="MARH OR (67.8%)" 
          icon="arrow-trending-down"
          iconColor="text-amber-500"
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Panel title="Location Utilization" isSubpanel={true} dropLightIntensity="medium">
          <div className="h-80">
            <ResponsiveBar
              data={locationData}
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
                tickRotation: 0,
                legend: 'Location',
                legendPosition: 'middle',
                legendOffset: 32,
                truncateTickAt: 0
              }}
              axisLeft={{
                tickSize: 5,
                tickPadding: 5,
                tickRotation: 0,
                legend: 'Utilization (%)',
                legendPosition: 'middle',
                legendOffset: -40,
                truncateTickAt: 0
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
              role="application"
              ariaLabel="Location utilization chart"
              barAriaLabel={e => e.id + ": " + e.formattedValue + " in location: " + e.indexValue}
              tooltip={({ id, value, color }) => (
                <div
                  style={{
                    padding: 12,
                    background: '#fff',
                    color: '#333',
                    border: '1px solid #ccc',
                    borderRadius: 4,
                  }}
                >
                  <strong>{id}:</strong> {formatPercentage(value)}
                </div>
              )}
            />
          </div>
        </Panel>

        <Panel title="Location Comparison" isSubpanel={true} dropLightIntensity="medium">
          <div className="space-y-4">
            {locationData
              .sort((a, b) => b.inBlockUtilization - a.inBlockUtilization)
              .map((location, index) => (
                <div key={index} className="border-b pb-3 last:border-0">
                  <div className="flex justify-between mb-2">
                    <h3 className="font-medium dark:text-white">{location.name}</h3>
                    <span className="text-blue-600 dark:text-blue-400">{formatPercentage(location.inBlockUtilization)}</span>
                  </div>
                  <div className="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
                    <div 
                      className={`h-2.5 rounded-full ${
                        location.inBlockUtilization > 75 ? 'bg-emerald-500' : 
                        location.inBlockUtilization > 70 ? 'bg-blue-500' : 
                        location.inBlockUtilization > 65 ? 'bg-amber-500' : 'bg-red-500'
                      }`}
                      style={{ width: `${location.inBlockUtilization}%` }}
                    ></div>
                  </div>
                </div>
              ))}
          </div>
        </Panel>
      </div>

      <div className="mt-6">
        <Panel title="Location Insights" isSubpanel={true} dropLightIntensity="medium">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Panel title="Service Distribution by Location" isSubpanel={true} dropLightIntensity="subtle">
              <div className="space-y-4">
                {Object.entries(mockBlockUtilization.sites).map(([siteName, data], index) => (
                  <div key={index} className="border-b pb-3 last:border-0">
                    <h3 className="font-medium mb-2 dark:text-white">{siteName}</h3>
                    <div className="space-y-2">
                      {data.services.slice(0, 3).map((service, sIndex) => (
                        <div key={sIndex} className="flex justify-between items-center text-sm">
                          <span className="text-gray-600 dark:text-gray-300">{service.service_name}</span>
                          <span className="text-blue-600 dark:text-blue-400">{formatPercentage(service.in_block_utilization)}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            </Panel>
            
            <Panel title="Location Recommendations" isSubpanel={true} dropLightIntensity="subtle">
              <ul className="space-y-2">
                <li className="p-2 bg-blue-50 dark:bg-blue-900/20 rounded">
                  <h4 className="font-medium text-blue-800 dark:text-blue-300 mb-1">Cross-Location Standardization</h4>
                  <p className="text-sm text-gray-600 dark:text-gray-300">
                    Implement standardized scheduling practices across all locations to reduce variability in utilization rates.
                  </p>
                </li>
                <li className="p-2 bg-emerald-50 dark:bg-emerald-900/20 rounded">
                  <h4 className="font-medium text-emerald-800 dark:text-emerald-300 mb-1">Resource Allocation</h4>
                  <p className="text-sm text-gray-600 dark:text-gray-300">
                    Analyze staffing models at VORH JRI OR to identify best practices that can be applied to other locations.
                  </p>
                </li>
                <li className="p-2 bg-amber-50 dark:bg-amber-900/20 rounded">
                  <h4 className="font-medium text-amber-800 dark:text-amber-300 mb-1">MARH OR Improvement</h4>
                  <p className="text-sm text-gray-600 dark:text-gray-300">
                    Focus on improving block allocation at MARH OR, which shows the lowest utilization among all locations.
                  </p>
                </li>
              </ul>
            </Panel>
          </div>
        </Panel>
      </div>

      <div className="mt-6">
        <Panel title="Detailed Location Data" isSubpanel={true} dropLightIntensity="medium">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
              <thead className="bg-gray-50 dark:bg-gray-700">
                <tr>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Location
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    In-Block
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Total Block
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Non-Prime
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                    Trend
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                {Object.entries(mockBlockUtilization.sites).map(([name, data], index) => (
                  <tr key={index} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                      {name}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                      {formatPercentage(data.metrics.inBlockUtilization)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                      {formatPercentage(data.metrics.totalBlockUtilization)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                      {formatPercentage(data.metrics.nonPrimePercentage)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                      {data.metrics.utilizationTrend}
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

export default LocationView;
