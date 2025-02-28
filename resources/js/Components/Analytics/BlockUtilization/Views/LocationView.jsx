import React, { useMemo } from 'react';
import { ResponsiveBar } from '@nivo/bar';
import { mockBlockUtilization } from '@/mock-data/block-utilization';
import { MetricCard, Panel } from '@/Components/ui';

const LocationView = ({ filters }) => {
  // Extract filter values from the new filter structure
  const { selectedHospital, selectedLocation, selectedSpecialty, dateRange } = filters;
  
  // Filter data based on hierarchical filters
  const filteredData = useMemo(() => {
    let filteredLocationData = [...mockBlockUtilization.locationData];
    
    // Filter by hospital if selected
    if (selectedHospital) {
      filteredLocationData = filteredLocationData.filter(location => 
        location.hospital === selectedHospital
      );
    }
    
    // Filter by specialty if selected
    if (selectedSpecialty) {
      filteredLocationData = filteredLocationData.filter(location => 
        location.specialties && location.specialties.includes(selectedSpecialty)
      );
    }
    
    return filteredLocationData;
  }, [selectedHospital, selectedSpecialty, dateRange]);

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
        totalLocations: 0,
        averageUtilization: 0,
        highestLocation: { name: 'N/A', utilization: 0 }
      };
    }
    
    const totalLocations = filteredData.length;
    const totalUtilization = filteredData.reduce((sum, location) => sum + (location.utilization || 0), 0);
    const averageUtilization = totalUtilization / totalLocations;
    
    // Find location with highest utilization
    const highestLocation = filteredData.reduce((highest, current) => 
      (current.utilization > highest.utilization) ? current : highest
    , { name: 'N/A', utilization: 0 });
    
    return {
      totalLocations,
      averageUtilization,
      highestLocation
    };
  };
  
  const metrics = calculateMetrics();

  // Prepare data for the bar chart
  const barChartData = filteredData.map(location => ({
    location: location.name,
    utilization: location.utilization
  }));

  return (
    <div className="animate-fadeIn">
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <MetricCard 
          title="Total Locations" 
          value={metrics.totalLocations.toString()} 
          icon="map-marker"
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
          title="Highest Location" 
          value={`${metrics.highestLocation.name} (${formatPercentage(metrics.highestLocation.utilization)})`} 
          icon="arrow-up-right"
          iconColor="text-purple-500"
          isSubpanel={true}
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Panel title="Location Utilization" isSubpanel={true} dropLightIntensity="medium">
          <div className="h-80">
            <ResponsiveBar
              data={barChartData}
              keys={['utilization']}
              indexBy="location"
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
            {filteredData
              .sort((a, b) => b.utilization - a.utilization)
              .map((location, index) => (
                <div key={index} className="border-b pb-3 last:border-0">
                  <div className="flex justify-between mb-2">
                    <h3 className="font-medium dark:text-white">{location.name}</h3>
                    <span className="text-blue-600 dark:text-blue-400">{formatPercentage(location.utilization)}</span>
                  </div>
                  <div className="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
                    <div 
                      className={`h-2.5 rounded-full ${
                        location.utilization > 75 ? 'bg-emerald-500' : 
                        location.utilization > 70 ? 'bg-blue-500' : 
                        location.utilization > 65 ? 'bg-amber-500' : 'bg-red-500'
                      }`}
                      style={{ width: `${location.utilization}%` }}
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
                {filteredData.map((location, index) => (
                  <div key={index} className="border-b pb-3 last:border-0">
                    <h3 className="font-medium mb-2 dark:text-white">{location.name}</h3>
                    <div className="space-y-2">
                      {location.services.slice(0, 3).map((service, sIndex) => (
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
                {filteredData.map((location, index) => (
                  <tr key={index} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                      {location.name}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                      {formatPercentage(location.utilization)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                      {formatPercentage(location.totalBlockUtilization)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                      {formatPercentage(location.nonPrimePercentage)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                      {location.utilizationTrend}
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
