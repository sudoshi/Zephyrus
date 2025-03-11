import React, { useMemo } from 'react';
import PropTypes from 'prop-types';
import { mockTurnoverTimes } from '@/mock-data/turnover-times';
import { ResponsiveBar } from '@nivo/bar';
import Panel from '@/Components/ui/Panel';
import { useDarkMode } from '@/hooks/useDarkMode';

const LocationComparisonView = ({ filters }) => {
  // Extract filter values
  const { selectedHospital, selectedLocation, selectedSpecialty, dateRange } = filters;
  const [isDarkMode] = useDarkMode();
  
  // Format location comparison data
  const locationComparisonData = useMemo(() => {
    // Get all locations
    const locations = Object.keys(mockTurnoverTimes.sites);
    
    // Filter by hospital if selected
    const filteredLocations = selectedHospital 
      ? locations.filter(loc => loc.startsWith(selectedHospital.toUpperCase()))
      : locations;
    
    // Create data for bar chart
    return filteredLocations.map(location => {
      const siteData = mockTurnoverTimes.sites[location];
      return {
        location,
        'Median Turnover': siteData.medianTurnoverTime,
        'Average Turnover': siteData.averageTurnoverTime
      };
    });
  }, [selectedHospital]);
  
  // Format location efficiency data
  const locationEfficiencyData = useMemo(() => {
    // Get all locations
    const locations = Object.keys(mockTurnoverTimes.sites);
    
    // Filter by hospital if selected
    const filteredLocations = selectedHospital 
      ? locations.filter(loc => loc.startsWith(selectedHospital.toUpperCase()))
      : locations;
    
    // Create data for bar chart with efficiency score
    // Efficiency score is a synthetic metric for this example
    return filteredLocations.map(location => {
      const siteData = mockTurnoverTimes.sites[location];
      const efficiencyScore = 100 - (siteData.medianTurnoverTime / 60) * 100;
      
      return {
        location,
        'Efficiency Score': Math.min(Math.max(efficiencyScore, 0), 100).toFixed(1)
      };
    });
  }, [selectedHospital]);
  
  // Format location case volume data
  const locationVolumeData = useMemo(() => {
    // Get all locations
    const locations = Object.keys(mockTurnoverTimes.sites);
    
    // Filter by hospital if selected
    const filteredLocations = selectedHospital 
      ? locations.filter(loc => loc.startsWith(selectedHospital.toUpperCase()))
      : locations;
    
    // Create data for bar chart
    return filteredLocations.map(location => {
      const siteData = mockTurnoverTimes.sites[location];
      return {
        location,
        'Total Cases': siteData.totalCases,
        'Total Turnovers': siteData.totalTurnovers
      };
    });
  }, [selectedHospital]);
  
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
    }
  };

  return (
    <div className="space-y-6">
      {/* Turnover Time Comparison */}
      <Panel title="Location Comparison - Turnover Times" isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveBar
            data={locationComparisonData}
            keys={['Median Turnover', 'Average Turnover']}
            indexBy="location"
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
              legend: 'Location',
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
      
      {/* Efficiency Score Comparison */}
      <Panel title="Location Comparison - Efficiency Score" isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveBar
            data={locationEfficiencyData}
            keys={['Efficiency Score']}
            indexBy="location"
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
              legend: 'Location',
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
      
      {/* Case Volume Comparison */}
      <Panel title="Location Comparison - Case Volume" isSubpanel dropLightIntensity="medium">
        <div className="h-80">
          <ResponsiveBar
            data={locationVolumeData}
            keys={['Total Cases', 'Total Turnovers']}
            indexBy="location"
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
              legend: 'Location',
              legendPosition: 'middle',
              legendOffset: 40
            }}
            axisLeft={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: 'Count',
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
    </div>
  );
};

LocationComparisonView.propTypes = {
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

export default LocationComparisonView;
