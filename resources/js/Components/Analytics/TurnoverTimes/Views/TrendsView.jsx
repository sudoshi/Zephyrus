import React, { useMemo } from 'react';
import PropTypes from 'prop-types';
import { ResponsiveLine } from '@nivo/line';
import Panel from '@/Components/ui/Panel';
import  { useDarkMode } from '@/hooks/useDarkMode';
import { formatDurationMinutes } from '@/lib/duration';
import { resolveSiteData } from '../resolveSiteData';

const TrendsView = ({ filters, data = null }) => {
  // Extract filter values
  const { selectedHospital, selectedLocation, selectedSpecialty, dateRange } = filters;
  const [isDarkMode] = useDarkMode();
  const mockTurnoverTimes = data;

  // Get location data based on filters
  const locationData = useMemo(
    () => resolveSiteData(mockTurnoverTimes?.sites, { selectedLocation, selectedHospital }),
    [mockTurnoverTimes, selectedHospital, selectedLocation]
  );

  // Format monthly trend data for line chart
  const monthlyTrendData = useMemo(() => {
    return [
      {
        id: 'Median Turnover Time',
        data: (locationData?.trends?.medianTurnoverTime ?? []).map(item => ({
          x: item.month,
          y: item.value
        }))
      },
      {
        id: 'Average Turnover Time',
        data: (locationData?.trends?.averageTurnoverTime ?? []).map(item => ({
          x: item.month,
          y: item.value
        }))
      }
    ];
  }, [locationData]);

  // Format day of week data
  const dayOfWeekData = useMemo(() => {
    return [
      {
        id: 'Median Turnover Time',
        data: (locationData?.dayOfWeek ?? []).map(item => ({
          x: item.day,
          y: item.medianTurnoverTime
        }))
      },
      {
        id: 'Average Turnover Time',
        data: (locationData?.dayOfWeek ?? []).map(item => ({
          x: item.day,
          y: item.averageTurnoverTime
        }))
      }
    ];
  }, [locationData]);

  // Format year-over-year comparison data
  const yearOverYearData = useMemo(() => {
    // This would typically come from the API with real data
    // For mock purposes, we'll create a synthetic dataset
    const currentYearData = (locationData?.trends?.medianTurnoverTime ?? []).map(item => ({
      x: item.month,
      y: item.value
    }));

    const previousYearData = (locationData?.trends?.medianTurnoverTime ?? []).map(item => ({
      x: item.month,
      y: item.value * (1 + (Math.random() * 0.2 - 0.1)) // +/- 10% variation
    }));
    
    return [
      {
        id: 'Current Year',
        data: currentYearData
      },
      {
        id: 'Previous Year',
        data: previousYearData
      }
    ];
  }, [locationData]);

  if (!locationData) {
    return (
      <div className="p-8 text-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        No turnover trend data is available for the selected location and period.
      </div>
    );
  }

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
    crosshair: {
      line: {
        stroke: isDarkMode ? '#e5e7eb' : '#374151'
      }
    }
  };

  return (
    <div className="space-y-6">
      {/* Monthly Trends */}
      <Panel title="Monthly Trends - Turnover Times" isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveLine
            data={monthlyTrendData}
            margin={{ top: 20, right: 110, bottom: 50, left: 125 }}
            xScale={{ type: 'point' }}
            yScale={{ 
              type: 'linear', 
              min: 'auto', 
              max: 'auto', 
              stacked: false, 
              reverse: false 
            }}
            yFormat={formatDurationMinutes}
            axisTop={null}
            axisRight={null}
            axisBottom={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: 'Month',
              legendOffset: 36,
              legendPosition: 'middle'
            }}
            axisLeft={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: 'Duration',
              legendOffset: -110,
              format: formatDurationMinutes,
              legendPosition: 'middle'
            }}
            pointSize={10}
            pointColor={{ theme: 'background' }}
            pointBorderWidth={2}
            pointBorderColor={{ from: 'serieColor' }}
            pointLabelYOffset={-12}
            useMesh={true}
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
                symbolBorderColor: 'rgba(0, 0, 0, .5)',
                effects: [
                  {
                    on: 'hover',
                    style: {
                      itemBackground: 'rgba(0, 0, 0, .03)',
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
      
      {/* Day of Week Analysis */}
      <Panel title="Day of Week Analysis - Turnover Times" isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveLine
            data={dayOfWeekData}
            margin={{ top: 20, right: 110, bottom: 50, left: 125 }}
            xScale={{ type: 'point' }}
            yScale={{ 
              type: 'linear', 
              min: 'auto', 
              max: 'auto', 
              stacked: false, 
              reverse: false 
            }}
            yFormat={formatDurationMinutes}
            axisTop={null}
            axisRight={null}
            axisBottom={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: 'Day of Week',
              legendOffset: 36,
              legendPosition: 'middle'
            }}
            axisLeft={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: 'Duration',
              legendOffset: -110,
              format: formatDurationMinutes,
              legendPosition: 'middle'
            }}
            pointSize={10}
            pointColor={{ theme: 'background' }}
            pointBorderWidth={2}
            pointBorderColor={{ from: 'serieColor' }}
            pointLabelYOffset={-12}
            useMesh={true}
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
                symbolBorderColor: 'rgba(0, 0, 0, .5)',
                effects: [
                  {
                    on: 'hover',
                    style: {
                      itemBackground: 'rgba(0, 0, 0, .03)',
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
      
      {/* Year-over-Year Comparison */}
      <Panel title="Year-over-Year Comparison - Median Turnover Time" isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveLine
            data={yearOverYearData}
            margin={{ top: 20, right: 110, bottom: 50, left: 125 }}
            xScale={{ type: 'point' }}
            yScale={{ 
              type: 'linear', 
              min: 'auto', 
              max: 'auto', 
              stacked: false, 
              reverse: false 
            }}
            yFormat={formatDurationMinutes}
            axisTop={null}
            axisRight={null}
            axisBottom={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: 'Month',
              legendOffset: 36,
              legendPosition: 'middle'
            }}
            axisLeft={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: 'Duration',
              legendOffset: -110,
              format: formatDurationMinutes,
              legendPosition: 'middle'
            }}
            pointSize={10}
            pointColor={{ theme: 'background' }}
            pointBorderWidth={2}
            pointBorderColor={{ from: 'serieColor' }}
            pointLabelYOffset={-12}
            useMesh={true}
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
                symbolBorderColor: 'rgba(0, 0, 0, .5)',
                effects: [
                  {
                    on: 'hover',
                    style: {
                      itemBackground: 'rgba(0, 0, 0, .03)',
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
    </div>
  );
};

TrendsView.propTypes = {
  data: PropTypes.object,
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

export default TrendsView;
