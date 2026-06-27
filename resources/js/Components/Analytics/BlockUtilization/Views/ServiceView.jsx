import React, { useMemo } from 'react';
import { mockBlockUtilization } from '@/mock-data/block-utilization';
import { ResponsiveBar } from '@nivo/bar';
import MetricCard from '@/Components/ui/MetricCard';
import Panel from '@/Components/ui/Panel';

const ServiceView = ({ filters, data = mockBlockUtilization }) => {
  // Extract filter values from the new filter structure
  const { selectedHospital, selectedLocation, selectedSpecialty } = filters;
  
  // Filter data based on hierarchical filters
  const filteredData = useMemo(() => {
    let filteredServiceData = [...data.serviceData];
    
    // Filter by hospital if selected
    if (selectedHospital) {
      filteredServiceData = filteredServiceData.filter(service => 
        service.sites.some(site => site.includes(selectedHospital))
      );
    }
    
    // Filter by location if selected
    if (selectedLocation) {
      filteredServiceData = filteredServiceData.filter(service => 
        service.sites.includes(selectedLocation)
      );
    }
    
    // Filter by specialty if selected
    if (selectedSpecialty) {
      filteredServiceData = filteredServiceData.filter(service => 
        service.name === selectedSpecialty
      );
    }
    
    return filteredServiceData;
  }, [selectedHospital, selectedLocation, selectedSpecialty]);

  // Transform filtered data into the format needed for the chart
  const chartData = filteredData.map(service => ({
    name: service.name,
    inBlockUtilization: service.metrics.inBlockUtilization,
    totalBlockUtilization: service.metrics.totalBlockUtilization,
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

  // Get metrics based on filtered data
  const getFilteredMetrics = () => {
    if (filteredData.length === 0) {
      return data.overallMetrics;
    }
    
    // Calculate average metrics from filtered services
    const inBlockSum = filteredData.reduce((sum, service) => sum + parseFloat(service.metrics.inBlockUtilization), 0);
    const totalBlockSum = filteredData.reduce((sum, service) => sum + parseFloat(service.metrics.totalBlockUtilization), 0);
    const nonPrimeSum = filteredData.reduce((sum, service) => sum + parseFloat(service.metrics.nonPrimePercentage), 0);
    
    return {
      inBlockUtilization: inBlockSum / filteredData.length,
      totalBlockUtilization: totalBlockSum / filteredData.length,
      nonPrimePercentage: nonPrimeSum / filteredData.length
    };
  };
  
  const metrics = getFilteredMetrics();

  return (
    <div className="animate-fadeIn">
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <MetricCard 
          title="In-Block Utilization" 
          value={formatPercentage(metrics.inBlockUtilization)} 
          trend="+2.3%" 
          trendDirection="up"
          icon="chart-bar"
          iconColor="text-blue-500"
          isSubpanel={true}
        />
        <MetricCard 
          title="Total Block Utilization" 
          value={formatPercentage(metrics.totalBlockUtilization)} 
          trend="+1.8%" 
          trendDirection="up"
          icon="chart-pie"
          iconColor="text-emerald-500"
          isSubpanel={true}
        />
        <MetricCard 
          title="Non-Prime Time" 
          value={formatPercentage(metrics.nonPrimePercentage)} 
          trend="-0.7%" 
          trendDirection="down"
          icon="clock"
          iconColor="text-purple-500"
          isSubpanel={true}
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Panel title="Service Utilization" dropLightIntensity="medium">
          <div className="h-80">
            <ResponsiveBar
              data={chartData}
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
                legend: 'Service',
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
              ariaLabel="Service utilization chart"
              barAriaLabel={e => e.id + ": " + e.formattedValue + " in service: " + e.indexValue}
            />
          </div>
        </Panel>

        <Panel title="Service Details" dropLightIntensity="medium">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
              <thead className="bg-healthcare-background dark:bg-healthcare-background-dark">
                <tr>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                    Service
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                    In-Block
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                    Total Block
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                    Non-Prime
                  </th>
                </tr>
              </thead>
              <tbody className="bg-healthcare-surface dark:bg-healthcare-surface-dark divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                {filteredData.map((service, index) => (
                  <tr key={index} className="hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark">
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-healthcare-text-primary dark:text-white">
                      {service.name}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      <span className="font-semibold">In-Block Utilization:</span> 
                      {formatPercentage(service.metrics.inBlockUtilization)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      <span className="font-semibold">Total Block Utilization:</span> 
                      {formatPercentage(service.metrics.totalBlockUtilization)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      <span className="font-semibold">Non-Prime Time:</span> 
                      {formatPercentage(service.metrics.nonPrimePercentage)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Panel>
      </div>

      {/* Service Insights Section */}
      <div className="mt-6">
        <Panel title="Service Insights" dropLightIntensity="medium">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Panel title="Top Performing Services" isSubpanel={true} dropLightIntensity="medium">
              <ul className="space-y-2">
                <li className="flex justify-between items-center p-2 bg-healthcare-info/10 dark:bg-healthcare-info-dark/20 rounded">
                  <span className="font-medium dark:text-white">Orthopedics</span>
                  <span className="text-healthcare-info dark:text-healthcare-info-dark">82.3%</span>
                </li>
                <li className="flex justify-between items-center p-2 bg-healthcare-info/10 dark:bg-healthcare-info-dark/20 rounded">
                  <span className="font-medium dark:text-white">Neurosurgery</span>
                  <span className="text-healthcare-info dark:text-healthcare-info-dark">78.9%</span>
                </li>
                <li className="flex justify-between items-center p-2 bg-healthcare-info/10 dark:bg-healthcare-info-dark/20 rounded">
                  <span className="font-medium dark:text-white">Cardiology</span>
                  <span className="text-healthcare-info dark:text-healthcare-info-dark">76.4%</span>
                </li>
              </ul>
            </Panel>
            
            <Panel title="Improvement Opportunities" isSubpanel={true} dropLightIntensity="medium">
              <ul className="space-y-2">
                <li className="flex justify-between items-center p-2 bg-healthcare-critical/10 dark:bg-healthcare-critical-dark/20 rounded">
                  <span className="font-medium dark:text-white">General Surgery</span>
                  <span className="text-healthcare-critical dark:text-healthcare-critical-dark">62.1%</span>
                </li>
                <li className="flex justify-between items-center p-2 bg-healthcare-critical/10 dark:bg-healthcare-critical-dark/20 rounded">
                  <span className="font-medium dark:text-white">Urology</span>
                  <span className="text-healthcare-critical dark:text-healthcare-critical-dark">64.5%</span>
                </li>
                <li className="flex justify-between items-center p-2 bg-healthcare-critical/10 dark:bg-healthcare-critical-dark/20 rounded">
                  <span className="font-medium dark:text-white">Gynecology</span>
                  <span className="text-healthcare-critical dark:text-healthcare-critical-dark">65.8%</span>
                </li>
              </ul>
            </Panel>
          </div>
        </Panel>
      </div>
    </div>
  );
};

export default ServiceView;
