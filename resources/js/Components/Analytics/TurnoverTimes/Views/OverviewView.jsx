import React, { useMemo } from 'react';
import PropTypes from 'prop-types';
import { mockTurnoverTimes } from '@/mock-data/turnover-times';
import { ResponsivePie } from '@nivo/pie';
import { ResponsiveBar } from '@nivo/bar';
import Panel from '@/Components/ui/Panel';
import  { useDarkMode } from '@/hooks/useDarkMode.js';

const OverviewView = ({ filters }) => {
  // Extract filter values
  const { selectedHospital, selectedLocation, selectedSpecialty, dateRange } = filters;
  const [isDarkMode] = useDarkMode();
  
  // Get location data based on filters
  const locationData = useMemo(() => {
    if (selectedLocation && mockTurnoverTimes.sites[selectedLocation]) {
      return mockTurnoverTimes.sites[selectedLocation];
    } else if (selectedHospital) {
      // Get first location for the selected hospital
      const locationKey = Object.keys(mockTurnoverTimes.sites).find(
        site => site.startsWith(selectedHospital)
      );
      return locationKey ? mockTurnoverTimes.sites[locationKey] : mockTurnoverTimes.sites['MARH OR'];
    } else {
      // Default to first location
      return mockTurnoverTimes.sites['MARH OR'];
    }
  }, [selectedHospital, selectedLocation]);
  
  // Format turnover distribution data for pie chart
  const distributionData = useMemo(() => {
    return Object.entries(locationData.turnoverDistribution).map(([range, count]) => ({
      id: range,
      label: range,
      value: count
    }));
  }, [locationData]);
  
  // Format room comparison data for bar chart
  const roomComparisonData = useMemo(() => {
    return locationData.rooms.map(room => ({
      room: room.room,
      'Median Turnover': room.medianTurnoverTime,
      'Average Turnover': room.averageTurnoverTime
    }));
  }, [locationData]);
  
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
      {/* Summary Metrics */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <Panel title="Median Turnover Time" isSubpanel dropLightIntensity="medium">
          <div className="text-3xl font-bold">{locationData.medianTurnoverTime} min</div>
          <p className="text-sm text-gray-500 dark:text-gray-400">Median time between cases</p>
        </Panel>
        
        <Panel title="Average Turnover Time" isSubpanel dropLightIntensity="medium">
          <div className="text-3xl font-bold">{locationData.averageTurnoverTime} min</div>
          <p className="text-sm text-gray-500 dark:text-gray-400">Average time between cases</p>
        </Panel>
        
        <Panel title="Total Cases" isSubpanel dropLightIntensity="medium">
          <div className="text-3xl font-bold">{locationData.totalCases}</div>
          <p className="text-sm text-gray-500 dark:text-gray-400">Cases in selected period</p>
        </Panel>
        
        <Panel title="Total Turnovers" isSubpanel dropLightIntensity="medium">
          <div className="text-3xl font-bold">{locationData.totalTurnovers}</div>
          <p className="text-sm text-gray-500 dark:text-gray-400">Turnovers in selected period</p>
        </Panel>
      </div>
      
      {/* Turnover Distribution */}
      <Panel title="Turnover Time Distribution" isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsivePie
            data={distributionData}
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
      
      {/* Room Comparison */}
      <Panel title="Room Comparison - Turnover Times" isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveBar
            data={roomComparisonData}
            keys={['Median Turnover', 'Average Turnover']}
            indexBy="room"
            margin={{ top: 20, right: 130, bottom: 50, left: 60 }}
            padding={0.3}
            groupMode="grouped"
            valueScale={{ type: 'linear' }}
            indexScale={{ type: 'band', round: true }}
            colors={{ scheme: 'paired' }}
            borderColor={{ from: 'color', modifiers: [['darker', 1.6]] }}
            axisTop={null}
            axisRight={null}
            axisBottom={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: -45,
              legend: 'Room',
              legendPosition: 'middle',
              legendOffset: 40
            }}
            axisLeft={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: 'Time (minutes)',
              legendPosition: 'middle',
              legendOffset: -40
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
            theme={theme}
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
