import React, { useEffect } from 'react';
import { usePage } from '@inertiajs/react';

const AppWrapper = ({ children }) => {
  const { url } = usePage();

  useEffect(() => {
    // Apply dark mode class on URL change
    const savedTheme = localStorage.getItem('darkMode');
    if (savedTheme === 'false') {
      document.documentElement.classList.remove('dark');
    } else {
      document.documentElement.classList.add('dark');
    }
  }, [url]);

  return children;
};

export default AppWrapper;
