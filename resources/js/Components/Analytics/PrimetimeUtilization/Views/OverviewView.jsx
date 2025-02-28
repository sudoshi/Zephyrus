import React, { useMemo } from 'react';
import { mockPrimetimeUtilization } from '../../../../mock-data/primetime-utilization';
import { ResponsiveLine } from '@nivo/line';
import { ResponsiveBar } from '@nivo/bar';
import Panel from '../../../ui/Panel';

const OverviewView = ({ filters }) => {
  // Extract filter values
  const { selectedHospital, selectedLocation, selectedSpecialty, dateRange } = filters;
  
  // Filter data based on hierarchical filters
  const filteredData = useMemo(() => {
    // In a real application, we would filter the data based on the selected filters
    // For now, we'll just use the mock data
    
    // If a location is selected, use that location's data
    if (selectedLocation && mockPrimetimeUtilization.sites[selectedLocation]) {
      return {
        locationData: mockPrimetimeUtilization.sites[selectedLocation],
        utilizationData: mockPrimetimeUtilization.utilizationData.map(item => ({
          month: item.month,
          value: selectedLocation === 'MARH IR' ? item.marhIR : item.marhOR
        })),
        nonPrimeData: mockPrimetimeUtilization.utilizationData.map(item => ({
          month: item.month,
          value: selectedLocation === 'MARH IR' ? item.nonPrimeIR : item.nonPrimeOR
        }))
      };
    }
    
    // Default: use overall data
    return {
      locationData: mockPrimetimeUtilization.overallMetrics,
      utilizationData: mockPrimetimeUtilization.utilizationData.map(item => ({
        month: item.month,
        value: (item.marhIR + item.marhOR) / 2 // Average for demo purposes
      })),
      nonPrimeData: mockPrimetimeUtilization.utilizationData.map(item => ({
        month: item.month,
        value: (item.nonPrimeIR + item.nonPrimeOR) / 2 // Average for demo purposes
      }))
    };
  }, [selectedHospital, selectedLocation, selectedSpecialty, dateRange]);

  // Format data for charts
  const utilizationChartData = [{
    id: 'Prime Time Utilization',
    data: filteredData.utilizationData.map(item => ({
      x: item.month,
      y: item.value
    }))
  }];
  
  const nonPrimeChartData = [{
    id: 'Non-Prime Time %',
    data: filteredData.nonPrimeData.map(item => ({
      x: item.month,
      y: item.value
    }))
  }];
  
  // Format day of week data
  const dayOfWeekData = Object.entries(mockPrimetimeUtilization.weekdayData)
    .filter(([day]) => day !== 'Saturday' && day !== 'Sunday')
    .map(([day, data]) => ({
      name: day,
      utilization: data?.utilization || 0
    }));
  
  // Format location comparison data
  const locationComparisonData = Object.entries(mockPrimetimeUtilization.sites || {}).map(([location, data]) => ({
    name: location,
    nonPrimeTimePercentage: data?.nonPrimeTimePercentage || 0,
    nonPrimeCases: data?.casesInNonPrimeTime || 0
  }));

  return (
    <div className="space-y-6">
      {/* Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <Panel isSubpanel dropLightIntensity="medium">
          <h5 className="text-xl font-bold tracking-tight">Prime Time Utilization</h5>
          <div className="text-2xl font-bold">{filteredData.locationData?.primeTimeUtilization || 0}%</div>
          <p className="text-xs text-muted-foreground">Utilization during prime hours</p>
        </Panel>
        <Panel isSubpanel dropLightIntensity="medium">
          <h5 className="text-xl font-bold tracking-tight">Non-Prime Time %</h5>
          <div className="text-2xl font-bold">{filteredData.locationData?.nonPrimeTimePercentage || 0}%</div>
          <p className="text-xs text-muted-foreground">Percentage of cases in non-prime time</p>
        </Panel>
        <Panel isSubpanel dropLightIntensity="medium">
          <h5 className="text-xl font-bold tracking-tight">Total Cases</h5>
          <div className="text-2xl font-bold">{filteredData.locationData?.totalCases || 0}</div>
          <p className="text-xs text-muted-foreground">Cases performed in selected period</p>
        </Panel>
        <Panel isSubpanel dropLightIntensity="medium">
          <h5 className="text-xl font-bold tracking-tight">Non-Prime Time Cases</h5>
          <div className="text-2xl font-bold">{filteredData.locationData?.casesInNonPrimeTime || 0}</div>
          <p className="text-xs text-muted-foreground">Cases performed in non-prime hours</p>
        </Panel>
      </div>

      {/* Charts */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Panel title="Prime Time Utilization Trend" isSubpanel dropLightIntensity="medium">
          <div className="h-80">
            <ResponsiveLine
              data={utilizationChartData}
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
              pointSize={8}
              pointColor={{ theme: 'background' }}
              pointBorderWidth={2}
              pointBorderColor={{ from: 'serieColor' }}
              enableSlices="x"
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
        
        <Panel title="Non-Prime Time Percentage Trend" isSubpanel dropLightIntensity="medium">
          <div className="h-80">
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
              pointSize={8}
              pointColor={{ theme: 'background' }}
              pointBorderWidth={2}
              pointBorderColor={{ from: 'serieColor' }}
              enableSlices="x"
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

      <Panel title="Prime Time Utilization by Day of Week" isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveBar
            data={dayOfWeekData}
            keys={['utilization']}
            indexBy="name"
            margin={{ top: 20, right: 130, bottom: 50, left: 60 }}
            padding={0.3}
            colors={{ scheme: 'category10' }}
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
            labelSkipWidth={12}
            labelSkipHeight={12}
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
        </div>
      </Panel>

      <Panel title="Non-Prime Time Percentage by Location" isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveBar
            data={locationComparisonData}
            keys={['nonPrimeTimePercentage']}
            indexBy="name"
            margin={{ top: 20, right: 130, bottom: 70, left: 60 }}
            padding={0.3}
            colors={{ scheme: 'accent' }}
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
            labelSkipWidth={12}
            labelSkipHeight={12}
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
        </div>
      </Panel>
    </div>
  );
};

export default OverviewView;
