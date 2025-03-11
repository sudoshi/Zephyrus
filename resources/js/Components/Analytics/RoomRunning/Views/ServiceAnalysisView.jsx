import React, { useMemo } from 'react';
import PropTypes from 'prop-types';
import { mockRoomRunning } from '@/mock-data/room-running';
import { ResponsiveBar } from '@nivo/bar';
import { ResponsivePie } from '@nivo/pie';
import Panel from '@/Components/ui/Panel';
import { useDarkMode } from '@/hooks/useDarkMode.js';

const ServiceAnalysisView = ({ filters }) => {
  // Extract filter values
  const { selectedHospital, selectedLocation, selectedSpecialty, dateRange } = filters;
  const [isDarkMode] = useDarkMode();
  
  // Format service distribution data
  const serviceDistributionData = useMemo(() => {
    return Object.entries(mockRoomRunning.services).map(([service, data]) => ({
      id: service,
      label: service,
      value: data.averageRoomsRunning
    }));
  }, []);
  
  // Format service comparison data
  const serviceComparisonData = useMemo(() => {
    return Object.entries(mockRoomRunning.services).map(([service, data]) => ({
      service,
      'Avg. Rooms Running': data.averageRoomsRunning,
      'Utilization Rate': data.utilizationRate,
      'Avg. Case Duration': data.averageCaseDuration
    }));
  }, []);
  
  // Format service by hour data
  const serviceByHourData = useMemo(() => {
    // Get top 5 services by rooms running
    const topServices = Object.entries(mockRoomRunning.services)
      .sort((a, b) => b[1].averageRoomsRunning - a[1].averageRoomsRunning)
      .slice(0, 5)
      .map(([service]) => service);
    
    // Format data for line chart
    return topServices.map(service => {
      const serviceData = mockRoomRunning.services[service];
      return {
        id: service,
        data: Object.entries(serviceData.roomsRunningByHour).map(([hour, value]) => ({
          x: hour,
          y: value
        }))
      };
    });
  }, []);
  
  // Chart theme based on dark mode
  const theme = {
    axis: {
      ticks: {
        text: {
          fill: isDarkMode ? '#e5e7eb' : '#374151'
        }
      },
      legend: {
        text: {
          fill: isDarkMode ? '#e5e7eb' : '#374151'
        }
      }
    },
    grid: {
      line: {
        stroke: isDarkMode ? '#374151' : '#e5e7eb'
      }
    },
    tooltip: {
      container: {
        background: isDarkMode ? '#1f2937' : '#ffffff',
        color: isDarkMode ? '#e5e7eb' : '#374151'
      }
    },
    labels: {
      text: {
        fill: isDarkMode ? '#e5e7eb' : '#374151'
      }
    }
  };

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Service Distribution */}
        <Panel title="Service Distribution - Rooms Running" isSubpanel dropLightIntensity="medium">
          <div className="h-80">
            <ResponsivePie
              data={serviceDistributionData}
              margin={{ top: 40, right: 80, bottom: 80, left: 80 }}
              innerRadius={0.5}
              padAngle={0.7}
              cornerRadius={3}
              activeOuterRadiusOffset={8}
              borderWidth={1}
              borderColor={{ from: 'color', modifiers: [['darker', 0.2]] }}
              arcLinkLabelsSkipAngle={10}
              arcLinkLabelsTextColor={isDarkMode ? '#e5e7eb' : '#374151'}
              arcLinkLabelsThickness={2}
              arcLinkLabelsColor={{ from: 'color' }}
              arcLabelsSkipAngle={10}
              arcLabelsTextColor={{ from: 'color', modifiers: [['darker', 2]] }}
              colors={{ scheme: 'category10' }}
              theme={theme}
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
                  itemTextColor: isDarkMode ? '#e5e7eb' : '#374151',
                  itemDirection: 'left-to-right',
                  itemOpacity: 1,
                  symbolSize: 18,
                  symbolShape: 'circle',
                  effects: [
                    {
                      on: 'hover',
                      style: {
                        itemTextColor: isDarkMode ? '#ffffff' : '#000000'
                      }
                    }
                  ]
                }
              ]}
            />
          </div>
        </Panel>
        
        {/* Service Comparison - Utilization Rate */}
        <Panel title="Service Comparison - Utilization Rate" isSubpanel dropLightIntensity="medium">
          <div className="h-80">
            <ResponsiveBar
              data={serviceComparisonData}
              keys={['Utilization Rate']}
              indexBy="service"
              margin={{ top: 20, right: 20, bottom: 50, left: 60 }}
              padding={0.3}
              valueScale={{ type: 'linear' }}
              indexScale={{ type: 'band', round: true }}
              colors={{ scheme: 'category10' }}
              borderColor={{ from: 'color', modifiers: [['darker', 1.6]] }}
              axisTop={null}
              axisRight={null}
              axisBottom={{
                tickSize: 5,
                tickPadding: 5,
                tickRotation: -45,
                legend: 'Service',
                legendPosition: 'middle',
                legendOffset: 40
              }}
              axisLeft={{
                tickSize: 5,
                tickPadding: 5,
                tickRotation: 0,
                legend: 'Utilization Rate (%)',
                legendPosition: 'middle',
                legendOffset: -40
              }}
              labelSkipWidth={12}
              labelSkipHeight={12}
              labelTextColor={{ from: 'color', modifiers: [['darker', 1.6]] }}
              theme={theme}
            />
          </div>
        </Panel>
      </div>
      
      {/* Service Comparison - Average Case Duration */}
      <Panel title="Service Comparison - Average Case Duration" isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveBar
            data={serviceComparisonData}
            keys={['Avg. Case Duration']}
            indexBy="service"
            margin={{ top: 20, right: 20, bottom: 50, left: 60 }}
            padding={0.3}
            valueScale={{ type: 'linear' }}
            indexScale={{ type: 'band', round: true }}
            colors={{ scheme: 'category10' }}
            borderColor={{ from: 'color', modifiers: [['darker', 1.6]] }}
            axisTop={null}
            axisRight={null}
            axisBottom={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: -45,
              legend: 'Service',
              legendPosition: 'middle',
              legendOffset: 40
            }}
            axisLeft={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: 'Average Case Duration (min)',
              legendPosition: 'middle',
              legendOffset: -40
            }}
            labelSkipWidth={12}
            labelSkipHeight={12}
            labelTextColor={{ from: 'color', modifiers: [['darker', 1.6]] }}
            theme={theme}
          />
        </div>
      </Panel>
      
      {/* Service Comparison - Top Services by Hour */}
      <Panel title="Top Services - Rooms Running by Hour" isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveBar
            data={Object.entries(mockRoomRunning.services).map(([service, data]) => ({
              service,
              'Avg. Rooms Running': data.averageRoomsRunning
            }))}
            keys={['Avg. Rooms Running']}
            indexBy="service"
            margin={{ top: 20, right: 20, bottom: 50, left: 60 }}
            padding={0.3}
            valueScale={{ type: 'linear' }}
            indexScale={{ type: 'band', round: true }}
            colors={{ scheme: 'category10' }}
            borderColor={{ from: 'color', modifiers: [['darker', 1.6]] }}
            axisTop={null}
            axisRight={null}
            axisBottom={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: -45,
              legend: 'Service',
              legendPosition: 'middle',
              legendOffset: 40
            }}
            axisLeft={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: 'Avg. Rooms Running',
              legendPosition: 'middle',
              legendOffset: -40
            }}
            labelSkipWidth={12}
            labelSkipHeight={12}
            labelTextColor={{ from: 'color', modifiers: [['darker', 1.6]] }}
            theme={theme}
          />
        </div>
      </Panel>
    </div>
  );
};

ServiceAnalysisView.propTypes = {
  filters: PropTypes.shape({
    selectedHospital: PropTypes.string,
    selectedLocation: PropTypes.string,
    selectedSpecialty: PropTypes.string,
    selectedSurgeon: PropTypes.string,
    dateRange: PropTypes.shape({
      startDate: PropTypes.instanceOf(Date),
      endDate: PropTypes.instanceOf(Date)
    }),
    showComparison: PropTypes.bool,
    comparisonDateRange: PropTypes.shape({
      startDate: PropTypes.instanceOf(Date),
      endDate: PropTypes.instanceOf(Date)
    })
  }).isRequired
};

export default ServiceAnalysisView;
