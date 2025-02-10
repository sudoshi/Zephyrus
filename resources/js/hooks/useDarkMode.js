import { useState, useEffect } from 'react';

const COLOR_SCHEME_QUERY = '(prefers-color-scheme: dark)';

export function useDarkMode() {
  // Initialize from localStorage or system preference
  const [isDarkMode, setIsDarkMode] = useState(() => {
    const savedTheme = localStorage.getItem('darkMode');
    if (savedTheme !== null) {
      return savedTheme === 'true';
    }
    return window.matchMedia(COLOR_SCHEME_QUERY).matches;
  });

  useEffect(() => {
    const mediaQuery = window.matchMedia(COLOR_SCHEME_QUERY);
    const handleChange = (e) => {
      const savedTheme = localStorage.getItem('darkMode');
      // Only update if user hasn't set a preference
      if (savedTheme === null) {
        setIsDarkMode(e.matches);
      }
    };

    // Listen for system theme changes
    mediaQuery.addEventListener('change', handleChange);

    // Update document class and localStorage
    if (isDarkMode) {
      document.documentElement.classList.add('dark');
    } else {
      document.documentElement.classList.remove('dark');
    }
    localStorage.setItem('darkMode', isDarkMode);

    return () => mediaQuery.removeEventListener('change', handleChange);
  }, [isDarkMode]);

  // Wrapper function to update theme
  const toggleDarkMode = () => {
    setIsDarkMode(prev => !prev);
  };

  return [isDarkMode, toggleDarkMode];
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
