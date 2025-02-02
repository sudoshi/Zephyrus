import { useState, useEffect } from 'react';

const COLOR_SCHEME_QUERY = '(prefers-color-scheme: dark)';

export function useDarkMode() {
  const [isDarkMode, setIsDarkMode] = useState(() => {
    // Check localStorage first
    const savedMode = localStorage.getItem('darkMode');
    if (savedMode !== null) {
      return savedMode === 'true';
    }
    // Fall back to system preference
    return window.matchMedia(COLOR_SCHEME_QUERY).matches;
  });

  useEffect(() => {
    const mediaQuery = window.matchMedia(COLOR_SCHEME_QUERY);
    const handleChange = (e) => {
      setIsDarkMode(e.matches);
    };

    mediaQuery.addEventListener('change', handleChange);
    return () => mediaQuery.removeEventListener('change', handleChange);
  }, []);

  useEffect(() => {
    // Update document class and localStorage
    if (isDarkMode) {
      document.documentElement.classList.add('dark');
    } else {
      document.documentElement.classList.remove('dark');
    }
    localStorage.setItem('darkMode', isDarkMode);
  }, [isDarkMode]);

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
    critical: '#EF4444', // red-500 (brighter for dark mode)
    warning: '#F59E0B', // amber-500
    success: '#10B981', // emerald-500
    info: '#3B82F6', // blue-500
    background: '#111827', // gray-900
    surface: '#1F2937', // gray-800
        text: {
          primary: '#FFFFFF', // white for better contrast
          secondary: '#E5E7EB', // gray-200 for improved readability
        },
    border: '#374151', // gray-700
  }
};
