import React, { useContext } from 'react';
import { ThemeProvider } from '@nivo/core';
import { useDarkMode } from '@/Layouts/AuthenticatedLayout';

// Define a theme that matches our healthcare light theme
const nivoLightTheme = {
  background: 'transparent',
  textColor: '#1e293b', // healthcare-text-primary
  fontSize: 12,
  axis: {
    domain: {
      line: {
        stroke: '#e2e8f0', // healthcare-border
        strokeWidth: 1
      }
    },
    legend: {
      text: {
        fontSize: 12,
        fill: '#1e293b' // healthcare-text-primary
      }
    },
    ticks: {
      line: {
        stroke: '#e2e8f0', // healthcare-border
        strokeWidth: 1
      },
      text: {
        fontSize: 11,
        fill: '#475569', // healthcare-text-secondary
        fontWeight: 500
      }
    }
  },
  grid: {
    line: {
      stroke: '#e2e8f0', // healthcare-border
      strokeWidth: 1,
      strokeDasharray: '4 4'
    }
  },
  legends: {
    title: {
      text: {
        fontSize: 11,
        fill: '#1e293b', // healthcare-text-primary
        fontWeight: 600
      }
    },
    text: {
      fontSize: 11,
      fill: '#475569' // healthcare-text-secondary
    },
    ticks: {
      line: {
        stroke: '#e2e8f0', // healthcare-border
        strokeWidth: 1
      },
      text: {
        fontSize: 10,
        fill: '#475569' // healthcare-text-secondary
      }
    }
  },
  annotations: {
    text: {
      fontSize: 13,
      fill: '#1e293b', // healthcare-text-primary
      outlineWidth: 2,
      outlineColor: '#ffffff', // healthcare-surface
      outlineOpacity: 1
    },
    link: {
      stroke: '#e2e8f0', // healthcare-border
      strokeWidth: 1,
      outlineWidth: 2,
      outlineColor: '#ffffff', // healthcare-surface
      outlineOpacity: 1
    },
    outline: {
      stroke: '#e2e8f0', // healthcare-border
      strokeWidth: 2,
      outlineWidth: 2,
      outlineColor: '#ffffff', // healthcare-surface
      outlineOpacity: 1
    },
    symbol: {
      fill: '#2563eb', // healthcare-primary
      outlineWidth: 2,
      outlineColor: '#ffffff', // healthcare-surface
      outlineOpacity: 1
    }
  },
  tooltip: {
    container: {
      background: '#ffffff', // healthcare-surface
      color: '#1e293b', // healthcare-text-primary
      fontSize: 12,
      borderRadius: 4,
      boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)',
      padding: '8px 12px'
    },
    basic: {
      whiteSpace: 'pre',
      display: 'flex',
      alignItems: 'center'
    },
    table: {},
    tableCell: {
      padding: '3px 5px'
    },
    tableCellValue: {
      fontWeight: 'bold'
    }
  }
};

// Define a theme that matches our healthcare dark theme
const nivoDarkTheme = {
  background: 'transparent',
  textColor: '#ffffff', // White text for better readability
  fontSize: 12,
  axis: {
    domain: {
      line: {
        stroke: 'rgba(255, 255, 255, 0.2)', // Lighter border for dark mode
        strokeWidth: 1
      }
    },
    legend: {
      text: {
        fontSize: 12,
        fill: '#ffffff' // White text for better readability
      }
    },
    ticks: {
      line: {
        stroke: 'rgba(255, 255, 255, 0.2)', // Lighter border for dark mode
        strokeWidth: 1
      },
      text: {
        fontSize: 11,
        fill: '#ffffff', // White text for better readability
        fontWeight: 500
      }
    }
  },
  grid: {
    line: {
      stroke: 'rgba(255, 255, 255, 0.1)', // Subtle grid lines for dark mode
      strokeWidth: 1,
      strokeDasharray: '4 4'
    }
  },
  legends: {
    title: {
      text: {
        fontSize: 11,
        fill: '#ffffff', // White text for better readability
        fontWeight: 600
      }
    },
    text: {
      fontSize: 11,
      fill: '#ffffff', // White text for better readability
      fontWeight: 500
    }
  },
  labels: {
    text: {
      fontSize: 11,
      fill: '#ffffff', // White text for better readability
      fontWeight: 500
    }
  },
  tooltip: {
    container: {
      background: '#1e293b',
      color: '#ffffff',
      fontSize: 12,
      borderRadius: '4px',
      boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)',
      padding: '8px 12px'
    }
  },
  annotations: {
    text: {
      fontSize: 12,
      fill: '#ffffff', // White text for better readability
      outlineWidth: 2,
      outlineColor: '#1e293b',
      outlineOpacity: 1
    },
    link: {
      stroke: '#ffffff',
      strokeWidth: 1,
      outlineWidth: 2,
      outlineColor: '#1e293b',
      outlineOpacity: 1
    },
    outline: {
      stroke: '#ffffff',
      strokeWidth: 2,
      outlineWidth: 2,
      outlineColor: '#1e293b',
      outlineOpacity: 1
    },
    symbol: {
      fill: '#ffffff',
      outlineWidth: 2,
      outlineColor: '#1e293b',
      outlineOpacity: 1
    }
  },
  crosshair: {
    line: {
      stroke: '#ffffff',
      strokeWidth: 1,
      strokeOpacity: 0.5,
      strokeDasharray: '6 6'
    }
  }
};

// Define color schemes for light mode
export const healthcareColorSchemesLight = {
  // Primary blues
  primary: [
    '#2563eb', // healthcare-primary
    '#3b82f6',
    '#60a5fa',
    '#93c5fd',
    '#bfdbfe',
  ],
  // Success greens
  success: [
    '#059669', // healthcare-success
    '#10b981',
    '#34d399',
    '#6ee7b7',
    '#a7f3d0',
  ],
  // Warning oranges
  warning: [
    '#d97706', // healthcare-warning
    '#f59e0b',
    '#fbbf24',
    '#fcd34d',
    '#fde68a',
  ],
  // Critical reds
  critical: [
    '#dc2626', // healthcare-critical
    '#ef4444',
    '#f87171',
    '#fca5a5',
    '#fecaca',
  ],
  // Mixed palette
  mixed: [
    '#2563eb', // healthcare-primary (blue)
    '#059669', // healthcare-success (green)
    '#d97706', // healthcare-warning (orange)
    '#dc2626', // healthcare-critical (red)
    '#7c3aed', // healthcare-purple (purple)
    '#0d9488', // healthcare-teal (teal)
  ],
};

// Define color schemes for dark mode
export const healthcareColorSchemesDark = {
  // Primary blues
  primary: [
    '#3b82f6', // healthcare-primary-dark
    '#60a5fa',
    '#93c5fd',
    '#bfdbfe',
    '#dbeafe',
  ],
  // Success greens
  success: [
    '#10b981', // healthcare-success-dark
    '#34d399',
    '#6ee7b7',
    '#a7f3d0',
    '#d1fae5',
  ],
  // Warning oranges
  warning: [
    '#f59e0b', // healthcare-warning-dark
    '#fbbf24',
    '#fcd34d',
    '#fde68a',
    '#fef3c7',
  ],
  // Critical reds
  critical: [
    '#ef4444', // healthcare-critical-dark
    '#f87171',
    '#fca5a5',
    '#fecaca',
    '#fee2e2',
  ],
  // Mixed palette
  mixed: [
    '#3b82f6', // healthcare-primary-dark (blue)
    '#10b981', // healthcare-success-dark (green)
    '#f59e0b', // healthcare-warning-dark (orange)
    '#ef4444', // healthcare-critical-dark (red)
    '#8b5cf6', // healthcare-purple-dark (purple)
    '#14b8a6', // healthcare-teal-dark (teal)
  ],
};

export function NivoThemeProvider({ children, isDarkMode: propIsDarkMode }) {
  // Try to get dark mode state from context, fallback to prop
  const darkModeContext = useDarkMode();
  const isDarkMode = propIsDarkMode !== undefined ? propIsDarkMode : darkModeContext.isDarkMode;
  
  // Select the appropriate theme based on dark mode state
  const theme = isDarkMode ? nivoDarkTheme : nivoLightTheme;
  
  // Export the appropriate color schemes
  const healthcareColorSchemes = isDarkMode ? healthcareColorSchemesDark : healthcareColorSchemesLight;
  
  return (
    <ThemeProvider theme={theme}>
      {children}
    </ThemeProvider>
  );
}

// Export a hook to get the current color schemes
export function useHealthcareColorSchemes() {
  const { isDarkMode } = useDarkMode();
  return isDarkMode ? healthcareColorSchemesDark : healthcareColorSchemesLight;
}
