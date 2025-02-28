import React, { useMemo } from 'react';
import PropTypes from 'prop-types';
import { mockTurnoverTimes } from '@/mock-data/turnover-times';
import { ResponsiveBar } from '@nivo/bar';
import { ResponsivePie } from '@nivo/pie';
import Panel from '@/Components/ui/Panel';
import { useDarkMode } from '@/hooks/useDarkMode';

const ServiceAnalysisView = ({ filters }) => {
  // Extract filter values
  const { selectedHospital, selectedLocation, selectedSpecialty, dateRange } = filters;
  const [isDarkMode] = useDarkMode();
  
  // Format service comparison data
  const serviceComparisonData = useMemo(() => {
    return Object.entries(mockTurnoverTimes.services).map(([service, data]) => ({
      service,
      'Median Turnover': data.medianTurnoverTime,
      'Average Turnover': data.averageTurnoverTime
    }));
  }, []);
  
  // Format service distribution data
  const serviceDistributionData = useMemo(() => {
    return Object.entries(mockTurnoverTimes.services).map(([service, data]) => ({
      id: service,
      label: service,
      value: data.totalTurnovers
    }));
  }, []);
  
  // Format service efficiency data
  const serviceEfficiencyData = useMemo(() => {
    return Object.entries(mockTurnoverTimes.services).map(([service, data]) => {
      // Efficiency score is a synthetic metric for this example
      const efficiencyScore = 100 - (data.medianTurnoverTime / 60) * 100;
      
      return {
        service,
        'Efficiency Score': Math.min(Math.max(efficiencyScore, 0), 100).toFixed(1)
      };
    });
  }, []);
  
  // Chart theme based on dark mode
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
    },
    labels: {
      text: {
        fill: isDarkMode ? '#e5e7eb' : '#374151'
      }
    }
  };

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Service Distribution */}
        <Panel title="Service Distribution - Turnovers" isSubpanel dropLightIntensity="medium">
          <div className="h-80">
            <ResponsivePie
              data={serviceDistributionData}
              margin={{ top: 40, right: 80, bottom: 80, left: 80 }}
              innerRadius={0.5}
              padAngle={0.7}
              cornerRadius={3}
              activeOuterRadiusOffset={8}
              borderWidth={1}
              borderColor={{ from: 'color', modifiers: [['darker', 0.2]] }}
              arcLinkLabelsSkipAngle={10}
              arcLinkLabelsTextColor={isDarkMode ? '#e5e7eb' : '#374151'}
              arcLinkLabelsThickness={2}
              arcLinkLabelsColor={{ from: 'color' }}
              arcLabelsSkipAngle={10}
              arcLabelsTextColor={{ from: 'color', modifiers: [['darker', 2]] }}
              colors={{ scheme: 'category10' }}
              theme={theme}
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
                  itemTextColor: isDarkMode ? '#e5e7eb' : '#374151',
                  itemDirection: 'left-to-right',
                  itemOpacity: 1,
                  symbolSize: 18,
                  symbolShape: 'circle',
                  effects: [
                    {
                      on: 'hover',
                      style: {
                        itemTextColor: isDarkMode ? '#ffffff' : '#000000'
                      }
                    }
                  ]
                }
              ]}
            />
          </div>
        </Panel>
        
        {/* Service Comparison - Efficiency Score */}
        <Panel title="Service Comparison - Efficiency Score" isSubpanel dropLightIntensity="medium">
          <div className="h-80">
            <ResponsiveBar
              data={serviceEfficiencyData}
              keys={['Efficiency Score']}
              indexBy="service"
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
                legend: 'Service',
                legendPosition: 'middle',
                legendOffset: 40
              }}
              axisLeft={{
                tickSize: 5,
                tickPadding: 5,
                tickRotation: 0,
                legend: 'Efficiency Score (%)',
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
      </div>
      
      {/* Service Comparison - Turnover Times */}
      <Panel title="Service Comparison - Turnover Times" isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveBar
            data={serviceComparisonData}
            keys={['Median Turnover', 'Average Turnover']}
            indexBy="service"
            margin={{ top: 20, right: 130, bottom: 50, left: 60 }}
            padding={0.3}
            groupMode="grouped"
            valueScale={{ type: 'linear' }}
            indexScale={{ type: 'band', round: true }}
            colors={{ scheme: 'paired' }}
            borderColor={{ from: 'color', modifiers: [['darker', 1.6]] }}
            axisTop={null}
            axisRight={null}
            axisBottom={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: -45,
              legend: 'Service',
              legendPosition: 'middle',
              legendOffset: 40
            }}
            axisLeft={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: 'Time (minutes)',
              legendPosition: 'middle',
              legendOffset: -40
            }}
            labelSkipWidth={12}
            labelSkipHeight={12}
            labelTextColor={{ from: 'color', modifiers: [['darker', 1.6]] }}
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
                symbolSize: 20,
                effects: [
                  {
                    on: 'hover',
                    style: {
                      itemOpacity: 1
                    }
                  }
                ]
              }
            ]}
            theme={theme}
          />
        </div>
      </Panel>
      
      {/* Service Details Table */}
      <Panel title="Service Details" isSubpanel dropLightIntensity="medium">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead className="bg-gray-50 dark:bg-gray-800">
              <tr>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Service
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Median Turnover (min)
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Average Turnover (min)
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Total Cases
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Total Turnovers
                </th>
              </tr>
            </thead>
            <tbody className="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
              {Object.entries(mockTurnoverTimes.services).map(([service, data], index) => (
                <tr key={service} className={index % 2 === 0 ? 'bg-white dark:bg-gray-900' : 'bg-gray-50 dark:bg-gray-800'}>
                  <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                    {service}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                    {data.medianTurnoverTime}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                    {data.averageTurnoverTime}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                    {data.totalCases}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                    {data.totalTurnovers}
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

ServiceAnalysisView.propTypes = {
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

export default ServiceAnalysisView;
