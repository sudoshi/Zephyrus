import React, { useEffect, useLayoutEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { useDarkMode } from '@/hooks/useDarkMode';

const AppWrapper = ({ children }) => {
  const { url } = usePage();
  const [isDarkMode] = useDarkMode();
  const initialized = useRef(false);

  // Initial setup - runs once and ensures dark mode is set before first paint
  useLayoutEffect(() => {
    if (!initialized.current) {
      document.documentElement.classList.add('dark');
      initialized.current = true;
    }
  }, []);

  // Handle dark mode changes and URL navigation
  useLayoutEffect(() => {
    if (isDarkMode) {
      document.documentElement.classList.add('dark');
    } else {
      document.documentElement.classList.remove('dark');
    }
  }, [url, isDarkMode]);

  return children;
};

export default AppWrapper;
