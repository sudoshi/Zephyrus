import React from 'react';
import { ResponsiveLine } from '@nivo/line';
import { useHealthcareColorSchemes } from '../NivoThemeProvider';

/**
 * LineChart component wrapper for Nivo ResponsiveLine
 * 
 * @param {Object} props - Component props
 * @param {Array} props.data - Chart data in Nivo format
 * @param {Object} [props.margin] - Chart margins
 * @param {string} [props.colorScheme="primary"] - Color scheme to use (primary, success, warning, critical, mixed)
 * @param {boolean} [props.enableArea=false] - Whether to fill the area under the line
 * @param {boolean} [props.enablePoints=true] - Whether to show points
 * @param {boolean} [props.enableGridX=true] - Whether to show X grid lines
 * @param {boolean} [props.enableGridY=true] - Whether to show Y grid lines
 * @param {boolean} [props.useMesh=true] - Whether to use interactive mesh for tooltips
 * @param {boolean} [props.enableSlices="x"] - Slice mode (x, y, or false)
 * @param {Object} [props.legends] - Chart legends configuration
 * @param {string} [props.xScale] - X scale configuration
 * @param {string} [props.yScale] - Y scale configuration
 * @param {string} [props.curve="linear"] - Curve interpolation (linear, monotoneX, etc.)
 * @param {string} [props.className] - Additional CSS classes
 * @returns {React.ReactElement} LineChart component
 */
export function LineChart({
  data,
  margin = { top: 50, right: 110, bottom: 50, left: 60 },
  colorScheme = "primary",
  enableArea = false,
  enablePoints = true,
  enableGridX = true,
  enableGridY = true,
  useMesh = true,
  enableSlices = "x",
  legends,
  xScale = { type: 'point' },
  yScale = { type: 'linear', min: 'auto', max: 'auto', stacked: false, reverse: false },
  curve = "linear",
  className = "",
  ...props
}) {
  // Default legends configuration
  const defaultLegends = [
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
  ];

  // Get the current color schemes based on theme
  const healthcareColorSchemes = useHealthcareColorSchemes();
  
  // Get colors from the selected scheme
  const colors = healthcareColorSchemes[colorScheme] || healthcareColorSchemes.primary;

  return (
    <div className={`h-80 w-full ${className}`}>
      <ResponsiveLine
        data={data}
        margin={margin}
        xScale={xScale}
        yScale={yScale}
        curve={curve}
        axisTop={null}
        axisRight={null}
        axisBottom={{
          tickSize: 5,
          tickPadding: 5,
          tickRotation: 0,
          legend: 'x',
          legendOffset: 36,
          legendPosition: 'middle'
        }}
        axisLeft={{
          tickSize: 5,
          tickPadding: 5,
          tickRotation: 0,
          legend: 'y',
          legendOffset: -40,
          legendPosition: 'middle'
        }}
        colors={colors}
        enablePoints={enablePoints}
        pointSize={10}
        pointColor={{ theme: 'background' }}
        pointBorderWidth={2}
        pointBorderColor={{ from: 'serieColor' }}
        pointLabelYOffset={-12}
        enableArea={enableArea}
        areaOpacity={0.15}
        enableGridX={enableGridX}
        enableGridY={enableGridY}
        useMesh={useMesh}
        enableSlices={enableSlices}
        legends={legends || defaultLegends}
        {...props}
      />
    </div>
  );
}

/**
 * Helper function to transform simple data into Nivo line chart format
 * 
 * @param {Array} data - Array of objects with x and y values
 * @param {string} xKey - Key for x values
 * @param {Array|string} yKeys - Key(s) for y values
 * @param {Array|string} [seriesNames] - Optional names for series (defaults to yKeys)
 * @returns {Array} Formatted data for Nivo line chart
 */
LineChart.formatData = function formatData(data, xKey, yKeys, seriesNames = null) {
  // Handle single yKey as string
  const yKeysArray = Array.isArray(yKeys) ? yKeys : [yKeys];
  const seriesNamesArray = seriesNames ? (Array.isArray(seriesNames) ? seriesNames : [seriesNames]) : yKeysArray;
  
  // Check if data is undefined or null
  if (!data || !Array.isArray(data)) {
    return [];
  }
  
  return yKeysArray.map((yKey, index) => ({
    id: seriesNamesArray[index] || yKey,
    data: data.map(item => ({
      x: item[xKey],
      y: item[yKey]
    }))
  }));
};

/**
 * Example usage:
 * 
 * const rawData = [
 *   { month: 'Jan', value1: 111, value2: 157 },
 *   { month: 'Feb', value1: 157, value2: 129 },
 *   { month: 'Mar', value1: 129, value2: 150 },
 * ];
 * 
 * const formattedData = LineChart.formatData(
 *   rawData, 
 *   'month', 
 *   ['value1', 'value2'], 
 *   ['Series 1', 'Series 2']
 * );
 * 
 * <LineChart 
 *   data={formattedData} 
 *   colorScheme="mixed"
 *   enableArea={true}
 * />
 */
