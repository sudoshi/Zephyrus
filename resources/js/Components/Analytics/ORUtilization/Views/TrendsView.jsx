import React from 'react';
import Panel from '@/Components/ui/Panel';
import { LineChart } from '@/Components/ui/charts/LineChart';
import { BarChart } from '@/Components/ui/charts/BarChart';
import { 
  mockTrendsData, 
  mockComparisonTrendsData, 
  mockDayOfWeekData, 
  mockTimeOfDayData 
} from '../mockData';

const TrendsView = ({ data }) => {
  // Get location data
  const getSelectedLocationData = () => {
    if (!data || !data.locations) return null;
    return data.locations[Object.keys(data.locations)[0]];
  };
  
  // Get selected location name
  const getSelectedLocationName = () => {
    const locationData = getSelectedLocationData();
    if (locationData) {
      return locationData.fullName || locationData.name;
    }
    return 'All Locations';
  };

  // Format trends data for line chart
  const formatTrendsData = () => {
    // Use real data if available, otherwise use mock data
    const realTrendsData = data?.trends?.[Object.keys(data.trends)[0]]?.utilization?.map(item => ({
      date: item.month,
      utilization: item.value
    }));

    const trendsData = realTrendsData?.length > 0 ? realTrendsData : mockTrendsData;
    
    return [
      {
        id: 'Current Year',
        data: trendsData.map(item => ({
          x: item.date,
          y: Math.round(item.utilization * 100)
        }))
      },
      {
        id: 'Previous Year',
        data: mockComparisonTrendsData.map(item => ({
          x: item.date.replace('2023', '2024'),
          y: Math.round(item.utilization * 100)
        }))
      }
    ];
  };

  // Format day of week data for bar chart
  const formatDayOfWeekData = () => {
    return mockDayOfWeekData.map(item => ({
      name: item.day,
      utilization: Math.round(item.utilization * 100)
    }));
  };

  // Format time of day data for line chart
  const formatTimeOfDayData = () => {
    return [
      {
        id: 'Utilization',
        data: mockTimeOfDayData.map(item => ({
          x: item.hour,
          y: Math.round(item.utilization * 100)
        }))
      }
    ];
  };

  return (
    <div>
      <Panel title={`Utilization Trends: ${getSelectedLocationName()}`} className="mb-6">
        <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
          Historical trends and comparative analysis of OR utilization, showing patterns over time.
        </p>
        
        <Panel isSubpanel={true} dropLightIntensity="medium" title="Monthly Utilization Trends" className="mb-6">
          <div className="h-80">
            <LineChart 
              data={formatTrendsData()}
              margin={{ top: 20, right: 20, bottom: 60, left: 60 }}
              xScale={{ type: 'point' }}
              yScale={{ type: 'linear', min: 0, max: 100 }}
              axisBottom={{
                tickRotation: -45,
                legend: 'Month',
                legendOffset: 50,
                legendPosition: 'middle'
              }}
              axisLeft={{
                legend: 'Utilization (%)',
                legendOffset: -40,
                legendPosition: 'middle'
              }}
              enablePoints={true}
              pointSize={8}
              pointColor={{ theme: 'background' }}
              pointBorderWidth={2}
              pointBorderColor={{ from: 'serieColor' }}
              enableSlices="x"
              useMesh={true}
              legends={[
                {
                  anchor: 'bottom-right',
                  direction: 'column',
                  justify: false,
                  translateX: 0,
                  translateY: 0,
                  itemsSpacing: 0,
                  itemDirection: 'left-to-right',
                  itemWidth: 80,
                  itemHeight: 20,
                  symbolSize: 12,
                  symbolShape: 'circle'
                }
              ]}
            />
          </div>
        </Panel>
        
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <Panel isSubpanel={true} dropLightIntensity="medium" title="Utilization by Day of Week">
            <div className="h-64">
              <BarChart 
                data={formatDayOfWeekData()}
                keys={['utilization']}
                indexBy="name"
                margin={{ top: 20, right: 20, bottom: 50, left: 60 }}
                padding={0.3}
                axisBottom={{
                  tickSize: 5,
                  tickPadding: 5,
                  tickRotation: 0,
                  legend: 'Day of Week',
                  legendPosition: 'middle',
                  legendOffset: 40
                }}
                axisLeft={{
                  tickSize: 5,
                  tickPadding: 5,
                  tickRotation: 0,
                  legend: 'Utilization (%)',
                  legendPosition: 'middle',
                  legendOffset: -50
                }}
                labelFormat={value => `${value}%`}
                colorScheme="primary"
                labelSkipWidth={12}
                labelSkipHeight={12}
                labelTextColor={{ from: 'color', modifiers: [['darker', 1.6]] }}
                animate={true}
                motionStiffness={90}
                motionDamping={15}
              />
            </div>
          </Panel>
          
          <Panel isSubpanel={true} dropLightIntensity="medium" title="Utilization by Time of Day">
            <div className="h-64">
              <LineChart 
                data={formatTimeOfDayData()}
                margin={{ top: 20, right: 20, bottom: 50, left: 60 }}
                xScale={{ type: 'point' }}
                yScale={{ type: 'linear', min: 0, max: 100 }}
                axisBottom={{
                  tickRotation: -45,
                  legend: 'Hour',
                  legendOffset: 40,
                  legendPosition: 'middle'
                }}
                axisLeft={{
                  legend: 'Utilization (%)',
                  legendOffset: -50,
                  legendPosition: 'middle'
                }}
                enableArea={true}
                areaOpacity={0.15}
                enablePoints={true}
                pointSize={8}
                pointColor={{ theme: 'background' }}
                pointBorderWidth={2}
                pointBorderColor={{ from: 'serieColor' }}
                enableSlices="x"
                useMesh={true}
                colorScheme="primary"
              />
            </div>
          </Panel>
        </div>
      </Panel>
    </div>
  );
};

export default TrendsView;
