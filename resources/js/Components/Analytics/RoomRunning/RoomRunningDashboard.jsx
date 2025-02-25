import React from 'react';
import { Card, Tabs } from '@/Components/ui/flowbite';
import { BarChart, LineChart } from '@/Components/ui/charts';
import { useAnalytics } from '@/contexts/AnalyticsContext';
import useAnalyticsData from '@/hooks/useAnalyticsData';
import AnalyticsFilters from '@/Components/Analytics/AnalyticsFilters';
import { mockRoomRunning } from '@/mock-data/room-running';

export default function RoomRunningDashboard() {
  const { selectedLocation, dateRange } = useAnalytics();
  const [selectedTab, setSelectedTab] = React.useState('overview');
  const [selectedDay, setSelectedDay] = React.useState('weekdays');

  const locationOptions = Object.keys(mockRoomRunning.sites);
  const dayOptions = ['weekdays', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'weekend'];

  // In a real application, this would be an API call
  const fetchRoomData = async () => {
    const dayData = selectedDay === 'weekdays' 
      ? mockRoomRunning.weekdays.averageRoomsRunning
      : selectedDay === 'weekend'
        ? mockRoomRunning.weekend
        : mockRoomRunning.weekdays[selectedDay];

    return {
      locationData: mockRoomRunning.sites[selectedLocation],
      dayData,
    };
  };

  const { data, isLoading, error } = useAnalyticsData(fetchRoomData, [selectedLocation, selectedDay, dateRange]);
  
  if (error) {
    throw error; // This will be caught by ErrorBoundary
  }

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-gray-900"></div>
      </div>
    );
  }

  const selectedLocationData = data?.locationData;
  const dayData = data?.dayData;

  // Format rooms running by hour data for area chart
  const roomsRunningByHourData = LineChart.formatData(
    dayData,
    'time',
    'value',
    'Rooms Running'
  );

  // Format room utilization by hour data
  const roomUtilizationByHourData = LineChart.formatData(
    Object.entries(selectedLocationData.roomsRunningByHour).map(([hour, value]) => ({
      hour,
      value,
      percentage: (value / selectedLocationData.totalRooms) * 100
    })),
    'hour',
    ['value', 'percentage'],
    ['Rooms Running', 'Utilization %']
  );

  // Format weekday vs weekend comparison data
  const weekdayWeekendData = [
    ...LineChart.formatData(
      mockRoomRunning.weekdays.averageRoomsRunning,
      'time',
      'value',
      'Weekdays'
    ),
    ...LineChart.formatData(
      mockRoomRunning.weekend,
      'time',
      'value',
      'Weekend'
    )
  ];

  // Format day of week comparison data
  const dayOfWeekData = [
    ...LineChart.formatData(
      mockRoomRunning.weekdays.Monday,
      'time',
      'value',
      'Monday'
    ),
    ...LineChart.formatData(
      mockRoomRunning.weekdays.Tuesday,
      'time',
      'value',
      'Tuesday'
    ),
    ...LineChart.formatData(
      mockRoomRunning.weekdays.Wednesday,
      'time',
      'value',
      'Wednesday'
    ),
    ...LineChart.formatData(
      mockRoomRunning.weekdays.Thursday,
      'time',
      'value',
      'Thursday'
    ),
    ...LineChart.formatData(
      mockRoomRunning.weekdays.Friday,
      'time',
      'value',
      'Friday'
    )
  ];

  // Format monthly trend data
  const monthlyTrendData = Object.entries(mockRoomRunning.monthlyTrends).flatMap(([location, data]) => 
    LineChart.formatData(
      data,
      'month',
      'value',
      location
    )
  );

  // Format location comparison data
  const locationComparisonData = Object.entries(mockRoomRunning.sites).map(([location, data]) => ({
    name: location,
    averageRoomsRunning: data.averageRoomsRunning,
    totalRooms: data.totalRooms,
    utilizationRate: data.utilizationRate
  }));

  // Format service comparison data
  const serviceComparisonData = Object.entries(mockRoomRunning.services).map(([service, data]) => ({
    name: service,
    averageRoomsRunning: data.averageRoomsRunning,
    utilizationRate: data.utilizationRate
  }));

  return (
    <div>
      <div className="flex flex-wrap gap-4 mb-6">
        <AnalyticsFilters locationOptions={locationOptions} />
        <div className="w-64">
          <label className="block text-sm font-medium mb-1">Day</label>
          <select 
            className="bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md w-full p-2"
            value={selectedDay} 
            onChange={(e) => setSelectedDay(e.target.value)}
          >
            {dayOptions.map(day => (
              <option key={day} value={day}>{day}</option>
            ))}
          </select>
        </div>
      </div>

      {/* Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <Card>
          <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Avg. Rooms Running</h5>
          <div className="text-2xl font-bold">{selectedLocationData.averageRoomsRunning}</div>
          <p className="text-xs text-muted-foreground">Out of {selectedLocationData.totalRooms} total rooms</p>
        </Card>
        <Card>
          <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Utilization Rate</h5>
          <div className="text-2xl font-bold">{selectedLocationData.utilizationRate}%</div>
          <p className="text-xs text-muted-foreground">Overall room utilization</p>
        </Card>
        <Card>
          <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Total Cases</h5>
          <div className="text-2xl font-bold">{selectedLocationData.totalCases}</div>
          <p className="text-xs text-muted-foreground">Cases performed in selected period</p>
        </Card>
        <Card>
          <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Avg. Case Duration</h5>
          <div className="text-2xl font-bold">{selectedLocationData.averageCaseDuration} min</div>
          <p className="text-xs text-muted-foreground">Average case duration</p>
        </Card>
      </div>

      {/* Tabs */}
      <Tabs>
        <Tabs.Item title="Overview">
          <div className="space-y-4">
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">{`Rooms Running by Hour - ${selectedDay}`}</h5>
              <LineChart 
                data={roomsRunningByHourData}
                margin={{ top: 20, right: 110, bottom: 50, left: 60 }}
                axisBottom={{
                  legend: 'Time',
                  legendOffset: 36,
                  legendPosition: 'middle'
                }}
                axisLeft={{
                  legend: 'Rooms Running',
                  legendOffset: -40,
                  legendPosition: 'middle'
                }}
                yScale={{ 
                  type: 'linear', 
                  min: 0, 
                  max: 'auto', 
                  stacked: false 
                }}
                colorScheme="primary"
                enableArea={true}
                areaOpacity={0.3}
                enablePoints={true}
                pointSize={8}
              />
            </Card>
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">{`Room Utilization by Hour - ${selectedLocation}`}</h5>
              <LineChart 
                data={roomUtilizationByHourData}
                margin={{ top: 20, right: 110, bottom: 50, left: 60 }}
                axisBottom={{
                  legend: 'Hour',
                  legendOffset: 36,
                  legendPosition: 'middle'
                }}
                axisLeft={{
                  legend: 'Rooms Running / Utilization (%)',
                  legendOffset: -40,
                  legendPosition: 'middle'
                }}
                colorScheme="mixed"
                enablePoints={true}
                pointSize={8}
              />
            </Card>
          </div>
        </Tabs.Item>
        
        <Tabs.Item title="Hourly Analysis">
          <div className="space-y-4">
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Weekday vs. Weekend Comparison</h5>
              <LineChart 
                data={weekdayWeekendData}
                margin={{ top: 20, right: 110, bottom: 50, left: 60 }}
                axisBottom={{
                  legend: 'Time',
                  legendOffset: 36,
                  legendPosition: 'middle'
                }}
                axisLeft={{
                  legend: 'Rooms Running',
                  legendOffset: -40,
                  legendPosition: 'middle'
                }}
                yScale={{ 
                  type: 'linear', 
                  min: 0, 
                  max: 'auto', 
                  stacked: false 
                }}
                colorScheme="primary"
                enablePoints={true}
                pointSize={8}
              />
            </Card>
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Day of Week Comparison</h5>
              <div className="h-96">
                <LineChart 
                  data={dayOfWeekData}
                  margin={{ top: 20, right: 110, bottom: 50, left: 60 }}
                  axisBottom={{
                    legend: 'Time',
                    legendOffset: 36,
                    legendPosition: 'middle'
                  }}
                  axisLeft={{
                    legend: 'Rooms Running',
                    legendOffset: -40,
                    legendPosition: 'middle'
                  }}
                  yScale={{ 
                    type: 'linear', 
                    min: 0, 
                    max: 'auto', 
                    stacked: false 
                  }}
                  colorScheme="mixed"
                  enablePoints={true}
                  pointSize={4}
                />
              </div>
            </Card>
          </div>
        </Tabs.Item>
        
        <Tabs.Item title="Trends">
          <div className="space-y-4">
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Monthly Trend - Average Rooms Running</h5>
              <LineChart 
                data={monthlyTrendData}
                margin={{ top: 20, right: 110, bottom: 50, left: 60 }}
                axisBottom={{
                  legend: 'Month',
                  legendOffset: 36,
                  legendPosition: 'middle'
                }}
                axisLeft={{
                  legend: 'Rooms Running',
                  legendOffset: -40,
                  legendPosition: 'middle'
                }}
                yScale={{ 
                  type: 'linear', 
                  min: 0, 
                  max: 'auto', 
                  stacked: false 
                }}
                colorScheme="mixed"
                enablePoints={true}
                pointSize={8}
              />
            </Card>
          </div>
        </Tabs.Item>
        
        <Tabs.Item title="Location Comparison">
          <div className="space-y-4">
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Location Comparison - Average Rooms Running</h5>
              <BarChart
                data={locationComparisonData}
                keys={['averageRoomsRunning', 'totalRooms']}
                indexBy="name"
                margin={{ top: 20, right: 130, bottom: 70, left: 60 }}
                padding={0.3}
                colorScheme="primary"
                axisBottom={{
                  tickRotation: -45,
                  legend: 'Location',
                  legendPosition: 'middle',
                  legendOffset: 50
                }}
                axisLeft={{
                  legend: 'Rooms',
                  legendPosition: 'middle',
                  legendOffset: -40
                }}
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
                    symbolSize: 20
                  }
                ]}
              />
            </Card>
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Service Comparison - Average Rooms Running</h5>
              <div className="h-96">
                <BarChart
                  data={serviceComparisonData}
                  keys={['averageRoomsRunning']}
                  indexBy="name"
                  margin={{ top: 20, right: 130, bottom: 50, left: 150 }}
                  padding={0.3}
                  colorScheme="primary"
                  layout="horizontal"
                  axisBottom={{
                    legend: 'Rooms Running',
                    legendPosition: 'middle',
                    legendOffset: 32
                  }}
                  axisLeft={{
                    legend: 'Service',
                    legendPosition: 'middle',
                    legendOffset: -120
                  }}
                />
              </div>
            </Card>
          </div>
        </Tabs.Item>
      </Tabs>
    </div>
  );
}
