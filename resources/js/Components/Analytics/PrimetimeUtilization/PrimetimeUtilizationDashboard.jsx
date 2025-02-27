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
    // Create properly formatted data for charts from utilizationData
    const utilizationTrend = mockPrimetimeUtilization.utilizationData.map(item => ({
      month: item.month,
      value: selectedLocation === 'MARH IR' ? item.marhIR : item.marhOR
    }));

    const nonPrimeTrend = mockPrimetimeUtilization.utilizationData.map(item => ({
      month: item.month,
      value: selectedLocation === 'MARH IR' ? item.nonPrimeIR : item.nonPrimeOR
    }));

    return {
      locationData: mockPrimetimeUtilization.sites[selectedLocation],
      utilizationData: utilizationTrend,
      nonPrimeData: nonPrimeTrend,
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
  const utilizationData = data?.utilizationData ? LineChart.formatData(
    data.utilizationData,
    'month',
    'value',
    'Utilization %'
  ) : [];
  
  const nonPrimeData = data?.nonPrimeData ? LineChart.formatData(
    data.nonPrimeData,
    'month',
    'value',
    'Non-Prime %'
  ) : [];
  
  const weekdayData = data?.weekdayData;

  // Format prime time trend data
  const primeTimeTrendData = selectedLocationData?.primeTimeTrend ? LineChart.formatData(
    selectedLocationData.primeTimeTrend,
    'month',
    'value',
    'Prime Time %'
  ) : [];

  // Format non-prime time trend data
  const nonPrimeTimeTrendData = selectedLocationData?.nonPrimeTimeTrend ? LineChart.formatData(
    selectedLocationData.nonPrimeTimeTrend,
    'month',
    'value',
    'Non-Prime Time %'
  ) : [];

  // Format day of week chart data
  const dayOfWeekChartData = weekdayData ? Object.entries(weekdayData)
    .filter(([day]) => day !== 'Saturday' && day !== 'Sunday')
    .map(([day, data]) => ({
      name: day,
      utilization: data?.utilization || 0,
      nonPrime: data?.nonPrime || 0
    })) : [];

  // Format location comparison data
  const locationComparisonData = Object.entries(mockPrimetimeUtilization.sites || {}).map(([location, data]) => ({
    name: location,
    primeTimeUtilization: data?.primeTimeUtilization || 0,
    nonPrimeTimePercentage: data?.nonPrimeTimePercentage || 0
  }));

  // Format non-prime time by location data
  const nonPrimeTimeByLocationData = Object.entries(mockPrimetimeUtilization.sites || {}).map(([location, data]) => ({
    name: location,
    nonPrimeTimePercentage: data?.nonPrimeTimePercentage || 0,
    nonPrimeCases: data?.casesInNonPrimeTime || 0
  }));

  return (
    <div>
      <AnalyticsFilters locationOptions={locationOptions} />

      {/* Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <Card>
          <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Prime Time Utilization</h5>
          <div className="text-2xl font-bold">{selectedLocationData?.primeTimeUtilization || 0}%</div>
          <p className="text-xs text-muted-foreground">Utilization during prime hours</p>
        </Card>
        <Card>
          <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Non-Prime Time %</h5>
          <div className="text-2xl font-bold">{selectedLocationData?.nonPrimeTimePercentage || 0}%</div>
          <p className="text-xs text-muted-foreground">Percentage of cases in non-prime time</p>
        </Card>
        <Card>
          <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Total Cases</h5>
          <div className="text-2xl font-bold">{selectedLocationData?.totalCases || 0}</div>
          <p className="text-xs text-muted-foreground">Cases performed in selected period</p>
        </Card>
        <Card>
          <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Non-Prime Time Cases</h5>
          <div className="text-2xl font-bold">{selectedLocationData?.casesInNonPrimeTime || 0}</div>
          <p className="text-xs text-muted-foreground">Cases performed in non-prime hours</p>
        </Card>
      </div>

      {/* Tabs */}
      <Tabs style={{ base: "underline" }}>
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
                colorScheme="warning"
                enablePoints={true}
                pointSize={8}
              />
            </Card>
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Prime Time Utilization by Day of Week</h5>
              <BarChart
                data={dayOfWeekChartData}
                keys={['utilization']}
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
                  legend: 'Utilization (%)',
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
        <Tabs.Item title="Location Trends">
          <div className="space-y-4">
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Prime Time Utilization Trend</h5>
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
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Non-Prime Time Trend</h5>
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
                  max: 25, 
                  stacked: false 
                }}
                colorScheme="warning"
                enablePoints={true}
                pointSize={8}
              />
            </Card>
          </div>
        </Tabs.Item>
        <Tabs.Item title="Day of Week Analysis">
          <div className="space-y-4">
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Day of Week Utilization</h5>
              <div className="overflow-x-auto">
                <table className="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                  <thead className="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                    <tr>
                      <th className="p-2 border">Metric</th>
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
                      {weekdayData && Object.entries(weekdayData).map(([day, data]) => (
                        day !== 'Saturday' && day !== 'Sunday' && (
                          <td key={day} className="p-2 border text-center">
                            {data?.utilization ? data.utilization.toFixed(2) + '%' : 'N/A'}
                          </td>
                        )
                      ))}
                    </tr>
                    <tr>
                      <td className="p-2 border">Non-Prime</td>
                      {weekdayData && Object.entries(weekdayData).map(([day, data]) => (
                        day !== 'Saturday' && day !== 'Sunday' && (
                          <td key={day} className="p-2 border text-center">
                            {data?.nonPrime ? data.nonPrime.toFixed(2) + '%' : 'N/A'}
                          </td>
                        )
                      ))}
                    </tr>
                  </tbody>
                </table>
              </div>
            </Card>
            <Card>
              <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Day of Week Utilization Chart</h5>
              <BarChart
                data={dayOfWeekChartData}
                keys={['utilization', 'nonPrime']}
                indexBy="name"
                margin={{ top: 20, right: 130, bottom: 50, left: 60 }}
                padding={0.3}
                groupMode="grouped"
                colorScheme="mixed"
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
