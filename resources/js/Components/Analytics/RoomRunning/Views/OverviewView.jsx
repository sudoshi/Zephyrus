import React, { useMemo } from 'react';
import PropTypes from 'prop-types';
import { mockRoomRunning } from '@/mock-data/room-running';
import { ResponsiveLine } from '@nivo/line';
import Panel from '@/Components/ui/Panel';
import { useDarkMode } from '@/hooks/useDarkMode';

const OverviewView = ({ filters }) => {
  // Extract filter values
  const { selectedHospital, selectedLocation, selectedSpecialty, dateRange } = filters;
  const [isDarkMode] = useDarkMode();
  
  // Get location data based on filters
  const locationData = useMemo(() => {
    // Default to first location if none selected
    const locationKey = selectedLocation || Object.keys(mockRoomRunning.sites)[0];
    return mockRoomRunning.sites[locationKey] || mockRoomRunning.sites[Object.keys(mockRoomRunning.sites)[0]];
  }, [selectedLocation]);
  
  // Get day data based on filters
  const dayData = useMemo(() => {
    // Default to weekdays if no specific day is selected
    return mockRoomRunning.weekdays.averageRoomsRunning;
  }, []);
  
  // Format rooms running by hour data for line chart
  const roomsRunningByHourData = useMemo(() => {
    return [
      {
        id: 'Rooms Running',
        data: dayData.map(item => ({
          x: item.time,
          y: item.value
        }))
      }
    ];
  }, [dayData]);
  
  // Format room utilization by hour data
  const roomUtilizationByHourData = useMemo(() => {
    return [
      {
        id: 'Rooms Running',
        data: Object.entries(locationData.roomsRunningByHour).map(([hour, value]) => ({
          x: hour,
          y: value
        }))
      },
      {
        id: 'Utilization %',
        data: Object.entries(locationData.roomsRunningByHour).map(([hour, value]) => ({
          x: hour,
          y: (value / locationData.totalRooms) * 100
        }))
      }
    ];
  }, [locationData]);
  
  // Line chart theme based on dark mode
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
    }
  };

  return (
    <div className="space-y-6">
      {/* Summary Metrics */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <Panel title="Avg. Rooms Running" isSubpanel dropLightIntensity="medium">
          <div className="text-2xl font-bold">{locationData.averageRoomsRunning}</div>
          <p className="text-xs text-muted-foreground">Out of {locationData.totalRooms} total rooms</p>
        </Panel>
        
        <Panel title="Utilization Rate" isSubpanel dropLightIntensity="medium">
          <div className="text-2xl font-bold">{locationData.utilizationRate}%</div>
          <p className="text-xs text-muted-foreground">Overall room utilization</p>
        </Panel>
        
        <Panel title="Total Cases" isSubpanel dropLightIntensity="medium">
          <div className="text-2xl font-bold">{locationData.totalCases}</div>
          <p className="text-xs text-muted-foreground">Cases performed in selected period</p>
        </Panel>
        
        <Panel title="Avg. Case Duration" isSubpanel dropLightIntensity="medium">
          <div className="text-2xl font-bold">{locationData.averageCaseDuration} min</div>
          <p className="text-xs text-muted-foreground">Average case duration</p>
        </Panel>
      </div>
      
      {/* Rooms Running by Hour */}
      <Panel title="Rooms Running by Hour - Weekdays" isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveLine
            data={roomsRunningByHourData}
            margin={{ top: 20, right: 20, bottom: 50, left: 60 }}
            xScale={{ type: 'point' }}
            yScale={{ 
              type: 'linear', 
              min: 0, 
              max: 'auto' 
            }}
            axisTop={null}
            axisRight={null}
            axisBottom={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: 'Time',
              legendOffset: 36,
              legendPosition: 'middle'
            }}
            axisLeft={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: 'Rooms Running',
              legendOffset: -40,
              legendPosition: 'middle'
            }}
            colors={{ scheme: 'category10' }}
            pointSize={10}
            pointColor={{ theme: 'background' }}
            pointBorderWidth={2}
            pointBorderColor={{ from: 'serieColor' }}
            pointLabelYOffset={-12}
            useMesh={true}
            enableArea={true}
            areaOpacity={0.3}
            theme={theme}
            legends={[
              {
                anchor: 'bottom-right',
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
          />
        </div>
      </Panel>
      
      {/* Room Utilization by Hour */}
      <Panel title={`Room Utilization by Hour - ${selectedLocation || Object.keys(mockRoomRunning.sites)[0]}`} isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveLine
            data={roomUtilizationByHourData}
            margin={{ top: 20, right: 110, bottom: 50, left: 60 }}
            xScale={{ type: 'point' }}
            yScale={{ 
              type: 'linear', 
              min: 0, 
              max: 'auto' 
            }}
            axisTop={null}
            axisRight={null}
            axisBottom={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: 'Hour',
              legendOffset: 36,
              legendPosition: 'middle'
            }}
            axisLeft={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: 'Rooms Running / Utilization (%)',
              legendOffset: -40,
              legendPosition: 'middle'
            }}
            colors={{ scheme: 'category10' }}
            pointSize={10}
            pointColor={{ theme: 'background' }}
            pointBorderWidth={2}
            pointBorderColor={{ from: 'serieColor' }}
            pointLabelYOffset={-12}
            useMesh={true}
            theme={theme}
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
          />
        </div>
      </Panel>
    </div>
  );
};

OverviewView.propTypes = {
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

export default OverviewView;
