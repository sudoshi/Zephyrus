import React from 'react';
import { ResponsiveBar } from '@nivo/bar';
import { useHealthcareColorSchemes } from '../NivoThemeProvider';

/**
 * BarChart component wrapper for Nivo ResponsiveBar
 * 
 * @param {Object} props - Component props
 * @param {Array} props.data - Chart data
 * @param {Array} props.keys - Keys to display in the chart
 * @param {string} [props.indexBy="name"] - Property to use as index
 * @param {Object} [props.margin] - Chart margins
 * @param {string} [props.colorScheme="primary"] - Color scheme to use (primary, success, warning, critical, mixed)
 * @param {string} [props.layout="vertical"] - Chart layout (vertical or horizontal)
 * @param {boolean} [props.enableGridX=true] - Whether to show X grid lines
 * @param {boolean} [props.enableGridY=true] - Whether to show Y grid lines
 * @param {boolean} [props.enableLabel=true] - Whether to show value labels
 * @param {string} [props.labelSkipWidth=0] - Skip label if bar width lower than value
 * @param {string} [props.labelSkipHeight=0] - Skip label if bar height lower than value
 * @param {string} [props.groupMode="stacked"] - Group mode (stacked or grouped)
 * @param {Object} [props.legends] - Chart legends configuration
 * @param {string} [props.className] - Additional CSS classes
 * @returns {React.ReactElement} BarChart component
 */
export function BarChart({
  data,
  keys,
  indexBy = "name",
  margin = { top: 50, right: 130, bottom: 50, left: 60 },
  colorScheme = "primary",
  layout = "vertical",
  enableGridX = true,
  enableGridY = true,
  enableLabel = true,
  labelSkipWidth = 0,
  labelSkipHeight = 0,
  groupMode = "stacked",
  legends,
  className = "",
  ...props
}) {
  // Default legends configuration
  const defaultLegends = [
    {
      dataFrom: 'keys',
      anchor: layout === 'vertical' ? 'bottom-right' : 'bottom',
      direction: layout === 'vertical' ? 'column' : 'row',
      justify: false,
      translateX: layout === 'vertical' ? 120 : 0,
      translateY: layout === 'vertical' ? 0 : 50,
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
  ];

  // Get the current color schemes based on theme
  const healthcareColorSchemes = useHealthcareColorSchemes();
  
  // Get colors from the selected scheme
  const colors = healthcareColorSchemes[colorScheme] || healthcareColorSchemes.primary;

  return (
    <div className={`h-80 w-full ${className}`}>
      <ResponsiveBar
        data={data}
        keys={keys}
        indexBy={indexBy}
        margin={margin}
        padding={0.3}
        layout={layout}
        valueScale={{ type: 'linear' }}
        indexScale={{ type: 'band', round: true }}
        colors={colors}
        colorBy="id"
        borderColor={{ from: 'color', modifiers: [['darker', 1.6]] }}
        axisTop={null}
        axisRight={null}
        axisBottom={{
          tickSize: 5,
          tickPadding: 5,
          tickRotation: layout === 'vertical' ? -45 : 0,
          legend: layout === 'vertical' ? indexBy : 'values',
          legendPosition: 'middle',
          legendOffset: 40,
          truncateTickAt: 0
        }}
        axisLeft={{
          tickSize: 5,
          tickPadding: 5,
          tickRotation: 0,
          legend: layout === 'vertical' ? 'values' : indexBy,
          legendPosition: 'middle',
          legendOffset: -40,
          truncateTickAt: 0
        }}
        enableGridX={enableGridX}
        enableGridY={enableGridY}
        enableLabel={enableLabel}
        labelSkipWidth={labelSkipWidth}
        labelSkipHeight={labelSkipHeight}
        labelTextColor={{ from: 'color', modifiers: [['darker', 1.6]] }}
        groupMode={groupMode}
        legends={legends || defaultLegends}
        role="application"
        ariaLabel="Bar chart"
        barAriaLabel={e => `${e.id}: ${e.formattedValue} in ${e.indexValue}`}
        {...props}
      />
    </div>
  );
}

/**
 * Example usage:
 * 
 * const data = [
 *   { name: 'Jan', value1: 111, value2: 157 },
 *   { name: 'Feb', value1: 157, value2: 129 },
 *   { name: 'Mar', value1: 129, value2: 150 },
 * ];
 * 
 * <BarChart 
 *   data={data} 
 *   keys={['value1', 'value2']} 
 *   colorScheme="mixed"
 *   groupMode="grouped"
 * />
 */
