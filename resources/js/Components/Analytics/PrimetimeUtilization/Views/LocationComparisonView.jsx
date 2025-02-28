import React, { useMemo } from 'react';
import { mockPrimetimeUtilization } from '../../../../mock-data/primetime-utilization';
import { ResponsiveBar } from '@nivo/bar';
import { ResponsivePie } from '@nivo/pie';
import Panel from '../../../ui/Panel';

const LocationComparisonView = ({ filters }) => {
  // Extract filter values
  const { selectedHospital, selectedLocation, selectedSpecialty, dateRange } = filters;
  
  // Format location comparison data
  const locationData = useMemo(() => {
    // Filter locations based on selected hospital
    const filteredLocations = selectedHospital
      ? Object.entries(mockPrimetimeUtilization.sites || {})
          .filter(([location]) => location.startsWith(selectedHospital))
      : Object.entries(mockPrimetimeUtilization.sites || {});
    
    return filteredLocations.map(([location, data]) => ({
      name: location,
      primeTimeUtilization: data?.primeTimeUtilization || 0,
      nonPrimeTimePercentage: data?.nonPrimeTimePercentage || 0,
      totalCases: data?.totalCases || 0,
      casesInPrimeTime: data?.casesInPrimeTime || 0,
      casesInNonPrimeTime: data?.casesInNonPrimeTime || 0
    }));
  }, [selectedHospital, selectedLocation, selectedSpecialty, dateRange]);
  
  // Format pie chart data
  const pieChartData = useMemo(() => {
    const totalCases = locationData.reduce((sum, location) => sum + location.totalCases, 0);
    
    return locationData.map(location => ({
      id: location.name,
      label: location.name,
      value: location.totalCases,
      percentage: ((location.totalCases / totalCases) * 100).toFixed(1)
    }));
  }, [locationData]);

  return (
    <div className="space-y-6">
      <Panel title="Prime Time Utilization by Location" isSubpanel dropLightIntensity="medium">
        <div className="h-96">
          <ResponsiveBar
            data={locationData}
            keys={['primeTimeUtilization']}
            indexBy="name"
            margin={{ top: 20, right: 130, bottom: 70, left: 60 }}
            padding={0.3}
            colors={{ scheme: 'category10' }}
            axisBottom={{
              tickRotation: -45,
              legend: 'Location',
              legendPosition: 'middle',
              legendOffset: 50
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
        <div className="h-96">
          <ResponsiveBar
            data={locationData}
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
              legend: 'Non-Prime Time (%)',
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

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Panel title="Total Cases by Location" isSubpanel dropLightIntensity="medium">
          <div className="h-96">
            <ResponsivePie
              data={pieChartData}
              margin={{ top: 40, right: 80, bottom: 80, left: 80 }}
              innerRadius={0.5}
              padAngle={0.7}
              cornerRadius={3}
              colors={{ scheme: 'paired' }}
              borderWidth={1}
              borderColor={{ from: 'color', modifiers: [['darker', 0.2]] }}
              radialLabelsSkipAngle={10}
              radialLabelsTextXOffset={6}
              radialLabelsTextColor="#333333"
              radialLabelsLinkOffset={0}
              radialLabelsLinkDiagonalLength={16}
              radialLabelsLinkHorizontalLength={24}
              radialLabelsLinkStrokeWidth={1}
              radialLabelsLinkColor={{ from: 'color' }}
              slicesLabelsSkipAngle={10}
              slicesLabelsTextColor="#333333"
              animate={true}
              motionStiffness={90}
              motionDamping={15}
              legends={[
                {
                  anchor: 'bottom',
                  direction: 'row',
                  translateY: 56,
                  itemWidth: 100,
                  itemHeight: 18,
                  itemTextColor: '#999',
                  symbolSize: 18,
                  symbolShape: 'circle',
                  effects: [
                    {
                      on: 'hover',
                      style: {
                        itemTextColor: '#000'
                      }
                    }
                  ]
                }
              ]}
            />
          </div>
        </Panel>

        <Panel title="Cases in Non-Prime Time by Location" isSubpanel dropLightIntensity="medium">
          <div className="h-96">
            <ResponsiveBar
              data={locationData}
              keys={['casesInNonPrimeTime']}
              indexBy="name"
              margin={{ top: 20, right: 130, bottom: 70, left: 60 }}
              padding={0.3}
              colors={{ scheme: 'red_blue' }}
              axisBottom={{
                tickRotation: -45,
                legend: 'Location',
                legendPosition: 'middle',
                legendOffset: 50
              }}
              axisLeft={{
                legend: 'Number of Cases',
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

      <Panel title="Location Comparison Table" isSubpanel dropLightIntensity="medium">
        <div className="overflow-x-auto">
          <table className="w-full text-sm text-left text-gray-500 dark:text-gray-400">
            <thead className="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
              <tr>
                <th className="p-2 border">Location</th>
                <th className="p-2 border text-center">Prime Time Utilization</th>
                <th className="p-2 border text-center">Non-Prime Time %</th>
                <th className="p-2 border text-center">Total Cases</th>
                <th className="p-2 border text-center">Cases in Prime Time</th>
                <th className="p-2 border text-center">Cases in Non-Prime Time</th>
              </tr>
            </thead>
            <tbody>
              {locationData.map(location => (
                <tr key={location.name} className="bg-white border-b dark:bg-gray-800 dark:border-gray-700">
                  <td className="p-2 border">{location.name}</td>
                  <td className="p-2 border text-center">{location.primeTimeUtilization.toFixed(1)}%</td>
                  <td className="p-2 border text-center">{location.nonPrimeTimePercentage.toFixed(1)}%</td>
                  <td className="p-2 border text-center">{location.totalCases}</td>
                  <td className="p-2 border text-center">{location.casesInPrimeTime}</td>
                  <td className="p-2 border text-center">{location.casesInNonPrimeTime}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  );
};

export default LocationComparisonView;
