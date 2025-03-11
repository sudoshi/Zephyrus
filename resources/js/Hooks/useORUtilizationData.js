import { useState, useEffect, useCallback } from 'react';

// Hard-coded data directly in the hook
const hardCodedData = {
  // Locations data with Virtua Health hospitals
  locations: {
    'MARH OR': {
      id: 'marh-or',
      hospitalId: 'marh',
      hospitalName: 'Virtua Marlton Hospital',
      name: 'MARH OR',
      fullName: 'Marlton Operating Room',
      utilization: 73.8,
      primeTimeUtilization: 79.2,
      nonPrimeTimeUtilization: 47.5,
      totalCases: 1106,
      averageCaseDuration: 132,
      averageTurnoverTime: 34,
      casesPerDay: 5.2,
      rooms: [
        {
          id: 'MAOR01',
          name: 'VH MARH OR 01',
          room: 'VH MARH OR 01', // Added room property
          utilization: 82.4,
          primeTimeUtilization: 86.7,
          nonPrimeTimeUtilization: 52.3
        },
        {
          id: 'MAOR02',
          name: 'VH MARH OR 02',
          room: 'VH MARH OR 02', // Added room property
          utilization: 78.9,
          primeTimeUtilization: 83.5,
          nonPrimeTimeUtilization: 49.8
        }
      ]
    },
    'MEMH OR': {
      id: 'memh-or',
      hospitalId: 'memh',
      hospitalName: 'Virtua Mount Holly Hospital',
      name: 'MEMH OR',
      fullName: 'Mt. Holly Operating Room',
      utilization: 75.2,
      primeTimeUtilization: 81.4,
      nonPrimeTimeUtilization: 48.9,
      totalCases: 1245,
      averageCaseDuration: 130,
      averageTurnoverTime: 31,
      casesPerDay: 5.4,
      rooms: [
        {
          id: 'MEOR01',
          name: 'VH MEMH OR 01',
          room: 'VH MEMH OR 01', // Added room property
          utilization: 83.7,
          primeTimeUtilization: 88.2,
          nonPrimeTimeUtilization: 53.8
        },
        {
          id: 'MEOR02',
          name: 'VH MEMH OR 02',
          room: 'VH MEMH OR 02', // Added room property
          utilization: 80.1,
          primeTimeUtilization: 85.3,
          nonPrimeTimeUtilization: 51.2
        }
      ]
    },
    'VORH OR': {
      id: 'vorh-or',
      hospitalId: 'vorh',
      hospitalName: 'Virtua Voorhees Hospital',
      name: 'VORH OR',
      fullName: 'Voorhees Operating Room',
      utilization: 70.6,
      primeTimeUtilization: 76.8,
      nonPrimeTimeUtilization: 43.2,
      totalCases: 956,
      averageCaseDuration: 125,
      averageTurnoverTime: 30,
      casesPerDay: 5.0,
      rooms: [
        {
          id: 'VOR01',
          name: 'VH VORH OR 01',
          room: 'VH VORH OR 01', // Added room property
          utilization: 78.3,
          primeTimeUtilization: 83.5,
          nonPrimeTimeUtilization: 48.7
        },
        {
          id: 'VOR02',
          name: 'VH VORH OR 02',
          room: 'VH VORH OR 02', // Added room property
          utilization: 74.9,
          primeTimeUtilization: 80.2,
          nonPrimeTimeUtilization: 46.3
        }
      ]
    },
    'OLLH OR': {
      id: 'ollh-or',
      hospitalId: 'ollh',
      hospitalName: 'Virtua Our Lady of Lourdes Hospital',
      name: 'OLLH OR',
      fullName: 'Our Lady of Lourdes Operating Room',
      utilization: 77.3,
      primeTimeUtilization: 83.1,
      nonPrimeTimeUtilization: 49.5,
      totalCases: 1320,
      averageCaseDuration: 138,
      averageTurnoverTime: 32,
      casesPerDay: 5.7,
      rooms: [
        {
          id: 'OLOR01',
          name: 'VH OLLH OR 01',
          room: 'VH OLLH OR 01', // Added room property
          utilization: 85.2,
          primeTimeUtilization: 89.7,
          nonPrimeTimeUtilization: 54.8
        },
        {
          id: 'OLOR02',
          name: 'VH OLLH OR 02',
          room: 'VH OLLH OR 02', // Added room property
          utilization: 82.4,
          primeTimeUtilization: 87.1,
          nonPrimeTimeUtilization: 52.6
        }
      ]
    }
  },

  // Specialty data with Virtua Health specialties
  specialties: {
    'Orthopaedic Surgery': {
      utilization: 79.8,
      primeTimeUtilization: 85.5,
      nonPrimeTimeUtilization: 51.7,
      totalCases: 378,
      averageCaseDuration: 145,
      averageTurnoverTime: 36
    },
    'Cardiac Surgery': {
      utilization: 82.3,
      primeTimeUtilization: 87.6,
      nonPrimeTimeUtilization: 53.4,
      totalCases: 245,
      averageCaseDuration: 210,
      averageTurnoverTime: 42
    },
    'General Surgery': {
      utilization: 75.1,
      primeTimeUtilization: 81.3,
      nonPrimeTimeUtilization: 47.2,
      totalCases: 356,
      averageCaseDuration: 132,
      averageTurnoverTime: 32
    },
    'Neurosurgery': {
      utilization: 81.2,
      primeTimeUtilization: 86.7,
      nonPrimeTimeUtilization: 52.8,
      totalCases: 198,
      averageCaseDuration: 225,
      averageTurnoverTime: 45
    }
  },

  // Provider data with Virtua Health providers
  providers: {
    'abraham-john-a': {
      id: 'abraham-john-a',
      name: 'ABRAHAM, JOHN A',
      group: 'Virtua Orthopaedics & Spine',
      title: 'MD',
      specialty: 'Orthopaedic Surgery'
    },
    'rodriguez-arthur-j': {
      id: 'rodriguez-arthur-j',
      name: 'RODRIGUEZ, ARTHUR J',
      group: 'Virtua Cardiovascular Surgery',
      title: 'MD',
      specialty: 'Cardiac Surgery'
    },
    'smith-robert-j': {
      id: 'smith-robert-j',
      name: 'SMITH, ROBERT J',
      group: 'Virtua Surgical Group',
      title: 'MD',
      specialty: 'General Surgery'
    },
    'rosenberg-william-s': {
      id: 'rosenberg-william-s',
      name: 'ROSENBERG, WILLIAM S',
      group: 'Virtua Brain & Spine Institute',
      title: 'MD',
      specialty: 'Neurosurgery'
    }
  },

  // Trend data
  trends: {
    'MARH OR': {
      utilization: [
        { month: 'Oct 2024', value: 72.8 },
        { month: 'Nov 2024', value: 73.5 },
        { month: 'Dec 2024', value: 75.1 }
      ]
    },
    'MEMH OR': {
      utilization: [
        { month: 'Oct 2024', value: 74.3 },
        { month: 'Nov 2024', value: 75.0 },
        { month: 'Dec 2024', value: 76.4 }
      ]
    },
    'VORH OR': {
      utilization: [
        { month: 'Oct 2024', value: 69.7 },
        { month: 'Nov 2024', value: 70.4 },
        { month: 'Dec 2024', value: 71.8 }
      ]
    },
    'OLLH OR': {
      utilization: [
        { month: 'Oct 2024', value: 76.5 },
        { month: 'Nov 2024', value: 77.2 },
        { month: 'Dec 2024', value: 78.3 }
      ]
    }
  },

  // Pre-calculated location metrics
  locationMetrics: {
    'MARH OR': {
      opportunity: {
        utilizationGap: 1.2,
        potentialAdditionalCases: 28,
        potentialRevenue: 140000,
        targetUtilization: 75.0
      },
      efficiency: {
        efficiencyRatio: 79.5,
        casesPerDay: 5.2,
        turnoverTime: 34,
        caseDuration: 132
      }
    },
    'MEMH OR': {
      opportunity: {
        utilizationGap: 0,
        potentialAdditionalCases: 0,
        potentialRevenue: 0,
        targetUtilization: 75.0
      },
      efficiency: {
        efficiencyRatio: 80.7,
        casesPerDay: 5.4,
        turnoverTime: 31,
        caseDuration: 130
      }
    },
    'VORH OR': {
      opportunity: {
        utilizationGap: 4.4,
        potentialAdditionalCases: 92,
        potentialRevenue: 460000,
        targetUtilization: 75.0
      },
      efficiency: {
        efficiencyRatio: 80.6,
        casesPerDay: 5.0,
        turnoverTime: 30,
        caseDuration: 125
      }
    },
    'OLLH OR': {
      opportunity: {
        utilizationGap: 0,
        potentialAdditionalCases: 0,
        potentialRevenue: 0,
        targetUtilization: 75.0
      },
      efficiency: {
        efficiencyRatio: 82.3,
        casesPerDay: 5.7,
        turnoverTime: 32,
        caseDuration: 138
      }
    }
  }
};

/**
 * Extremely simplified hook for OR utilization data
 * Uses hard-coded data with no external dependencies
 */
export const useORUtilizationData = (filters = {}, autoLoad = true) => {
  const [data, setData] = useState(null);
  const [isLoading, setIsLoading] = useState(autoLoad);
  const [error, setError] = useState(null);
  
  // Load data directly from hard-coded data
  const loadData = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    
    try {
      // No delay - immediate response
      const filteredData = applyFilters(hardCodedData, filters);
      setData(filteredData);
    } catch (err) {
      console.error('Error loading OR utilization data:', err);
      setError({
        message: 'Failed to load OR utilization data',
        timestamp: new Date().toISOString()
      });
    } finally {
      setIsLoading(false);
    }
  }, [filters]);
  
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
