import React, { useMemo } from 'react';
import { mockPrimetimeUtilization } from '../../../../mock-data/primetime-utilization';
import { ResponsiveLine } from '@nivo/line';
import Panel from '../../../ui/Panel';
import getChartTheme from '@/utils/chartTheme';
import { useDarkMode } from '@/Layouts/AuthenticatedLayout';

const TrendsView = ({ filters }) => {
  // Extract filter values
  const { selectedHospital, selectedLocation, selectedSpecialty, dateRange } = filters;
  const { isDarkMode } = useDarkMode();
  
  // Get chart theme with proper dark mode setting
  const chartTheme = getChartTheme(isDarkMode);
  
  // Filter data based on hierarchical filters
  const filteredData = useMemo(() => {
    // In a real application, we would filter the data based on the selected filters
    // For now, we'll just use the mock data
    
    // If a location is selected, use that location's data
    if (selectedLocation && mockPrimetimeUtilization.sites[selectedLocation]) {
      const site = mockPrimetimeUtilization.sites[selectedLocation];
      return {
        primeTimeTrend: site.trends.primeTimeUtilization,
        nonPrimeTimeTrend: site.trends.nonPrimeTimePercentage
      };
    }
    
    // Default: use first location's data
    const firstSite = Object.values(mockPrimetimeUtilization.sites)[0];
    return {
      primeTimeTrend: firstSite.trends.primeTimeUtilization,
      nonPrimeTimeTrend: firstSite.trends.nonPrimeTimePercentage
    };
  }, [selectedHospital, selectedLocation, selectedSpecialty, dateRange]);

  // Format data for charts
  const primeTimeChartData = [{
    id: 'Prime Time Utilization',
    data: filteredData.primeTimeTrend.map(item => ({
      x: item.month,
      y: item.value
    }))
  }];
  
  const nonPrimeChartData = [{
    id: 'Non-Prime Time %',
    data: filteredData.nonPrimeTimeTrend.map(item => ({
      x: item.month,
      y: item.value
    }))
  }];

  return (
    <div className="space-y-6">
      <Panel title="Prime Time Utilization Trend (6 Months)" isSubpanel dropLightIntensity="medium">
        <div className="h-96 bg-gray-900 rounded-lg p-4">
          <ResponsiveLine
            data={primeTimeChartData}
            margin={{ top: 20, right: 110, bottom: 50, left: 60 }}
            xScale={{ type: 'point' }}
            yScale={{ type: 'linear', min: 30, max: 100, stacked: false }}
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
            colors={{ scheme: 'category10' }}
            pointSize={10}
            pointColor={{ theme: 'background' }}
            pointBorderWidth={2}
            pointBorderColor={{ from: 'serieColor' }}
            enableSlices="x"
            enableArea={true}
            areaOpacity={0.1}
            useMesh={true}
            theme={chartTheme}
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
                symbolBorderColor: 'rgba(0, 0, 0, .5)'
              }
            ]}
          />
        </div>
      </Panel>
      
      <Panel title="Non-Prime Time Percentage Trend (6 Months)" isSubpanel dropLightIntensity="medium">
        <div className="h-96 bg-gray-900 rounded-lg p-4">
          <ResponsiveLine
            data={nonPrimeChartData}
            margin={{ top: 20, right: 110, bottom: 50, left: 60 }}
            xScale={{ type: 'point' }}
            yScale={{ type: 'linear', min: 0, max: 25, stacked: false }}
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
            colors={{ scheme: 'accent' }}
            pointSize={10}
            pointColor={{ theme: 'background' }}
            pointBorderWidth={2}
            pointBorderColor={{ from: 'serieColor' }}
            enableSlices="x"
            enableArea={true}
            areaOpacity={0.1}
            useMesh={true}
            theme={chartTheme}
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
                symbolBorderColor: 'rgba(0, 0, 0, .5)'
              }
            ]}
          />
        </div>
      </Panel>
      
      <Panel title="Yearly Comparison" isSubpanel dropLightIntensity="medium">
        <div className="h-96 bg-gray-900 rounded-lg p-4">
          <ResponsiveLine
            data={[
              {
                id: '2024',
                data: mockPrimetimeUtilization.utilizationData
                  .filter(item => item.month.includes('24'))
                  .map(item => ({
                    x: item.month.replace(' 24', ''),
                    y: (item.marhIR + item.marhOR) / 2
                  }))
              },
              {
                id: '2023',
                data: mockPrimetimeUtilization.utilizationData
                  .filter(item => item.month.includes('23'))
                  .map(item => ({
                    x: item.month.replace(' 23', ''),
                    y: (item.marhIR + item.marhOR) / 2
                  }))
              }
            ]}
            margin={{ top: 20, right: 110, bottom: 50, left: 60 }}
            xScale={{ type: 'point' }}
            yScale={{ type: 'linear', min: 30, max: 100, stacked: false }}
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
            colors={{ scheme: 'paired' }}
            pointSize={8}
            pointColor={{ theme: 'background' }}
            pointBorderWidth={2}
            pointBorderColor={{ from: 'serieColor' }}
            enableSlices="x"
            theme={chartTheme}
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
                symbolBorderColor: 'rgba(0, 0, 0, .5)'
              }
            ]}
          />
        </div>
      </Panel>
    </div>
  );
};

export default TrendsView;
