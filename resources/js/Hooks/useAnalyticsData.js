import { useState, useEffect } from 'react';

/**
 * Custom hook to fetch analytics data
 * 
 * @param {Function} fetchFunction - Async function that returns the data
 * @param {Array} deps - Dependencies array for useEffect
 * @returns {Object} - { data, isLoading, error }
 */
export const useAnalyticsData = (fetchFunction, deps = []) => {
  const [data, setData] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    const fetchData = async () => {
      setIsLoading(true);
      try {
        const result = await fetchFunction();
        setData(result);
        setError(null);
      } catch (err) {
        setError(err);
      } finally {
        setIsLoading(false);
      }
    };

    fetchData();
  }, deps);

  return { data, isLoading, error };
};

// Default export removed to standardize on named exports only
