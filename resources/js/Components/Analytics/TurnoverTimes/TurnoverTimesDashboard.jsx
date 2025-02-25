import React from 'react';
import { Card, Tabs } from '@/Components/ui/flowbite';
import { BarChart, LineChart, PieChart } from '@/Components/ui/charts';
import { useAnalytics } from '@/contexts/AnalyticsContext';
import useAnalyticsData from '@/hooks/useAnalyticsData';
import AnalyticsFilters from '@/Components/Analytics/AnalyticsFilters';
import { mockTurnoverTimes } from '@/mock-data/turnover-times';

export default function TurnoverTimesDashboard() {
  const { selectedLocation, selectedService, dateRange } = useAnalytics();
  const [selectedTab, setSelectedTab] = React.useState('overview');

  const locationOptions = Object.keys(mockTurnoverTimes.sites);
  const serviceOptions = Object.keys(mockTurnoverTimes.services);

  // In a real application, this would be an API call
  const fetchTurnoverData = async () => {
    return {
      locationData: mockTurnoverTimes.sites[selectedLocation],
      serviceData: selectedService ? mockTurnoverTimes.services[selectedService] : null
    };
  };

  const { data, isLoading, error } = useAnalyticsData(fetchTurnoverData, [selectedLocation, selectedService, dateRange]);
  
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
  const selectedServiceData = data?.serviceData;

  // Format turnover distribution data for charts
  const formatDistributionData = (distribution) => {
    return Object.entries(distribution).map(([range, count]) => ({
      id: range,
      label: range,
      value: count
    }));
  };

  const distributionData = formatDistributionData(selectedLocationData.turnoverDistribution);

  // Format line chart data
  const trendData = LineChart.formatData(
    selectedLocationData.trends.medianTurnoverTime.map((item, index) => ({
      month: item.month,
      median: item.value,
      average: selectedLocationData.trends.averageTurnoverTime[index].value
    })),
    'month',
    ['median', 'average'],
    ['Median Turnover (min)', 'Average Turnover (min)']
  );

  // Format room data for bar chart
  const roomData = selectedLocationData.rooms ? selectedLocationData.rooms.map(room => ({
    name: room.room,
    medianTurnoverTime: room.medianTurnoverTime,
    averageTurnoverTime: room.averageTurnoverTime
  })) : [];

  // Format day of week data
  const dayOfWeekData = Object.entries(mockTurnoverTimes.dayOfWeek[selectedLocation]).map(([day, data]) => ({
    name: day,
    median: data.median,
    average: data.average
  }));

  // Format time of day data
  const timeOfDayData = Object.entries(mockTurnoverTimes.timeOfDay[selectedLocation]).map(([timeRange, data]) => ({
    name: timeRange,
    median: data.median,
    average: data.average
  }));

  // Format location comparison data
  const locationComparisonData = Object.entries(mockTurnoverTimes.sites).map(([location, data]) => ({
    name: location,
    median: data.medianTurnoverTime,
    average: data.averageTurnoverTime
  }));

  // Format service comparison data
  const serviceComparisonData = Object.entries(mockTurnoverTimes.services).map(([service, data]) => ({
    name: service,
    median: data.medianTurnoverTime,
    average: data.averageTurnoverTime
  }));

  return (
    <div>
      <AnalyticsFilters 
        locationOptions={locationOptions}
        showServiceFilter={true}
        serviceOptions={serviceOptions}
      />

      {/* Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <Card>
          <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Median Turnover Time</h5>
          <div className="text-2xl font-bold">
            {selectedServiceData ? selectedServiceData.medianTurnoverTime : selectedLocationData.medianTurnoverTime} min
          </div>
          <p className="text-xs text-muted-foreground">Median time between cases</p>
        </Card>
        <Card>
          <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Average Turnover Time</h5>
          <div className="text-2xl font-bold">
            {selectedServiceData ? selectedServiceData.averageTurnoverTime : selectedLocationData.averageTurnoverTime} min
          </div>
          <p className="text-xs text-muted-foreground">Average time between cases</p>
        </Card>
        <Card>
          <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Total Cases</h5>
          <div className="text-2xl font-bold">
            {selectedServiceData ? selectedServiceData.totalCases : selectedLocationData.totalCases}
          </div>
          <p className="text-xs text-muted-foreground">Cases performed in selected period</p>
        </Card>
        <Card>
          <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Total Turnovers</h5>
          <div className="text-2xl font-bold">
            {selectedServiceData ? selectedServiceData.totalTurnovers : selectedLocationData.totalTurnovers}
          </div>
          <p className="text-xs text-muted-foreground">Number of room turnovers</p>
        </Card>
      </div>

      {/* Tabs */}
      <Tabs>
        <Tabs.Item title="Overview">
          <div className="space-y-4">
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">{`Turnover Time Trends - ${selectedLocation}`}</h5>
              <LineChart 
                data={trendData}
                margin={{ top: 20, right: 110, bottom: 50, left: 60 }}
                axisBottom={{
                  legend: 'Month',
                  legendOffset: 36,
                  legendPosition: 'middle'
                }}
                axisLeft={{
                  legend: 'Time (minutes)',
                  legendOffset: -40,
                  legendPosition: 'middle'
                }}
                yScale={{ 
                  type: 'linear', 
                  min: 25, 
                  max: 45, 
                  stacked: false 
                }}
                colorScheme="primary"
              />
            </Card>
            {selectedLocationData.rooms && (
              <Card>
                <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">{`Turnover Time by Room - ${selectedLocation}`}</h5>
                <BarChart
                  data={roomData}
                  keys={['medianTurnoverTime', 'averageTurnoverTime']}
                  indexBy="name"
                  margin={{ top: 20, right: 130, bottom: 70, left: 60 }}
                  padding={0.3}
                  colorScheme="primary"
                  axisBottom={{
                    tickRotation: -45,
                    legend: 'Room',
                    legendPosition: 'middle',
                    legendOffset: 50
                  }}
                  axisLeft={{
                    legend: 'Time (minutes)',
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
            )}
          </div>
        </Tabs.Item>
        
        <Tabs.Item title="Distribution">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">{`Turnover Time Distribution - ${selectedLocation}`}</h5>
              <PieChart
                data={distributionData}
                margin={{ top: 40, right: 80, bottom: 80, left: 80 }}
                innerRadius={0}
                padAngle={0.7}
                cornerRadius={3}
                activeOuterRadiusOffset={8}
                colorScheme="mixed"
                arcLinkLabelsSkipAngle={10}
                arcLinkLabelsTextColor="#f8fafc"
                arcLinkLabelsThickness={2}
                arcLabelsSkipAngle={10}
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
                    itemTextColor: '#999',
                    itemDirection: 'left-to-right',
                    itemOpacity: 1,
                    symbolSize: 18,
                    symbolShape: 'circle'
                  }
                ]}
              />
            </Card>
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">{`Turnover Time Distribution - ${selectedLocation}`}</h5>
              <BarChart
                data={distributionData.map(item => ({ name: item.id, count: item.value }))}
                keys={['count']}
                indexBy="name"
                margin={{ top: 20, right: 130, bottom: 50, left: 60 }}
                padding={0.3}
                colorScheme="mixed"
                axisBottom={{
                  legend: 'Time Range',
                  legendPosition: 'middle',
                  legendOffset: 32
                }}
                axisLeft={{
                  legend: 'Number of Turnovers',
                  legendPosition: 'middle',
                  legendOffset: -40
                }}
              />
            </Card>
          </div>
        </Tabs.Item>
        
        <Tabs.Item title="Trends">
          <div className="space-y-4">
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">{`Day of Week Analysis - ${selectedLocation}`}</h5>
              <BarChart
                data={dayOfWeekData}
                keys={['median', 'average']}
                indexBy="name"
                margin={{ top: 20, right: 130, bottom: 50, left: 60 }}
                padding={0.3}
                colorScheme="primary"
                axisBottom={{
                  legend: 'Day of Week',
                  legendPosition: 'middle',
                  legendOffset: 32
                }}
                axisLeft={{
                  legend: 'Time (minutes)',
                  legendPosition: 'middle',
                  legendOffset: -40
                }}
                yScale={{ 
                  type: 'linear', 
                  min: 25, 
                  max: 45, 
                  stacked: false 
                }}
              />
            </Card>
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">{`Time of Day Analysis - ${selectedLocation}`}</h5>
              <BarChart
                data={timeOfDayData}
                keys={['median', 'average']}
                indexBy="name"
                margin={{ top: 20, right: 130, bottom: 50, left: 60 }}
                padding={0.3}
                colorScheme="primary"
                axisBottom={{
                  legend: 'Time of Day',
                  legendPosition: 'middle',
                  legendOffset: 32
                }}
                axisLeft={{
                  legend: 'Time (minutes)',
                  legendPosition: 'middle',
                  legendOffset: -40
                }}
                yScale={{ 
                  type: 'linear', 
                  min: 25, 
                  max: 45, 
                  stacked: false 
                }}
              />
            </Card>
          </div>
        </Tabs.Item>
        
        <Tabs.Item title="Comparison">
          <div className="space-y-4">
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Location Comparison - Turnover Times</h5>
              <BarChart
                data={locationComparisonData}
                keys={['median', 'average']}
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
                  legend: 'Time (minutes)',
                  legendPosition: 'middle',
                  legendOffset: -40
                }}
                yScale={{ 
                  type: 'linear', 
                  min: 25, 
                  max: 45, 
                  stacked: false 
                }}
              />
            </Card>
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Service Comparison - Turnover Times</h5>
              <div className="h-96">
                <BarChart
                  data={serviceComparisonData}
                  keys={['median', 'average']}
                  indexBy="name"
                  margin={{ top: 20, right: 130, bottom: 50, left: 150 }}
                  padding={0.3}
                  colorScheme="primary"
                  layout="horizontal"
                  axisBottom={{
                    legend: 'Time (minutes)',
                    legendPosition: 'middle',
                    legendOffset: 32
                  }}
                  axisLeft={{
                    legend: 'Service',
                    legendPosition: 'middle',
                    legendOffset: -120
                  }}
                  yScale={{ 
                    type: 'linear', 
                    min: 25, 
                    max: 45, 
                    stacked: false 
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
