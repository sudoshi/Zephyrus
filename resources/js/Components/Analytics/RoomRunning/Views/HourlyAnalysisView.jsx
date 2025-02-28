import React, { useMemo } from 'react';
import PropTypes from 'prop-types';
import { mockRoomRunning } from '@/mock-data/room-running';
import { ResponsiveLine } from '@nivo/line';
import Panel from '@/Components/ui/Panel';
import { useDarkMode } from '@/hooks/useDarkMode';

const HourlyAnalysisView = ({ filters }) => {
  // Extract filter values
  const { selectedHospital, selectedLocation, selectedSpecialty, dateRange } = filters;
  const [isDarkMode] = useDarkMode();
  
  // Format weekday vs weekend comparison data
  const weekdayWeekendData = useMemo(() => {
    return [
      {
        id: 'Weekdays',
        data: mockRoomRunning.weekdays.averageRoomsRunning.map(item => ({
          x: item.time,
          y: item.value
        }))
      },
      {
        id: 'Weekend',
        data: mockRoomRunning.weekend.map(item => ({
          x: item.time,
          y: item.value
        }))
      }
    ];
  }, []);
  
  // Format day of week comparison data
  const dayOfWeekData = useMemo(() => {
    return [
      {
        id: 'Monday',
        data: mockRoomRunning.weekdays.Monday.map(item => ({
          x: item.time,
          y: item.value
        }))
      },
      {
        id: 'Tuesday',
        data: mockRoomRunning.weekdays.Tuesday.map(item => ({
          x: item.time,
          y: item.value
        }))
      },
      {
        id: 'Wednesday',
        data: mockRoomRunning.weekdays.Wednesday.map(item => ({
          x: item.time,
          y: item.value
        }))
      },
      {
        id: 'Thursday',
        data: mockRoomRunning.weekdays.Thursday.map(item => ({
          x: item.time,
          y: item.value
        }))
      },
      {
        id: 'Friday',
        data: mockRoomRunning.weekdays.Friday.map(item => ({
          x: item.time,
          y: item.value
        }))
      }
    ];
  }, []);
  
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
      {/* Weekday vs Weekend Comparison */}
      <Panel title="Weekday vs. Weekend Comparison" isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveLine
            data={weekdayWeekendData}
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
      
      {/* Day of Week Comparison */}
      <Panel title="Day of Week Comparison" isSubpanel dropLightIntensity="medium">
        <div className="h-96">
          <ResponsiveLine
            data={dayOfWeekData}
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
            pointSize={4}
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

HourlyAnalysisView.propTypes = {
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

export default HourlyAnalysisView;
