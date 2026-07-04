import { useState, useEffect, useCallback } from 'react';

/**
 * OR utilization data hook — live server payload only (P5: the ~290-line
 * hardCodedData mock is gone). When the live payload carries no locations,
 * data stays null and the dashboard renders its honest no-data state.
 */
export const useORUtilizationData = (filters = {}, autoLoad = true, liveData = null) => {
  const [data, setData] = useState(null);
  const [isLoading, setIsLoading] = useState(autoLoad);
  const [error, setError] = useState(null);

  const loadData = useCallback(async () => {
    setIsLoading(true);
    setError(null);

    try {
      const source = liveData && liveData.locations && Object.keys(liveData.locations).length > 0
        ? liveData
        : null;
      setData(source ? applyFilters(source, filters) : null);
    } catch (err) {
      console.error('Error loading OR utilization data:', err);
      setError({
        message: 'Failed to load OR utilization data',
        timestamp: new Date().toISOString()
      });
    } finally {
      setIsLoading(false);
    }
  }, [filters, liveData]);
  
  // Load data on mount if autoLoad is true
  useEffect(() => {
    if (autoLoad) {
      loadData();
    }
  }, [autoLoad, loadData]);
  
  // Apply filters to the data
  const applyFilters = (mockData, filters) => {
    const { 
      selectedHospital,
      selectedLocation, 
      selectedSpecialty, 
      selectedSurgeon
    } = filters || {};
    
    // Create a copy of the data
    const result = { ...mockData };
    
    // Filter locations
    if (selectedHospital || selectedLocation) {
      const filteredLocations = {};
      
      Object.entries(mockData.locations || {}).forEach(([key, location]) => {
        let include = true;
        
        if (selectedHospital && location.hospitalId !== selectedHospital) {
          include = false;
        }
        
        if (selectedLocation && key !== selectedLocation) {
          include = false;
        }
        
        if (include) {
          filteredLocations[key] = location;
        }
      });
      
      result.locations = filteredLocations;
    }
    
    // Filter specialties
    if (selectedSpecialty) {
      const filteredSpecialties = {};
      
      if (mockData.specialties && mockData.specialties[selectedSpecialty]) {
        filteredSpecialties[selectedSpecialty] = mockData.specialties[selectedSpecialty];
      }
      
      result.specialties = filteredSpecialties;
    }
    
    // Filter providers
    if (selectedSurgeon) {
      const filteredProviders = {};
      
      if (mockData.providers && mockData.providers[selectedSurgeon]) {
        filteredProviders[selectedSurgeon] = mockData.providers[selectedSurgeon];
      }
      
      result.providers = filteredProviders;
    }
    
    return result;
  };
  
  // Get derived metrics for the selected location
  const getDerivedMetrics = () => {
    if (!data || !data.locations) return null;
    
    // Get selected location
    let selectedLocationId = null;
    
    if (filters.selectedLocation) {
      selectedLocationId = filters.selectedLocation;
    } else if (filters.selectedHospital) {
      // Find first location for the selected hospital
      selectedLocationId = Object.keys(data.locations).find(key => 
        data.locations[key].hospitalId === filters.selectedHospital
      );
    } else {
      // Default to first location
      selectedLocationId = Object.keys(data.locations)[0];
    }
    
    if (!selectedLocationId || !data.locationMetrics || !data.locationMetrics[selectedLocationId]) {
      return null;
    }
    
    // Return pre-calculated metrics
    return {
      efficiencyRatio: data.locationMetrics[selectedLocationId].efficiency.efficiencyRatio,
      opportunity: data.locationMetrics[selectedLocationId].opportunity
    };
  };
  
  return {
    data,
    isLoading,
    error,
    refresh: loadData,
    derivedMetrics: getDerivedMetrics(),
    hasData: !!data && Object.keys(data.locations || {}).length > 0
  };
};

// Default export removed to standardize on named exports only
