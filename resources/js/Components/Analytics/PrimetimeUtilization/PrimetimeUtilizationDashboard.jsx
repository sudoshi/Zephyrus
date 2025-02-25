import React from 'react';
import { Card, Tabs } from '@/Components/ui/flowbite';
import { BarChart, LineChart } from '@/Components/ui/charts';
import { useAnalytics } from '@/contexts/AnalyticsContext';
import useAnalyticsData from '@/hooks/useAnalyticsData';
import AnalyticsFilters from '@/Components/Analytics/AnalyticsFilters';
import { mockPrimetimeUtilization } from '@/mock-data/primetime-utilization';

export default function PrimetimeUtilizationDashboard() {
  const { selectedLocation, dateRange } = useAnalytics();
  const [selectedTab, setSelectedTab] = React.useState('overview');

  const locationOptions = Object.keys(mockPrimetimeUtilization.sites);

  // In a real application, this would be an API call
  const fetchPrimetimeData = async () => {
    return {
      locationData: mockPrimetimeUtilization.sites[selectedLocation],
      utilizationData: mockPrimetimeUtilization.utilizationTrend,
      nonPrimeData: mockPrimetimeUtilization.nonPrimeTrend,
      weekdayData: mockPrimetimeUtilization.weekdayData
    };
  };

  const { data, isLoading, error } = useAnalyticsData(fetchPrimetimeData, [selectedLocation, dateRange]);
  
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
  const utilizationData = LineChart.formatData(
    data?.utilizationData,
    'month',
    'value',
    'Utilization %'
  );
  
  const nonPrimeData = LineChart.formatData(
    data?.nonPrimeData,
    'month',
    'value',
    'Non-Prime %'
  );
  
  const weekdayData = data?.weekdayData;

  // Format prime time trend data
  const primeTimeTrendData = LineChart.formatData(
    selectedLocationData.primeTimeTrend,
    'month',
    'value',
    'Prime Time %'
  );

  // Format non-prime time trend data
  const nonPrimeTimeTrendData = LineChart.formatData(
    selectedLocationData.nonPrimeTimeTrend,
    'month',
    'value',
    'Non-Prime Time %'
  );

  // Format day of week chart data
  const dayOfWeekChartData = Object.entries(weekdayData)
    .filter(([day]) => day !== 'Saturday' && day !== 'Sunday')
    .map(([day, data]) => ({
      name: day,
      utilization: data.utilization,
      nonPrime: data.nonPrime
    }));

  // Format location comparison data
  const locationComparisonData = Object.entries(mockPrimetimeUtilization.sites).map(([location, data]) => ({
    name: location,
    primeTimeUtilization: data.primeTimeUtilization,
    nonPrimeTimePercentage: data.nonPrimeTimePercentage
  }));

  // Format non-prime time by location data
  const nonPrimeTimeByLocationData = Object.entries(mockPrimetimeUtilization.sites).map(([location, data]) => ({
    name: location,
    nonPrimeTimePercentage: data.nonPrimeTimePercentage,
    nonPrimeCases: data.casesInNonPrimeTime
  }));

  return (
    <div>
      <AnalyticsFilters locationOptions={locationOptions} />

      {/* Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <Card>
          <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Prime Time Utilization</h5>
          <div className="text-2xl font-bold">{selectedLocationData.primeTimeUtilization}%</div>
          <p className="text-xs text-muted-foreground">Utilization during prime hours</p>
        </Card>
        <Card>
          <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Non-Prime Time %</h5>
          <div className="text-2xl font-bold">{selectedLocationData.nonPrimeTimePercentage}%</div>
          <p className="text-xs text-muted-foreground">Percentage of cases in non-prime time</p>
        </Card>
        <Card>
          <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Total Cases</h5>
          <div className="text-2xl font-bold">{selectedLocationData.totalCases}</div>
          <p className="text-xs text-muted-foreground">Cases performed in selected period</p>
        </Card>
        <Card>
          <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Non-Prime Time Cases</h5>
          <div className="text-2xl font-bold">{selectedLocationData.casesInNonPrimeTime}</div>
          <p className="text-xs text-muted-foreground">Cases performed in non-prime hours</p>
        </Card>
      </div>

      {/* Tabs */}
      <Tabs>
        <Tabs.Item title="Overview">
          <div className="space-y-4">
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Prime Time Utilization by Location</h5>
              <LineChart 
                data={utilizationData}
                margin={{ top: 20, right: 110, bottom: 50, left: 60 }}
                axisBottom={{
                  legend: 'Month',
                  legendOffset: 36,
                  legendPosition: 'middle'
                }}
                axisLeft={{
                  legend: 'Utilization (%)',
                  legendOffset: -40,
                  legendPosition: 'middle'
                }}
                yScale={{ 
                  type: 'linear', 
                  min: 30, 
                  max: 100, 
                  stacked: false 
                }}
                colorScheme="primary"
                enablePoints={true}
                pointSize={8}
              />
            </Card>
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">% Non-Prime Time Trend</h5>
              <LineChart 
                data={nonPrimeData}
                margin={{ top: 20, right: 110, bottom: 50, left: 60 }}
                axisBottom={{
                  legend: 'Month',
                  legendOffset: 36,
                  legendPosition: 'middle'
                }}
                axisLeft={{
                  legend: 'Non-Prime Time (%)',
                  legendOffset: -40,
                  legendPosition: 'middle'
                }}
                yScale={{ 
                  type: 'linear', 
                  min: 0, 
                  max: 25, 
                  stacked: false 
                }}
                colorScheme="primary"
                enablePoints={true}
                pointSize={8}
              />
            </Card>
          </div>
        </Tabs.Item>

        <Tabs.Item title="Trends">
          <div className="space-y-4">
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">{`Prime Time Utilization Trend - ${selectedLocation}`}</h5>
              <LineChart 
                data={primeTimeTrendData}
                margin={{ top: 20, right: 110, bottom: 50, left: 60 }}
                axisBottom={{
                  legend: 'Month',
                  legendOffset: 36,
                  legendPosition: 'middle'
                }}
                axisLeft={{
                  legend: 'Utilization (%)',
                  legendOffset: -40,
                  legendPosition: 'middle'
                }}
                yScale={{ 
                  type: 'linear', 
                  min: 60, 
                  max: 80, 
                  stacked: false 
                }}
                colorScheme="primary"
                enablePoints={true}
                pointSize={8}
              />
            </Card>
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">{`Non-Prime Time Percentage Trend - ${selectedLocation}`}</h5>
              <LineChart 
                data={nonPrimeTimeTrendData}
                margin={{ top: 20, right: 110, bottom: 50, left: 60 }}
                axisBottom={{
                  legend: 'Month',
                  legendOffset: 36,
                  legendPosition: 'middle'
                }}
                axisLeft={{
                  legend: 'Non-Prime Time (%)',
                  legendOffset: -40,
                  legendPosition: 'middle'
                }}
                yScale={{ 
                  type: 'linear', 
                  min: 0, 
                  max: 20, 
                  stacked: false 
                }}
                colorScheme="success"
                enablePoints={true}
                pointSize={8}
              />
            </Card>
          </div>
        </Tabs.Item>

        <Tabs.Item title="Day of Week">
          <div className="space-y-4">
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">{`Day of Week Utilization Matrix - ${selectedLocation}`}</h5>
              <div className="overflow-x-auto">
                <table className="w-full border-collapse">
                  <thead>
                    <tr>
                      <th className="p-2 border text-left">Metric</th>
                      <th className="p-2 border text-center">Monday</th>
                      <th className="p-2 border text-center">Tuesday</th>
                      <th className="p-2 border text-center">Wednesday</th>
                      <th className="p-2 border text-center">Thursday</th>
                      <th className="p-2 border text-center">Friday</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td className="p-2 border">Utilization</td>
                      {Object.entries(weekdayData).map(([day, data]) => (
                        day !== 'Saturday' && day !== 'Sunday' && (
                          <td key={day} className="p-2 border text-center">
                            {data.utilization.toFixed(2)}%
                          </td>
                        )
                      ))}
                    </tr>
                    <tr>
                      <td className="p-2 border">Non-Prime</td>
                      {Object.entries(weekdayData).map(([day, data]) => (
                        day !== 'Saturday' && day !== 'Sunday' && (
                          <td key={day} className="p-2 border text-center">
                            {data.nonPrime.toFixed(2)}%
                          </td>
                        )
                      ))}
                    </tr>
                  </tbody>
                </table>
              </div>
            </Card>
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">{`Day of Week Utilization - ${selectedLocation}`}</h5>
              <BarChart
                data={dayOfWeekChartData}
                keys={['utilization', 'nonPrime']}
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
                  legend: 'Percentage (%)',
                  legendPosition: 'middle',
                  legendOffset: -40
                }}
                yScale={{ 
                  type: 'linear', 
                  min: 0, 
                  max: 100, 
                  stacked: false 
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
          </div>
        </Tabs.Item>

        <Tabs.Item title="Location Comparison">
          <div className="space-y-4">
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Prime Time Utilization by Location</h5>
              <BarChart
                data={locationComparisonData}
                keys={['primeTimeUtilization', 'nonPrimeTimePercentage']}
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
                  legend: 'Percentage (%)',
                  legendPosition: 'middle',
                  legendOffset: -40
                }}
                yScale={{ 
                  type: 'linear', 
                  min: 0, 
                  max: 100, 
                  stacked: false 
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
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Non-Prime Time Percentage by Location</h5>
              <BarChart
                data={nonPrimeTimeByLocationData}
                keys={['nonPrimeTimePercentage', 'nonPrimeCases']}
                indexBy="name"
                margin={{ top: 20, right: 130, bottom: 70, left: 60 }}
                padding={0.3}
                colorScheme="mixed"
                axisBottom={{
                  tickRotation: -45,
                  legend: 'Location',
                  legendPosition: 'middle',
                  legendOffset: 50
                }}
                axisLeft={{
                  legend: 'Percentage (%)',
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
          </div>
        </Tabs.Item>
      </Tabs>
    </div>
  );
}
