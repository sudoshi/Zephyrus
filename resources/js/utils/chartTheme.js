/**
 * Shared chart theme configuration for Perioperative Dashboard components
 * Ensures consistent styling across all charts and graphs
 */

export const getChartTheme = (isDarkMode = true) => {
  // Always use white text for dark backgrounds in the dashboard
  return {
    axis: {
      ticks: {
        text: {
          fill: '#ffffff'
        },
        line: {
          stroke: 'rgba(255, 255, 255, 0.2)'
        }
      },
      legend: {
        text: {
          fill: '#ffffff'
        }
      },
      domain: {
        line: {
          stroke: 'rgba(255, 255, 255, 0.2)'
        }
      }
    },
    grid: {
      line: {
        stroke: 'rgba(255, 255, 255, 0.1)'
      }
    },
    legends: {
      text: {
        fill: '#ffffff'
      }
    },
    tooltip: {
      container: {
        background: '#1f2937',
        color: '#ffffff'
      }
    },
    labels: {
      text: {
        fill: '#ffffff'
      }
    },
    dots: {
      text: {
        fill: '#ffffff'
      }
    },
    annotations: {
      text: {
        fill: '#ffffff'
      }
    }
  };
};

export default getChartTheme;
