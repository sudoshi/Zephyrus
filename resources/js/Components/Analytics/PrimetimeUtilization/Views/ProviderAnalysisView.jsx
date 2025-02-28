import React, { useMemo } from 'react';
import { mockPrimetimeUtilization } from '../../../../mock-data/primetime-utilization';
import { ResponsiveBar } from '@nivo/bar';
import { ResponsivePie } from '@nivo/pie';
import Panel from '../../../ui/Panel';

const ProviderAnalysisView = ({ filters }) => {
  // Extract filter values
  const { selectedHospital, selectedLocation, selectedSpecialty, dateRange } = filters;
  
  // Format provider data
  const providerData = useMemo(() => {
    return Object.entries(mockPrimetimeUtilization.providers || {})
      .filter(([provider, data]) => {
        // Filter by specialty if selected
        if (selectedSpecialty && data.service !== selectedSpecialty) {
          return false;
        }
        return true;
      })
      .map(([provider, data]) => ({
        id: provider,
        service: data.service,
        primeTimeUtilization: data.primeTimeUtilization,
        nonPrimeTimePercentage: data.nonPrimeTimePercentage,
        totalCases: data.totalCases || 0,
        casesInPrimeTime: data.casesInPrimeTime || 0,
        casesInNonPrimeTime: data.casesInNonPrimeTime || 0
      }));
  }, [selectedHospital, selectedLocation, selectedSpecialty, dateRange]);
  
  // Format specialty distribution data for pie chart
  const specialtyDistribution = useMemo(() => {
    const specialtyCounts = {};
    
    providerData.forEach(provider => {
      if (!specialtyCounts[provider.service]) {
        specialtyCounts[provider.service] = 0;
      }
      specialtyCounts[provider.service] += provider.totalCases;
    });
    
    return Object.entries(specialtyCounts).map(([specialty, count]) => ({
      id: specialty,
      label: specialty,
      value: count
    }));
  }, [providerData]);

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Panel title="Prime Time Utilization by Provider" isSubpanel dropLightIntensity="medium">
          <div className="h-96">
            <ResponsiveBar
              data={providerData.slice(0, 10)} // Limit to top 10 for readability
              keys={['primeTimeUtilization']}
              indexBy="id"
              margin={{ top: 20, right: 130, bottom: 70, left: 60 }}
              padding={0.3}
              colors={{ scheme: 'category10' }}
              axisBottom={{
                tickRotation: -45,
                legend: 'Provider',
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

        <Panel title="Non-Prime Time Percentage by Provider" isSubpanel dropLightIntensity="medium">
          <div className="h-96">
            <ResponsiveBar
              data={providerData.slice(0, 10)} // Limit to top 10 for readability
              keys={['nonPrimeTimePercentage']}
              indexBy="id"
              margin={{ top: 20, right: 130, bottom: 70, left: 60 }}
              padding={0.3}
              colors={{ scheme: 'accent' }}
              axisBottom={{
                tickRotation: -45,
                legend: 'Provider',
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
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Panel title="Specialty Distribution" isSubpanel dropLightIntensity="medium">
          <div className="h-96">
            <ResponsivePie
              data={specialtyDistribution}
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

        <Panel title="Cases in Non-Prime Time by Provider" isSubpanel dropLightIntensity="medium">
          <div className="h-96">
            <ResponsiveBar
              data={providerData.slice(0, 10)} // Limit to top 10 for readability
              keys={['casesInNonPrimeTime']}
              indexBy="id"
              margin={{ top: 20, right: 130, bottom: 70, left: 60 }}
              padding={0.3}
              colors={{ scheme: 'red_blue' }}
              axisBottom={{
                tickRotation: -45,
                legend: 'Provider',
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

      <Panel title="Provider Analysis Table" isSubpanel dropLightIntensity="medium">
        <div className="overflow-x-auto">
          <table className="w-full text-sm text-left text-gray-500 dark:text-gray-400">
            <thead className="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
              <tr>
                <th className="p-2 border">Provider</th>
                <th className="p-2 border">Specialty</th>
                <th className="p-2 border text-center">Prime Time Utilization</th>
                <th className="p-2 border text-center">Non-Prime Time %</th>
                <th className="p-2 border text-center">Total Cases</th>
                <th className="p-2 border text-center">Cases in Non-Prime Time</th>
              </tr>
            </thead>
            <tbody>
              {providerData.map(provider => (
                <tr key={provider.id} className="bg-white border-b dark:bg-gray-800 dark:border-gray-700">
                  <td className="p-2 border">{provider.id}</td>
                  <td className="p-2 border">{provider.service}</td>
                  <td className="p-2 border text-center">{provider.primeTimeUtilization.toFixed(1)}%</td>
                  <td className="p-2 border text-center">{provider.nonPrimeTimePercentage.toFixed(1)}%</td>
                  <td className="p-2 border text-center">{provider.totalCases}</td>
                  <td className="p-2 border text-center">{provider.casesInNonPrimeTime}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  );
};

export default ProviderAnalysisView;
