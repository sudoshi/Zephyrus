import React from 'react';
import { ResponsivePie } from '@nivo/pie';
import { useHealthcareColorSchemes } from '../NivoThemeProvider';

/**
 * PieChart component wrapper for Nivo ResponsivePie
 * 
 * @param {Object} props - Component props
 * @param {Array} props.data - Chart data in Nivo format
 * @param {Object} [props.margin] - Chart margins
 * @param {string} [props.colorScheme="mixed"] - Color scheme to use (primary, success, warning, critical, mixed)
 * @param {boolean} [props.enableArcLabels=true] - Whether to show arc labels
 * @param {boolean} [props.enableArcLinkLabels=true] - Whether to show arc link labels
 * @param {number} [props.innerRadius=0] - Inner radius (0 for pie, >0 for donut)
 * @param {number} [props.padAngle=0.7] - Padding between arcs
 * @param {number} [props.cornerRadius=3] - Corner radius of arcs
 * @param {boolean} [props.activeOuterRadiusOffset=8] - Offset of active arc
 * @param {Object} [props.legends] - Chart legends configuration
 * @param {string} [props.className] - Additional CSS classes
 * @returns {React.ReactElement} PieChart component
 */
export function PieChart({
  data,
  margin = { top: 40, right: 80, bottom: 80, left: 80 },
  colorScheme = "mixed",
  enableArcLabels = true,
  enableArcLinkLabels = true,
  innerRadius = 0,
  padAngle = 0.7,
  cornerRadius = 3,
  activeOuterRadiusOffset = 8,
  legends,
  className = "",
  ...props
}) {
  // Default legends configuration
  const defaultLegends = [
    {
      anchor: 'bottom',
      direction: 'row',
      justify: false,
      translateX: 0,
      translateY: 56,
      itemsSpacing: 0,
      itemWidth: 100,
      itemHeight: 18,
      itemTextColor: '#999',
      itemDirection: 'left-to-right',
      itemOpacity: 1,
      symbolSize: 18,
      symbolShape: 'circle',
      effects: [
        {
          on: 'hover',
          style: {
            itemTextColor: '#fff'
          }
        }
      ]
    }
  ];

  // Get the current color schemes based on theme
  const healthcareColorSchemes = useHealthcareColorSchemes();
  
  // Get colors from the selected scheme
  const colors = healthcareColorSchemes[colorScheme] || healthcareColorSchemes.mixed;

  return (
    <div className={`h-80 w-full ${className}`}>
      <ResponsivePie
        data={data}
        margin={margin}
        innerRadius={innerRadius}
        padAngle={padAngle}
        cornerRadius={cornerRadius}
        activeOuterRadiusOffset={activeOuterRadiusOffset}
        colors={colors}
        borderWidth={1}
        borderColor={{ from: 'color', modifiers: [['darker', 0.2]] }}
        arcLinkLabelsSkipAngle={10}
        arcLinkLabelsTextColor="#f8fafc"
        arcLinkLabelsThickness={2}
        arcLinkLabelsColor={{ from: 'color' }}
        arcLabelsSkipAngle={10}
        arcLabelsTextColor={{ from: 'color', modifiers: [['darker', 2]] }}
        enableArcLabels={enableArcLabels}
        enableArcLinkLabels={enableArcLinkLabels}
        legends={legends || defaultLegends}
        {...props}
      />
    </div>
  );
}

/**
 * Helper function to transform simple data into Nivo pie chart format
 * 
 * @param {Array} data - Array of objects with id/label and value
 * @param {string} idKey - Key for id/label values
 * @param {string} valueKey - Key for value
 * @returns {Array} Formatted data for Nivo pie chart
 */
PieChart.formatData = function formatData(data, idKey, valueKey) {
  return data.map(item => ({
    id: item[idKey],
    label: item[idKey],
    value: item[valueKey]
  }));
};

/**
 * Example usage:
 * 
 * const rawData = [
 *   { category: 'A', count: 111 },
 *   { category: 'B', count: 157 },
 *   { category: 'C', count: 129 },
 * ];
 * 
 * const formattedData = PieChart.formatData(rawData, 'category', 'count');
 * 
 * <PieChart 
 *   data={formattedData} 
 *   colorScheme="mixed"
 *   innerRadius={0.5} // Makes it a donut chart
 * />
 */
