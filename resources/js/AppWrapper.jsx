import React, { useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import { useDarkMode } from '@/hooks/useDarkMode';

const AppWrapper = ({ children }) => {
  const { url } = usePage();
  const [isDarkMode] = useDarkMode();

  useEffect(() => {
    // Apply dark mode class on URL change
    if (isDarkMode) {
      document.documentElement.classList.add('dark');
    } else {
      document.documentElement.classList.remove('dark');
    }
  }, [url, isDarkMode]);

  return children;
};

export default AppWrapper;
