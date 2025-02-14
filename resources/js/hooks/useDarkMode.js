import { useState, useLayoutEffect } from 'react';

export function useDarkMode() {
  // Initialize state from localStorage, defaulting to true (dark mode)
  const [isDarkMode, setIsDarkMode] = useState(() => {
    return true; // Force dark mode to be true by default
  });

  // Use useLayoutEffect to update DOM synchronously before browser paint
  useLayoutEffect(() => {
    if (isDarkMode) {
      document.documentElement.classList.add('dark');
    } else {
      document.documentElement.classList.remove('dark');
    }
    localStorage.setItem('darkMode', isDarkMode.toString());
  }, [isDarkMode]);

  // Return theme state and setter function
  return [isDarkMode, setIsDarkMode];
}

// Healthcare-specific color constants
export const HEALTHCARE_COLORS = {
  light: {
    critical: '#DC2626', // red-600
    warning: '#D97706', // amber-600
    success: '#059669', // emerald-600
    info: '#2563EB', // blue-600
    background: '#F9FAFB', // gray-50
    surface: '#FFFFFF',
    text: {
      primary: '#111827', // gray-900
      secondary: '#4B5563', // gray-600
    },
    border: '#E5E7EB', // gray-200
  },
  dark: {
    critical: '#EF4444', // red-500
    warning: '#F59E0B', // amber-500
    success: '#10B981', // emerald-500
    info: '#3B82F6', // blue-500
    background: '#111827', // gray-900
    surface: '#1F2937', // gray-800
    text: {
      primary: '#FFFFFF', // white
      secondary: '#E5E7EB', // gray-200
    },
    border: '#374151', // gray-700
  },
};
