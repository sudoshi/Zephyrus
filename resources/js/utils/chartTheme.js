/**
 * Shared chart theme configuration for Perioperative Dashboard components
 * Ensures consistent styling across all charts and graphs
 */

export const getChartTheme = (isDarkMode = true) => {
  // Always use white text for dark backgrounds in the dashboard
  return {
    background: isDarkMode ? '#1f2937' : 'transparent',
    textColor: '#ffffff',
    fontSize: 12,
    axis: {
      ticks: {
        text: {
          fill: '#ffffff',
          fontSize: 12,
          fontWeight: 'bold'
        },
        line: {
          stroke: 'rgba(255, 255, 255, 0.7)',
          strokeWidth: 1
        }
      },
      legend: {
        text: {
          fill: '#ffffff',
          fontSize: 13,
          fontWeight: 'bold'
        }
      },
      domain: {
        line: {
          stroke: 'rgba(255, 255, 255, 0.7)',
          strokeWidth: 1
        }
      }
    },
    grid: {
      line: {
        stroke: 'rgba(255, 255, 255, 0.4)',
        strokeWidth: 1
      }
    },
    legends: {
      text: {
        fill: '#ffffff',
        fontSize: 12,
        fontWeight: 'bold'
      }
    },
    tooltip: {
      container: {
        background: isDarkMode ? '#1f2937' : '#374151',
        color: '#ffffff',
        fontSize: 12,
        borderRadius: 4,
        boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)'
      }
    },
    labels: {
      text: {
        fill: '#ffffff',
        fontSize: 12,
        fontWeight: 'bold'
      }
    },
    dots: {
      text: {
        fill: '#ffffff',
        fontSize: 12
      }
    },
    crosshair: {
      line: {
        stroke: 'rgba(255, 255, 255, 0.8)',
        strokeWidth: 1.5,
        strokeOpacity: 0.9
      }
    }
  };
};

export default getChartTheme;
