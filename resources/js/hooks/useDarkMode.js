import { useState, useEffect } from 'react';

export const HEALTHCARE_COLORS = {
  primary: '#0077B6',
  secondary: '#48CAE4',
  tertiary: '#90E0EF',
  quaternary: '#CAF0F8',
  success: '#2E7D32',
  warning: '#ED6C02',
  error: '#D32F2F',
  info: '#0288D1',
  dark: {
    background: '#1E293B',
    surface: '#334155',
    text: '#F8FAFC',
    border: '#475569'
  },
  light: {
    background: '#F8FAFC',
    surface: '#FFFFFF',
    text: '#1E293B',
    border: '#E2E8F0'
  }
};

export const useDarkMode = () => {
  const [isDarkMode, setIsDarkMode] = useState(() => {
    const savedMode = localStorage.getItem('darkMode');
    // If no preference is stored, use dark mode by default
    // or check system preferences
    if (savedMode === null) {
      const prefersDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
      return prefersDarkMode || true; // Default to dark mode even if system doesn't prefer it
    }
    return savedMode === 'true' || JSON.parse(savedMode);
  });

  useEffect(() => {
    localStorage.setItem('darkMode', JSON.stringify(isDarkMode));
    if (isDarkMode) {
      document.documentElement.classList.add('dark');
    } else {
      document.documentElement.classList.remove('dark');
    }
  }, [isDarkMode]);

  return [isDarkMode, setIsDarkMode];
};

export default useDarkMode;
