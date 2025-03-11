import React, { useMemo } from 'react';
import PropTypes from 'prop-types';
import { mockRoomRunning } from '@/mock-data/room-running';
import { ResponsiveBar } from '@nivo/bar';
import Panel from '@/Components/ui/Panel';
import { useDarkMode } from '@/hooks/useDarkMode.js';

const LocationComparisonView = ({ filters }) => {
  // Extract filter values
  const { selectedHospital, selectedLocation, selectedSpecialty, dateRange } = filters;
  const [isDarkMode] = useDarkMode();
  
  // Format location comparison data
  const locationComparisonData = useMemo(() => {
    // Get all locations
    const locations = Object.keys(mockRoomRunning.sites);
    
    // Filter by hospital if selected
    const filteredLocations = selectedHospital 
      ? locations.filter(loc => loc.startsWith(selectedHospital.toUpperCase()))
      : locations;
    
    // Create data for bar chart
    return filteredLocations.map(location => {
      const siteData = mockRoomRunning.sites[location];
      return {
        location,
        'Avg. Rooms Running': siteData.averageRoomsRunning,
        'Utilization Rate': siteData.utilizationRate,
        'Total Rooms': siteData.totalRooms
      };
    });
  }, [selectedHospital]);
  
  // Format peak hours comparison data
  const peakHoursData = useMemo(() => {
    // Get all locations
    const locations = Object.keys(mockRoomRunning.sites);
    
    // Filter by hospital if selected
    const filteredLocations = selectedHospital 
      ? locations.filter(loc => loc.startsWith(selectedHospital.toUpperCase()))
      : locations;
    
    // Get peak hour data (hour with highest rooms running)
    return filteredLocations.map(location => {
      const siteData = mockRoomRunning.sites[location];
      const hourlyData = siteData.roomsRunningByHour;
      const peakHour = Object.entries(hourlyData).reduce(
        (max, [hour, value]) => (value > max.value ? { hour, value } : max),
        { hour: '0', value: 0 }
      );
      
      return {
        location,
        'Peak Hour': peakHour.hour,
        'Peak Rooms Running': peakHour.value,
        'Peak Utilization': (peakHour.value / siteData.totalRooms) * 100
      };
    });
  }, [selectedHospital]);
  
  // Bar chart theme based on dark mode
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
      <Panel title="Location Comparison - Average Rooms Running" isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveBar
            data={locationComparisonData}
            keys={['Avg. Rooms Running']}
            indexBy="location"
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
              legend: 'Location',
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
      
      <Panel title="Location Comparison - Utilization Rate" isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveBar
            data={locationComparisonData}
            keys={['Utilization Rate']}
            indexBy="location"
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
              legend: 'Location',
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
      
      <Panel title="Peak Hours by Location" isSubpanel dropLightIntensity="medium">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead className="bg-gray-50 dark:bg-gray-800">
              <tr>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Location
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Peak Hour
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Peak Rooms Running
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Peak Utilization (%)
                </th>
              </tr>
            </thead>
            <tbody className="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
              {peakHoursData.map((row, index) => (
                <tr key={index} className={index % 2 === 0 ? 'bg-white dark:bg-gray-900' : 'bg-gray-50 dark:bg-gray-800'}>
                  <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                    {row.location}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                    {row['Peak Hour']}:00
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                    {row['Peak Rooms Running']}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                    {row['Peak Utilization'].toFixed(1)}%
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

LocationComparisonView.propTypes = {
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

export default LocationComparisonView;
