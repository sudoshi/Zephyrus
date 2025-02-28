import React, { useMemo } from 'react';
import PropTypes from 'prop-types';
import { mockTurnoverTimes } from '@/mock-data/turnover-times';
import { ResponsiveBar } from '@nivo/bar';
import { ResponsiveLine } from '@nivo/line';
import Panel from '@/Components/ui/Panel';
import { useDarkMode } from '@/hooks/useDarkMode';

const HourlyAnalysisView = ({ filters }) => {
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
  
  // Generate hourly data (mock data for this example)
  const hourlyData = useMemo(() => {
    // Generate synthetic hourly data
    const hours = [];
    for (let i = 6; i <= 22; i++) {
      const hour = i < 10 ? `0${i}:00` : `${i}:00`;
      const baseValue = 30 + Math.random() * 10;
      
      hours.push({
        hour,
        'Weekday': baseValue,
        'Weekend': baseValue + (Math.random() * 15 - 5)
      });
    }
    return hours;
  }, []);
  
  // Generate day of week data
  const dayOfWeekData = useMemo(() => {
    // Generate synthetic day of week data
    const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    return days.map(day => {
      const baseValue = 30 + Math.random() * 10;
      const peakHourValue = baseValue * (1 + Math.random() * 0.2);
      
      return {
        day,
        'Average Turnover': baseValue,
        'Peak Hour Turnover': peakHourValue
      };
    });
  }, []);
  
  // Generate hourly trend data for line chart
  const hourlyTrendData = useMemo(() => {
    // Generate synthetic hourly trend data
    const hours = [];
    for (let i = 6; i <= 22; i++) {
      const hour = i < 10 ? `0${i}:00` : `${i}:00`;
      hours.push({
        x: hour,
        y: 30 + Math.random() * 15
      });
    }
    
    return [
      {
        id: 'Turnover Time',
        data: hours
      }
    ];
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
    crosshair: {
      line: {
        stroke: isDarkMode ? '#e5e7eb' : '#374151'
      }
    }
  };

  return (
    <div className="space-y-6">
      {/* Weekday vs Weekend Comparison */}
      <Panel title="Weekday vs Weekend Comparison - Turnover Times" isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveBar
            data={hourlyData}
            keys={['Weekday', 'Weekend']}
            indexBy="hour"
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
              legend: 'Hour of Day',
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
      
      {/* Day of Week Analysis */}
      <Panel title="Day of Week Analysis - Turnover Times" isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveBar
            data={dayOfWeekData}
            keys={['Average Turnover', 'Peak Hour Turnover']}
            indexBy="day"
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
              legend: 'Day of Week',
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
      
      {/* Hourly Trend Analysis */}
      <Panel title="Hourly Trend Analysis - Turnover Times" isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveLine
            data={hourlyTrendData}
            margin={{ top: 20, right: 20, bottom: 50, left: 60 }}
            xScale={{ type: 'point' }}
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
              tickSize: 5,
              tickPadding: 5,
              tickRotation: -45,
              legend: 'Hour of Day',
              legendOffset: 36,
              legendPosition: 'middle'
            }}
            axisLeft={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: 'Time (minutes)',
              legendOffset: -40,
              legendPosition: 'middle'
            }}
            pointSize={10}
            pointColor={{ theme: 'background' }}
            pointBorderWidth={2}
            pointBorderColor={{ from: 'serieColor' }}
            pointLabelYOffset={-12}
            useMesh={true}
            theme={theme}
            curve="monotoneX"
          />
        </div>
      </Panel>
      
      {/* Peak Hours Analysis */}
      <Panel title="Peak Hours Analysis" isSubpanel dropLightIntensity="medium">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead className="bg-gray-50 dark:bg-gray-800">
              <tr>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Hour Range
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Average Turnover (min)
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Number of Turnovers
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Efficiency Score
                </th>
              </tr>
            </thead>
            <tbody className="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
              {[
                { range: '06:00 - 08:00', avg: 28.5, count: 125, score: '85%' },
                { range: '08:00 - 10:00', avg: 32.7, count: 245, score: '78%' },
                { range: '10:00 - 12:00', avg: 35.2, count: 278, score: '75%' },
                { range: '12:00 - 14:00', avg: 38.5, count: 210, score: '70%' },
                { range: '14:00 - 16:00', avg: 33.8, count: 198, score: '77%' },
                { range: '16:00 - 18:00', avg: 30.2, count: 156, score: '82%' },
                { range: '18:00 - 20:00', avg: 27.5, count: 98, score: '87%' },
                { range: '20:00 - 22:00', avg: 25.8, count: 45, score: '90%' }
              ].map((hour, index) => (
                <tr key={hour.range} className={index % 2 === 0 ? 'bg-white dark:bg-gray-900' : 'bg-gray-50 dark:bg-gray-800'}>
                  <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                    {hour.range}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                    {hour.avg}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                    {hour.count}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                    {hour.score}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
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
